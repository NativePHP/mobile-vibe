import Foundation

enum VibeFunctions {

    /// Connect (if needed) and subscribe to a channel. Params come from PHP's
    /// Vibe::subscribe(), which reads the connection from config('vibe.connection')
    /// and (for private/presence) the auth endpoint + current bearer token.
    class Subscribe: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let key = parameters["key"] as? String ?? ""
            let host = parameters["host"] as? String ?? ""
            let port = (parameters["port"] as? Int) ?? Int(parameters["port"] as? String ?? "") ?? 443
            let useTLS = (parameters["tls"] as? Bool) ?? true
            let channel = parameters["channel"] as? String ?? ""
            let authEndpoint = parameters["authEndpoint"] as? String
            let authToken = parameters["authToken"] as? String

            guard !key.isEmpty, !host.isEmpty, !channel.isEmpty else {
                return BridgeResponse.success(data: [
                    "subscribed": false,
                    "reason": "missing key/host/channel",
                ])
            }

            EchoClient.shared.subscribe(
                key: key, host: host, port: port, useTLS: useTLS, channel: channel,
                authEndpoint: authEndpoint, authToken: authToken
            )
            return BridgeResponse.success(data: ["subscribed": channel])
        }
    }

    /// Unsubscribe from a channel.
    class Unsubscribe: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let channel = parameters["channel"] as? String ?? ""
            EchoClient.shared.unsubscribe(channel: channel)
            return BridgeResponse.success(data: ["unsubscribed": channel])
        }
    }

    /// Update the bearer token on the live connection (token refresh / re-login).
    class SetAuthToken: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let token = parameters["token"] as? String ?? ""
            EchoClient.shared.setAuthToken(token)
            return BridgeResponse.success(data: ["updated": true])
        }
    }

    /// Send a client event (client-*) to the other subscribers of a channel.
    class Trigger: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let channel = parameters["channel"] as? String ?? ""
            let event = parameters["event"] as? String ?? ""
            let data = parameters["data"] as? String ?? "{}"
            EchoClient.shared.trigger(channel: channel, event: event, dataJson: data)
            return BridgeResponse.success(data: ["triggered": event])
        }
    }
}
