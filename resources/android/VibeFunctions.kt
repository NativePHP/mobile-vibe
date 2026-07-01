package com.nativephp.plugins.vibe

import android.content.Context
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import com.pusher.client.Pusher
import com.pusher.client.PusherOptions
import com.pusher.client.channel.Channel
import com.pusher.client.channel.PresenceChannelEventListener
import com.pusher.client.channel.PrivateChannel
import com.pusher.client.channel.PusherEvent
import com.pusher.client.channel.User
import com.pusher.client.connection.ConnectionEventListener
import com.pusher.client.connection.ConnectionState
import com.pusher.client.connection.ConnectionStateChange
import com.pusher.client.util.HttpChannelAuthorizer

/**
 * Holds the single app-wide Pusher-protocol connection (Vask / Reverb / Pusher)
 * and forwards every channel message into the PHP runloop as a native
 * (type-20) event via NativeElementBridge.sendNativeEvent — the Kotlin twin of
 * iOS's EchoClient. Private/presence channels authorize through a remote
 * /broadcasting/auth endpoint using an HttpAuthorizer + bearer token.
 */
private object VibeEchoClient {
    private var pusher: Pusher? = null
    private val channels = mutableMapOf<String, Channel>()

    // Auth config; authorizer kept so the bearer token can be refreshed live.
    private var authorizer: HttpChannelAuthorizer? = null
    private var authEndpoint: String? = null
    private var authToken: String? = null

    // Tracks a first connect vs a RECONNECT (worth telling PHP to refetch).
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
        if (channels.containsKey(channelName)) return

        // Presence: the listener delivers member info, but it is NOT bound to any
        // event names — so developer events need an explicit bindGlobal, same as
        // private/public. (On iOS these ride the connection-wide bind.)
        if (channelName.startsWith("presence-")) {
            val presence = p.subscribePresence(channelName, presenceListener(channelName))
            presence.bindGlobal { event ->
                val name = event.eventName ?: return@bindGlobal
                if (name.startsWith("pusher:") || name.startsWith("pusher_internal:")) return@bindGlobal
                NativeElementBridge.sendNativeEvent(name, event.data ?: "{}")
            }
            channels[channelName] = presence
            return
        }

        val channel: Channel = if (channelName.startsWith("private-")) {
            p.subscribePrivate(channelName)
        } else {
            p.subscribe(channelName)
        }

        // Bind to ALL events on the channel and forward developer-facing ones.
        channel.bindGlobal { event ->
            val name = event.eventName ?: return@bindGlobal
            if (name.startsWith("pusher:") || name.startsWith("pusher_internal:")) return@bindGlobal
            NativeElementBridge.sendNativeEvent(name, event.data ?: "{}")
        }
        channels[channelName] = channel
    }

    /** Presence listener: forwards developer events + member here/joining/leaving. */
    private fun presenceListener(channel: String) = object : PresenceChannelEventListener {
        override fun onUsersInformationReceived(channelName: String, users: MutableSet<User>) {
            val members = users.joinToString(",") { memberJson(it) }
            NativeElementBridge.sendNativeEvent("vibe:here:$channel", """{"members":[$members]}""")
        }

        override fun userSubscribed(channelName: String, user: User) {
            NativeElementBridge.sendNativeEvent("vibe:joining:$channel", memberJson(user))
        }

        override fun userUnsubscribed(channelName: String, user: User) {
            NativeElementBridge.sendNativeEvent("vibe:leaving:$channel", memberJson(user))
        }

        // Developer events are forwarded via bindGlobal in subscribe(); the
        // listener isn't bound to event names, so this only satisfies the interface.
        override fun onEvent(event: PusherEvent) {}

        override fun onSubscriptionSucceeded(channelName: String) {}

        override fun onAuthenticationFailure(message: String, e: Exception) {}
    }

    /** { id, info } — info is the raw user_info JSON from the auth response. */
    private fun memberJson(user: User): String = """{"id":"${user.id}","info":${user.info ?: "{}"}}"""

    @Synchronized
    fun unsubscribe(channelName: String) {
        if (!channels.containsKey(channelName)) return
        pusher?.unsubscribe(channelName)
        channels.remove(channelName)
        if (channels.isEmpty()) {
            pusher?.disconnect()
            pusher = null
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
        // CONNECTED after we'd connected before) as a synthetic native event so
        // PHP can refetch state — websockets don't replay missed messages.
        p.connect(object : ConnectionEventListener {
            override fun onConnectionStateChange(change: ConnectionStateChange) {
                if (change.currentState == ConnectionState.CONNECTED) {
                    if (hasBeenConnected) {
                        NativeElementBridge.sendNativeEvent("vibe:reconnected", "{}")
                    }
                    hasBeenConnected = true
                }
            }

            override fun onError(message: String?, code: String?, e: Exception?) {}
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
                return BridgeResponse.success(mapOf(
                    "subscribed" to false,
                    "reason" to "missing key/host/channel"
                ))
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
