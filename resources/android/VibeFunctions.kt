package com.nativephp.plugins.vibe

import android.content.Context
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.pusher.client.Pusher
import com.pusher.client.PusherOptions
import com.pusher.client.channel.Channel
import com.pusher.client.channel.PresenceChannelEventListener
import com.pusher.client.channel.PrivateChannel
import com.pusher.client.channel.PrivateChannelEventListener
import com.pusher.client.channel.PusherEvent
import com.pusher.client.channel.User
import com.pusher.client.connection.ConnectionEventListener
import com.pusher.client.connection.ConnectionState
import com.pusher.client.connection.ConnectionStateChange
import com.pusher.client.util.HttpChannelAuthorizer
import org.json.JSONArray
import org.json.JSONObject

/**
 * Holds the single app-wide Pusher-protocol connection (Vask / Reverb / Pusher)
 * and forwards every channel message into the PHP runloop as a native
 * (type-20) event via NativeElementBridge.sendNativeEvent — the Kotlin twin of
 * iOS's EchoClient.
 *
 * Broadcast events are emitted CHANNEL-SCOPED — `vibe:event:<channel>:<name>`
 * — so PHP listeners on one channel never fire for another channel that
 * happens to broadcast the same event name.
 *
 * Channels are REFCOUNTED app-wide: a channel closes only when its last
 * subscriber unsubscribes, and the socket tears down when no channels remain.
 * Private/presence channels authorize through a remote /broadcasting/auth
 * endpoint using an HttpChannelAuthorizer + bearer token; auth and connection
 * failures are forwarded to PHP as vibe:error events.
 */
private object VibeEchoClient {
    private const val TAG = "Vibe"

    private var pusher: Pusher? = null
    private val channels = mutableMapOf<String, Channel>()
    private val refCounts = mutableMapOf<String, Int>()

    // Auth config; authorizer kept so the bearer token can be refreshed live.
    private var authorizer: HttpChannelAuthorizer? = null
    private var authEndpoint: String? = null
    private var authToken: String? = null

    // Tracks a first connect vs a RECONNECT on THIS socket (worth telling PHP
    // to refetch). Reset on teardown — a fresh connection after a full
    // teardown is a first connect, not a reconnect.
    private var hasBeenConnected = false

    @Synchronized
    fun subscribe(
        key: String, host: String, port: Int, useTLS: Boolean, channelName: String,
        authEndpoint: String?, authToken: String?,
    ) {
        if (!authEndpoint.isNullOrEmpty()) this.authEndpoint = authEndpoint
        if (!authToken.isNullOrEmpty()) this.authToken = authToken

        connectIfNeeded(key, host, port, useTLS)
        val p = pusher ?: return

        refCounts[channelName]?.let { count ->
            refCounts[channelName] = count + 1
            return
        }
        refCounts[channelName] = 1

        // Presence: the listener delivers member info, but it is NOT bound to any
        // event names — so developer events need an explicit bindGlobal, same as
        // private/public. (On iOS these ride the connection-wide bind.)
        if (channelName.startsWith("presence-")) {
            val presence = p.subscribePresence(channelName, presenceListener(channelName))
            bindDeveloperEvents(presence, channelName)
            channels[channelName] = presence
            return
        }

        val channel: Channel = if (channelName.startsWith("private-")) {
            // Listener only used to surface auth failures; developer events
            // ride bindGlobal below.
            p.subscribePrivate(channelName, object : PrivateChannelEventListener {
                override fun onEvent(event: PusherEvent) {}

                override fun onSubscriptionSucceeded(channelName: String) {}

                override fun onAuthenticationFailure(message: String, e: Exception) {
                    emitError("auth_failed", channelName, message)
                }
            })
        } else {
            p.subscribe(channelName)
        }

        bindDeveloperEvents(channel, channelName)
        channels[channelName] = channel
    }

    /** Bind ALL events on the channel and forward developer-facing ones, channel-scoped. */
    private fun bindDeveloperEvents(channel: Channel, channelName: String) {
        channel.bindGlobal { event ->
            val name = event.eventName ?: return@bindGlobal
            if (name.startsWith("pusher:") || name.startsWith("pusher_internal:")) return@bindGlobal
            NativeElementBridge.sendNativeEvent("vibe:event:$channelName:$name", event.data ?: "{}")
        }
    }

    /** Presence listener: forwards member here/joining/leaving + auth failures. */
    private fun presenceListener(channel: String) = object : PresenceChannelEventListener {
        override fun onUsersInformationReceived(channelName: String, users: MutableSet<User>) {
            val members = JSONArray()
            users.forEach { members.put(memberJson(it)) }
            val payload = JSONObject().put("members", members)
            NativeElementBridge.sendNativeEvent("vibe:here:$channel", payload.toString())
        }

        override fun userSubscribed(channelName: String, user: User) {
            NativeElementBridge.sendNativeEvent("vibe:joining:$channel", memberJson(user).toString())
        }

        override fun userUnsubscribed(channelName: String, user: User) {
            NativeElementBridge.sendNativeEvent("vibe:leaving:$channel", memberJson(user).toString())
        }

        // Developer events are forwarded via bindGlobal in subscribe(); the
        // listener isn't bound to event names, so this only satisfies the interface.
        override fun onEvent(event: PusherEvent) {}

        override fun onSubscriptionSucceeded(channelName: String) {}

        override fun onAuthenticationFailure(message: String, e: Exception) {
            emitError("auth_failed", channel, message)
        }
    }

    /** { id, info } — info is the raw user_info JSON from the auth response. */
    private fun memberJson(user: User): JSONObject {
        val info = user.info?.let { runCatching { JSONObject(it) }.getOrNull() } ?: JSONObject()
        return JSONObject().put("id", user.id ?: "").put("info", info)
    }

    /**
     * Forward a native-side failure (channel auth rejection, connection error)
     * to PHP as a vibe:error event, so it is observable via ->onError()
     * instead of vanishing.
     */
    private fun emitError(type: String, channel: String?, message: String) {
        Log.e(TAG, "$type${channel?.let { " [$it]" } ?: ""}: $message")
        val payload = JSONObject()
            .put("type", type)
            .put("channel", channel ?: "")
            .put("message", message)
        NativeElementBridge.sendNativeEvent("vibe:error", payload.toString())
    }

    @Synchronized
    fun unsubscribe(channelName: String) {
        val count = refCounts[channelName] ?: return
        if (count > 1) {
            refCounts[channelName] = count - 1
            return
        }

        refCounts.remove(channelName)
        pusher?.unsubscribe(channelName)
        channels.remove(channelName)
        if (channels.isEmpty()) {
            pusher?.disconnect()
            pusher = null
            authorizer = null
            hasBeenConnected = false
        }
    }

    @Synchronized
    fun setAuthToken(token: String) {
        authToken = token
        authorizer?.setHeaders(authHeaders(token))
    }

    /** Send a client event (client-*) to the other subscribers — private/presence only. */
    @Synchronized
    fun trigger(channelName: String, event: String, dataJson: String) {
        val channel = channels[channelName]
        if (channel is PrivateChannel) {  // PresenceChannel extends PrivateChannel
            channel.trigger(event, dataJson)
        }
    }

    private fun authHeaders(token: String?): Map<String, String> {
        val headers = mutableMapOf("Accept" to "application/json")
        if (!token.isNullOrEmpty()) headers["Authorization"] = "Bearer $token"
        return headers
    }

    private fun connectIfNeeded(key: String, host: String, port: Int, useTLS: Boolean) {
        if (pusher != null) return

        val options = PusherOptions()
        options.setHost(host)
        options.setWsPort(port)
        options.setWssPort(port)
        options.setUseTLS(useTLS)

        // Configure private/presence channel auth when an endpoint is set.
        val endpoint = authEndpoint
        if (!endpoint.isNullOrEmpty()) {
            val auth = HttpChannelAuthorizer(endpoint)
            auth.setHeaders(authHeaders(authToken))
            options.setChannelAuthorizer(auth)
            authorizer = auth
        }

        val p = Pusher(key, options)
        // Connect and watch connection state: forward a RECONNECT (a return to
        // CONNECTED after we'd connected before) so PHP can refetch state —
        // websockets don't replay missed messages — and a DISCONNECT for
        // "reconnecting…" UI. Errors surface as vibe:error.
        p.connect(object : ConnectionEventListener {
            override fun onConnectionStateChange(change: ConnectionStateChange) {
                when (change.currentState) {
                    ConnectionState.CONNECTED -> {
                        if (hasBeenConnected) {
                            NativeElementBridge.sendNativeEvent("vibe:reconnected", "{}")
                        }
                        hasBeenConnected = true
                    }
                    ConnectionState.DISCONNECTED -> {
                        if (hasBeenConnected) {
                            NativeElementBridge.sendNativeEvent("vibe:disconnected", "{}")
                        }
                    }
                    else -> {}
                }
            }

            override fun onError(message: String?, code: String?, e: Exception?) {
                // The SDK's message is often just "An exception was thrown by
                // the websocket" — append the underlying cause so the error is
                // actionable.
                val detail = listOfNotNull(message, e?.message ?: e?.cause?.message)
                    .distinct()
                    .joinToString(" — ")
                    .ifEmpty { "unknown connection error" }
                emitError("connection_error", null, detail)
            }
        }, ConnectionState.ALL)
        pusher = p
    }
}

object VibeFunctions {

    /** Connect (if needed) and subscribe to a channel. */
    class Subscribe(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = parameters["key"] as? String ?: ""
            val host = parameters["host"] as? String ?: ""
            val port = (parameters["port"] as? Number)?.toInt() ?: 443
            val useTLS = parameters["tls"] as? Boolean ?: true
            val channel = parameters["channel"] as? String ?: ""
            val authEndpoint = parameters["authEndpoint"] as? String
            val authToken = parameters["authToken"] as? String

            if (key.isEmpty() || host.isEmpty() || channel.isEmpty()) {
                return BridgeResponse.error(
                    "vibe.missing_config",
                    "Vibe.Subscribe requires key, host and channel (check PUSHER_APP_KEY / PUSHER_HOST)"
                )
            }

            VibeEchoClient.subscribe(key, host, port, useTLS, channel, authEndpoint, authToken)
            return BridgeResponse.success(mapOf("subscribed" to channel))
        }
    }

    /** Unsubscribe from a channel. */
    class Unsubscribe(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val channel = parameters["channel"] as? String ?: ""
            VibeEchoClient.unsubscribe(channel)
            return BridgeResponse.success(mapOf("unsubscribed" to channel))
        }
    }

    /** Update the bearer token on the live connection (token refresh / re-login). */
    class SetAuthToken(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val token = parameters["token"] as? String ?: ""
            VibeEchoClient.setAuthToken(token)
            return BridgeResponse.success(mapOf("updated" to true))
        }
    }

    /** Send a client event (client-*) to the other subscribers of a channel. */
    class Trigger(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val channel = parameters["channel"] as? String ?: ""
            val event = parameters["event"] as? String ?: ""
            val data = parameters["data"] as? String ?: "{}"
            VibeEchoClient.trigger(channel, event, data)
            return BridgeResponse.success(mapOf("triggered" to event))
        }
    }
}
