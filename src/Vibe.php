<?php

namespace NativePHP\Vibe;

use Closure;
use Native\Mobile\Edge\NativeComponent;
use NativePHP\Vibe\Exceptions\VibeException;

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

    /**
     * The most recent token pushed via withToken(). Preferred over the static
     * config token on later subscribes, so a runtime refresh isn't clobbered
     * by a stale compile-time value.
     */
    protected ?string $pushedToken = null;

    public function resolveTokenUsing(Closure $resolver): void
    {
        $this->tokenResolver = $resolver;
    }

    protected function currentToken(): ?string
    {
        // Precedence: runtime resolver > token pushed via withToken() > the
        // static config token (POC / single-token setups).
        if ($this->tokenResolver) {
            return ($this->tokenResolver)();
        }

        return $this->pushedToken ?? config('vibe.auth.token');
    }

    /**
     * Push a fresh bearer token to the live native authorizer (e.g. after a
     * re-login / token refresh) without reconnecting. The native SDK uses it on
     * the next channel auth request, including re-auth after a reconnect.
     */
    public function withToken(string $token): void
    {
        $this->pushedToken = $token;

        if (function_exists('nativephp_call')) {
            nativephp_call('Vibe.SetAuthToken', json_encode(['token' => $token]));
        }
    }

    /**
     * Subscribe the native socket to a channel.
     *
     * Reads the Pusher-protocol connection from config (populated from the
     * app's .env) and hands it to the native EchoClient over the bridge. The
     * native side connects lazily on the first subscribe and refcounts
     * channels, so subscribing to an already-subscribed channel is a cheap
     * no-op and the channel stays open until the last subscriber leaves.
     *
     * Incoming messages arrive back as channel-scoped native (type-20) events
     * (vibe:event:<channel>:<name>) and are routed to #[OnEcho] listeners — or
     * to fluent ->on() closures on the returned PendingSubscription — on the
     * active NativeComponent. The subscription is torn down automatically when
     * that component unmounts, including for attribute-only usage.
     *
     * @throws VibeException when called on-device without PUSHER_APP_KEY/PUSHER_HOST,
     *                       or for private-/presence- channels without VIBE_AUTH_ENDPOINT.
     */
    public function subscribe(string $channel): PendingSubscription
    {
        $component = $this->detectComponent();

        if (function_exists('nativephp_call')) {
            $conn = config('vibe.connection', []);

            if (empty($conn['key']) || empty($conn['host'])) {
                throw VibeException::missingConnection();
            }

            $needsAuth = str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-');

            if ($needsAuth && empty(config('vibe.auth.endpoint'))) {
                throw VibeException::missingAuthEndpoint($channel);
            }

            nativephp_call('Vibe.Subscribe', json_encode([
                'key' => $conn['key'],
                'host' => $conn['host'],
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

        return new PendingSubscription($channel, $component);
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
     * Unsubscribe the native socket from a channel (refcounted native-side; the
     * channel actually closes when the last subscriber leaves).
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

    /**
     * Find the NativeComponent that is subscribing, by walking up the call
     * stack from Vibe::subscribe() (typically to mount()). Lets subscribe()
     * register unmount cleanup even when the caller only uses #[OnEcho]
     * attributes and never attaches a fluent closure.
     */
    protected function detectComponent(): ?NativeComponent
    {
        if (! class_exists(NativeComponent::class)) {
            return null;
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
            if (($frame['object'] ?? null) instanceof NativeComponent) {
                return $frame['object'];
            }
        }

        return null;
    }
}
