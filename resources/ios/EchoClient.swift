import Foundation
import PusherSwift

/// Holds the single app-wide Pusher-protocol connection (Vask / Reverb / Pusher)
/// and forwards every channel message into the PHP runloop as a native
/// (type-20) event via `NativeElementBridge.sendNativeEvent`. PHP's
/// `dispatchNativeEvent()` then routes it to `#[OnEcho]` / fluent `->on()`
/// listeners on the active NativeComponent and re-renders.
///
/// Broadcast events are emitted CHANNEL-SCOPED — `vibe:event:<channel>:<name>`
/// — so PHP listeners on one channel never fire for another channel that
/// happens to broadcast the same event name.
///
/// The socket lives here (native), NOT in PHP: the PHP runloop blocks in
/// `nativephp_element_wait_event()` and cannot host a persistent connection.
/// PHP declares intent (subscribe/unsubscribe) over the bridge; this class owns
/// the connection lifecycle and REFCOUNTS channels app-wide — a channel closes
/// only when its last subscriber unsubscribes, and the socket tears down when
/// no channels remain.
final class EchoClient: NSObject, PusherDelegate {
    static let shared = EchoClient()
    private override init() { super.init() }

    // Bridge calls and Pusher/URLSession callbacks arrive on different
    // threads; all mutable state below is guarded by this lock.
    private let lock = NSRecursiveLock()

    private var pusher: Pusher?
    private var channels: [String: PusherChannel] = [:]
    private var refCounts: [String: Int] = [:]

    // Tracks whether we've connected at least once ON THIS SOCKET, so a later
    // transition back to .connected is a RECONNECT (worth telling PHP to
    // refetch state). Reset when the socket is torn down — a fresh connection
    // after a full teardown is a first connect, not a reconnect.
    private var hasBeenConnected = false

    // Auth config for private/presence channels. Mutable so a token refresh
    // (Vibe.SetAuthToken) is picked up by the next channel-auth request,
    // including re-auth after a reconnect.
    private var authEndpoint: String?
    private var authToken: String?

    /// Snapshot of the auth config for the request builder (called from a
    /// URLSession thread).
    func currentAuth() -> (endpoint: String?, token: String?) {
        lock.lock(); defer { lock.unlock() }
        return (authEndpoint, authToken)
    }

    /// Connect lazily (first subscribe) and subscribe to a channel. PusherSwift
    /// auto-detects the private-/presence- prefix and runs the auth flow via the
    /// configured authRequestBuilder. Subscribing to an already-subscribed
    /// channel just bumps its refcount.
    func subscribe(key: String, host: String, port: Int, useTLS: Bool, channel: String,
                   authEndpoint: String?, authToken: String?) {
        lock.lock(); defer { lock.unlock() }

        // Capture the latest auth config; used when the connection is built.
        if let e = authEndpoint, !e.isEmpty { self.authEndpoint = e }
        if let t = authToken, !t.isEmpty { self.authToken = t }

        connectIfNeeded(key: key, host: host, port: port, useTLS: useTLS)
        guard let pusher = pusher else { return }

        if let count = refCounts[channel] {
            refCounts[channel] = count + 1
            return
        }
        refCounts[channel] = 1

        if channel.hasPrefix("presence-") {
            // Presence: subscribe with member callbacks and emit the initial
            // roster on subscription success. Developer events on the channel
            // still arrive via the connection-wide bind in connectIfNeeded().
            let presence = pusher.subscribeToPresenceChannel(
                channelName: channel,
                onMemberAdded: { [weak self] member in
                    self?.emitMember("joining", channel: channel, member: member)
                },
                onMemberRemoved: { [weak self] member in
                    self?.emitMember("leaving", channel: channel, member: member)
                }
            )
            presence.bind(eventName: "pusher:subscription_succeeded", eventCallback: { [weak self, weak presence] (_: PusherEvent) in
                guard let self = self, let presence = presence else { return }
                let members = presence.members.map { self.memberDict($0) }
                self.emit("here", channel: channel, payload: ["members": members])
            })
            channels[channel] = presence
        } else {
            // Public + private (PusherSwift auto-auths the private- prefix).
            channels[channel] = pusher.subscribe(channel)
        }
    }

    // MARK: - Presence member forwarding

    private func memberDict(_ member: PusherPresenceChannelMember) -> [String: Any] {
        return ["id": member.userId ?? "", "info": member.userInfo ?? [:]]
    }

    private func emitMember(_ type: String, channel: String, member: PusherPresenceChannelMember) {
        emit(type, channel: channel, payload: memberDict(member))
    }

    /// Emit a synthetic, channel-scoped native event (vibe:<type>:<channel>) that
    /// PHP's presence here()/joining()/leaving() listeners are registered under.
    private func emit(_ type: String, channel: String, payload: [String: Any]) {
        guard let data = try? JSONSerialization.data(withJSONObject: payload),
              let json = String(data: data, encoding: .utf8) else { return }
        NativeElementBridge.sendNativeEvent(eventName: "vibe:\(type):\(channel)", payloadJson: json)
    }

    /// Forward a native-side failure (channel auth rejection, subscription or
    /// connection error) to PHP as a vibe:error event, so it is observable via
    /// ->onError() instead of vanishing.
    private func emitError(type: String, channel: String?, message: String) {
        let payload: [String: Any] = ["type": type, "channel": channel ?? "", "message": message]
        guard let data = try? JSONSerialization.data(withJSONObject: payload),
              let json = String(data: data, encoding: .utf8) else { return }
        NativeElementBridge.sendNativeEvent(eventName: "vibe:error", payloadJson: json)
    }

    /// Drop one subscriber from a channel; actually unsubscribe when the last
    /// one leaves, and tear the socket down when no channels remain.
    func unsubscribe(channel: String) {
        lock.lock(); defer { lock.unlock() }

        guard let count = refCounts[channel] else { return }
        if count > 1 {
            refCounts[channel] = count - 1
            return
        }

        refCounts[channel] = nil
        pusher?.unsubscribe(channel)
        channels[channel] = nil
        if channels.isEmpty {
            pusher?.disconnect()
            pusher = nil
            hasBeenConnected = false
        }
    }

    /// Update the bearer token on the live connection (token refresh / re-login).
    func setAuthToken(_ token: String) {
        lock.lock(); defer { lock.unlock() }
        self.authToken = token
    }

    /// Send a client event (client-*) to the other subscribers of a private/
    /// presence channel — ephemeral, peer-to-peer, no server.
    func trigger(channel: String, event: String, dataJson: String) {
        lock.lock(); defer { lock.unlock() }
        guard let ch = channels[channel] else { return }
        let data = (try? JSONSerialization.jsonObject(with: Data(dataJson.utf8))) ?? [:]
        ch.trigger(eventName: event, data: data)
    }

    private func connectIfNeeded(key: String, host: String, port: Int, useTLS: Bool) {
        guard pusher == nil else { return }

        // Configure the channel-auth method only when an endpoint is set;
        // public-only apps use .noMethod.
        let authMethod: AuthMethod = (authEndpoint?.isEmpty == false)
            ? .authRequestBuilder(authRequestBuilder: VibeAuthRequestBuilder())
            : .noMethod

        // Self-hosted / drop-in Pusher (Vask, Reverb) — point at the host
        // instead of a Pusher cluster. host is the WEBSOCKET host, e.g.
        // wss.vask.dev; PusherSwift builds the /app/{key} path itself.
        let options = PusherClientOptions(
            authMethod: authMethod,
            host: .host(host),
            port: port,
            useTLS: useTLS
        )

        let pusher = Pusher(key: key, options: options)
        pusher.connection.delegate = self

        // Global binding: receive ALL events across the connection and forward
        // the developer-facing ones, scoped by channel. Pusher's own protocol
        // frames (pusher:*, pusher_internal:*) are filtered out.
        _ = pusher.bind(eventCallback: { (event: PusherEvent) in
            let name = event.eventName
            if name.hasPrefix("pusher:") || name.hasPrefix("pusher_internal:") { return }
            guard let channel = event.channelName else { return }
            NativeElementBridge.sendNativeEvent(
                eventName: "vibe:event:\(channel):\(name)",
                payloadJson: event.data ?? "{}"
            )
        })

        pusher.connect()
        self.pusher = pusher
    }

    // MARK: - PusherDelegate

    /// Forward connection transitions as synthetic native events: a return to
    /// .connected after we'd connected before is a RECONNECT (PHP should
    /// refetch state — websockets don't replay missed messages); a drop to
    /// .disconnected is surfaced for "reconnecting…" UI.
    func changedConnectionState(from old: ConnectionState, to new: ConnectionState) {
        lock.lock(); defer { lock.unlock() }

        if new == .connected {
            if hasBeenConnected {
                NativeElementBridge.sendNativeEvent(eventName: "vibe:reconnected", payloadJson: "{}")
            }
            hasBeenConnected = true
        } else if new == .disconnected, hasBeenConnected {
            NativeElementBridge.sendNativeEvent(eventName: "vibe:disconnected", payloadJson: "{}")
        }
    }

    /// A private/presence subscription was rejected (bad token, 403 from
    /// /broadcasting/auth, …) — the most common setup failure. Surface it.
    func failedToSubscribeToChannel(name: String, response: URLResponse?, data: String?, error: NSError?) {
        let message = error?.localizedDescription ?? data ?? "subscription failed"
        NSLog("[Vibe] failed to subscribe to %@: %@", name, message)
        emitError(type: "subscription_failed", channel: name, message: message)
    }

    func receivedError(error: PusherError) {
        let message = error.message ?? "unknown connection error"
        NSLog("[Vibe] connection error: %@", message)
        emitError(type: "connection_error", channel: nil, message: message)
    }
}

/// Builds the POST to the app's remote /broadcasting/auth with the user's bearer
/// token, for private/presence subscriptions. Reads the (mutable) endpoint +
/// token from EchoClient so a refreshed token is used on the next request.
class VibeAuthRequestBuilder: AuthRequestBuilderProtocol {
    func requestFor(socketID: String, channelName: String) -> URLRequest? {
        let auth = EchoClient.shared.currentAuth()
        guard let endpoint = auth.endpoint,
              let url = URL(string: endpoint) else {
            return nil
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.addValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        request.addValue("application/json", forHTTPHeaderField: "Accept")
        if let token = auth.token {
            request.addValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        request.httpBody = "socket_id=\(socketID)&channel_name=\(channelName)".data(using: .utf8)
        return request
    }
}
