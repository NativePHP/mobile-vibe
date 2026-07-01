<?php

namespace Nativephp\Vibe;

use Closure;

class Vibe
{
    /**
     * Runtime resolver for the current bearer token used to authorize private/
     * presence channels. Registered once (e.g. in AppServiceProvider::boot):
     *
     *     Vibe::resolveTokenUsing(fn () => SecureStorage::get('api_token'));
     *
     * Resolved fresh at subscribe-time so token rotation is picked up.
     */
    protected ?Closure $tokenResolver = null;

    public function resolveTokenUsing(Closure $resolver): void
    {
        $this->tokenResolver = $resolver;
    }

    protected function currentToken(): ?string
    {
        // A registered runtime resolver wins; otherwise fall back to the static
        // config token (POC / single-token setups).
        return $this->tokenResolver
            ? ($this->tokenResolver)()
            : config('vibe.auth.token');
    }

    /**
     * Push a fresh bearer token to the live native authorizer (e.g. after a
     * re-login / token refresh) without reconnecting. The native SDK uses it on
     * the next channel auth request, including re-auth after a reconnect.
     */
    public function withToken(string $token): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Vibe.SetAuthToken', json_encode(['token' => $token]));
        }
    }

    /**
     * Subscribe the native socket to a channel.
     *
     * Reads the Reverb connection from config (populated from the app's .env)
     * and hands it to the native EchoClient over the bridge. The native side
     * connects lazily on the first subscribe and refcounts channels, so calling
     * this for a channel that is already subscribed is a cheap no-op.
     *
     * Incoming messages arrive back as native (type-20) events and are routed
     * to #[OnEcho] listeners — or to fluent ->on() closures on the returned
     * PendingSubscription — on the active NativeComponent.
     */
    public function subscribe(string $channel): PendingSubscription
    {
        if (function_exists('nativephp_call')) {
            $conn = config('vibe.connection', []);

            nativephp_call('Vibe.Subscribe', json_encode([
                'key' => $conn['key'] ?? null,
                'host' => $conn['host'] ?? null,
                'port' => (int) ($conn['port'] ?? 443),
                'tls' => ($conn['scheme'] ?? 'https') === 'https',
                'channel' => $channel,
                // Passed on every subscribe so the native authorizer is
                // configured at connect-time; private-/presence- channels use it,
                // public ones ignore it.
                'authEndpoint' => config('vibe.auth.endpoint'),
                'authToken' => $this->currentToken(),
            ]));
        }

        return new PendingSubscription($channel);
    }

    /**
     * Send a client event directly to the other subscribers of a channel (no
     * server round-trip, not persisted). Client events require a private/presence
     * channel and must be named `client-*`. Prefer PendingSubscription::whisper().
     */
    public function trigger(string $channel, string $event, array $data = []): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Vibe.Trigger', json_encode([
                'channel' => $channel,
                'event' => $event,
                'data' => json_encode($data),
            ]));
        }
    }

    /**
     * Subscribe to a PUBLIC channel — Echo-style: Vibe::channel('orders').
     */
    public function channel(string $name): PendingSubscription
    {
        return $this->subscribe($name);
    }

    /**
     * Subscribe to a PRIVATE channel (auto-prefixes `private-`). Requires auth.
     * Pass the bare name: Vibe::private('orders.42').
     */
    public function private(string $name): PendingSubscription
    {
        return $this->subscribe('private-'.$name);
    }

    /**
     * Subscribe to a PRESENCE channel (auto-prefixes `presence-`). Requires auth;
     * also tracks members (here/joining/leaving — presence phase).
     * Pass the bare name: Vibe::presence('room.1').
     */
    public function presence(string $name): PendingSubscription
    {
        return $this->subscribe('presence-'.$name);
    }

    /**
     * Unsubscribe the native socket from a channel (refcounted native-side).
     */
    public function unsubscribe(string $channel): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        nativephp_call('Vibe.Unsubscribe', json_encode([
            'channel' => $channel,
        ]));
    }
}
