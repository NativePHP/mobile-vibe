import Foundation
import PusherSwift

/// Holds the single app-wide Pusher-protocol connection (Vask / Reverb / Pusher)
/// and forwards every channel message into the PHP runloop as a native
/// (type-20) event via `NativeElementBridge.sendNativeEvent`. PHP's
/// `dispatchNativeEvent()` then routes it to `#[OnEcho]` / fluent `->on()`
/// listeners on the active NativeComponent and re-renders.
///
/// The socket lives here (native), NOT in PHP: the PHP runloop blocks in
/// `nativephp_element_wait_event()` and cannot host a persistent connection.
/// PHP declares intent (subscribe/unsubscribe) over the bridge; this class owns
/// the connection lifecycle and refcounts channels app-wide.
final class EchoClient: NSObject, PusherDelegate {
    static let shared = EchoClient()
    private override init() { super.init() }

    private var pusher: Pusher?
    private var channels: [String: PusherChannel] = [:]

    // Tracks whether we've connected at least once, so a later transition back
    // to .connected is a RECONNECT (worth telling PHP to refetch state).
    private var hasBeenConnected = false

    // Auth config for private/presence channels. Mutable so a token refresh
    // (Vibe.SetAuthToken) is picked up by the next channel-auth request,
    // including re-auth after a reconnect.
    fileprivate var authEndpoint: String?
    fileprivate var authToken: String?

    /// Connect lazily (first subscribe) and subscribe to a channel. PusherSwift
    /// auto-detects the private-/presence- prefix and runs the auth flow via the
    /// configured authRequestBuilder. Subscribing to an already-subscribed
    /// channel is a no-op.
    func subscribe(key: String, host: String, port: Int, useTLS: Bool, channel: String,
                   authEndpoint: String?, authToken: String?) {
        // Capture the latest auth config; used when the connection is built.
        if let e = authEndpoint, !e.isEmpty { self.authEndpoint = e }
        if let t = authToken, !t.isEmpty { self.authToken = t }

        connectIfNeeded(key: key, host: host, port: port, useTLS: useTLS)
        guard let pusher = pusher, channels[channel] == nil else { return }

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

    /// Unsubscribe from a channel; tear the socket down when none remain.
    func unsubscribe(channel: String) {
        guard channels[channel] != nil else { return }
        pusher?.unsubscribe(channel)
        channels[channel] = nil
        if channels.isEmpty {
            pusher?.disconnect()
            pusher = nil
        }
    }

    /// Update the bearer token on the live connection (token refresh / re-login).
    func setAuthToken(_ token: String) {
        self.authToken = token
    }

    /// Send a client event (client-*) to the other subscribers of a private/
    /// presence channel — ephemeral, peer-to-peer, no server.
    func trigger(channel: String, event: String, dataJson: String) {
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
        // the developer-facing ones. Pusher's own protocol frames (pusher:*,
        // pusher_internal:*) are filtered out.
        _ = pusher.bind(eventCallback: { (event: PusherEvent) in
            let name = event.eventName
            if name.hasPrefix("pusher:") || name.hasPrefix("pusher_internal:") { return }
            NativeElementBridge.sendNativeEvent(eventName: name, payloadJson: event.data ?? "{}")
        })

        pusher.connect()
        self.pusher = pusher
    }

    // MARK: - PusherDelegate

    /// Forward a RECONNECT (a return to .connected after we'd connected before)
    /// as a synthetic native event so PHP can refetch state — websockets don't
    /// replay messages missed while disconnected.
    func changedConnectionState(from old: ConnectionState, to new: ConnectionState) {
        if new == .connected {
            if hasBeenConnected {
                NativeElementBridge.sendNativeEvent(eventName: "vibe:reconnected", payloadJson: "{}")
            }
            hasBeenConnected = true
        }
    }
}

/// Builds the POST to the app's remote /broadcasting/auth with the user's bearer
/// token, for private/presence subscriptions. Reads the (mutable) endpoint +
/// token from EchoClient so a refreshed token is used on the next request.
class VibeAuthRequestBuilder: AuthRequestBuilderProtocol {
    func requestFor(socketID: String, channelName: String) -> URLRequest? {
        guard let endpoint = EchoClient.shared.authEndpoint,
              let url = URL(string: endpoint) else {
            return nil
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.addValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        request.addValue("application/json", forHTTPHeaderField: "Accept")
        if let token = EchoClient.shared.authToken {
            request.addValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        request.httpBody = "socket_id=\(socketID)&channel_name=\(channelName)".data(using: .utf8)
        return request
    }
}
