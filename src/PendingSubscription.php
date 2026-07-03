<?php

namespace NativePHP\Vibe;

use Closure;
use Native\Mobile\Edge\NativeComponent;
use NativePHP\Vibe\Exceptions\VibeException;
use ReflectionFunction;

/**
 * Returned by Vibe::subscribe(); attaches fluent per-event listeners:
 *
 *     Vibe::subscribe('orders')->on('OrderShipped', function ($event) {
 *         $this->status = $event->status;   // $this is the component
 *     });
 *
 * Broadcast events arrive as channel-scoped native events
 * (vibe:event:<channel>:<name>), so a listener on this subscription only fires
 * for THIS channel — two channels broadcasting the same event name never cross.
 *
 * The owning component is resolved when the subscription is created (from the
 * call stack) or recovered from the listener closure's $this; listeners are
 * persistent (fire on every matching event) and the channel is unsubscribed
 * automatically when the component unmounts. Chainable: call ->on() as many
 * times as you like.
 */
class PendingSubscription
{
    protected bool $cleanupRegistered = false;

    public function __construct(
        protected string $channel,
        protected ?NativeComponent $component = null,
    ) {
        // Register unmount cleanup as soon as we know the component, so even
        // attribute-only usage (Vibe::channel(...) + #[OnEcho]) tears down.
        if ($component !== null) {
            $this->unsubscribeOnUnmount($component);
        }
    }

    /**
     * Run $callback every time $event arrives on THIS channel. $event is a
     * plain object built from the broadcast payload ($event->field).
     */
    public function on(string $event, Closure $callback): static
    {
        return $this->listen("vibe:event:{$this->channel}:{$event}", $callback, 'on');
    }

    /**
     * Send an ephemeral client event directly to the OTHER subscribers of this
     * channel (no server, not persisted) — e.g. chat messages, typing. Requires
     * a private/presence channel. Mirrors Laravel Echo's whisper(); the `client-`
     * prefix Pusher requires is added for you. The sender does NOT receive its
     * own whisper, so update local state optimistically.
     */
    public function whisper(string $event, array $data = []): static
    {
        app(Vibe::class)->trigger($this->channel, 'client-'.$event, $data);

        return $this;
    }

    /** Listen for a whisper (client event) sent by another subscriber of this channel. */
    public function listenForWhisper(string $event, Closure $callback): static
    {
        return $this->on('client-'.$event, $callback);
    }

    /**
     * Run $callback when the socket RECONNECTS (after a drop — backgrounding,
     * network flap). Websockets don't replay missed messages, so use this to
     * refetch authoritative state. Connection-level, so it fires for every
     * subscription's handler on any reconnect.
     */
    public function onReconnect(Closure $callback): static
    {
        return $this->listen('vibe:reconnected', $callback, 'onReconnect');
    }

    /**
     * Run $callback when the socket DISCONNECTS (backgrounding, network loss).
     * Pairs with onReconnect() — e.g. show a "reconnecting…" banner.
     * Connection-level, like onReconnect().
     */
    public function onDisconnect(Closure $callback): static
    {
        return $this->listen('vibe:disconnected', $callback, 'onDisconnect');
    }

    /**
     * Run $callback when the native side reports an error — a failed channel
     * auth (403 from /broadcasting/auth), a failed subscription, or a
     * connection error. $event is { type, channel, message }. Connection-level.
     */
    public function onError(Closure $callback): static
    {
        return $this->listen('vibe:error', $callback, 'onError');
    }

    /**
     * Presence: the current members when you join. $callback receives the
     * members array (each member: { id, info }).
     */
    public function here(Closure $callback): static
    {
        return $this->member('here', $callback, fn ($event, $cb) => $cb($event->members ?? []));
    }

    /** Presence: a member joined. $callback receives the member ['id' => , 'info' => ]. */
    public function joining(Closure $callback): static
    {
        return $this->member('joining', $callback, fn ($event, $cb) => $cb((array) $event));
    }

    /** Presence: a member left. $callback receives the member ['id' => , 'info' => ]. */
    public function leaving(Closure $callback): static
    {
        return $this->member('leaving', $callback, fn ($event, $cb) => $cb((array) $event));
    }

    /**
     * Register a presence member listener. Native emits synthetic, channel-scoped
     * events (vibe:here|joining|leaving:<channel>); $forward adapts the generic
     * event object into the shape here()/joining()/leaving() promise.
     */
    private function member(string $type, Closure $callback, Closure $forward): static
    {
        return $this->listen(
            "vibe:{$type}:{$this->channel}",
            fn ($event) => $forward($event, $callback),
            $type,
            resolveFrom: $callback,
        );
    }

    /**
     * Register $callback for a fully-resolved native event name on the owning
     * component. The component is the one captured at subscribe-time, or —
     * when subscribe() was called outside a component (e.g. from a service) —
     * recovered from the user's closure, which is bound to $this where it was
     * written. Throws when neither yields a component: a listener that can
     * never fire is a bug, not a no-op.
     */
    private function listen(string $nativeEvent, Closure $callback, string $method, ?Closure $resolveFrom = null): static
    {
        $component = $this->component ?? $this->componentFrom($resolveFrom ?? $callback);

        if ($component === null) {
            throw VibeException::listenerOutsideComponent($method);
        }

        $component->registerNativeEventListener($nativeEvent, $callback);
        $this->unsubscribeOnUnmount($component);

        return $this;
    }

    private function componentFrom(Closure $callback): ?NativeComponent
    {
        $bound = (new ReflectionFunction($callback))->getClosureThis();

        return $bound instanceof NativeComponent ? $bound : null;
    }

    /**
     * Unsubscribe from this channel when the component unmounts (navigates away),
     * so leaving the screen also leaves the channel / presence room. Registered
     * once per subscription, even if several listeners are attached.
     */
    private function unsubscribeOnUnmount(NativeComponent $component): void
    {
        if ($this->cleanupRegistered) {
            return;
        }

        $this->cleanupRegistered = true;
        $channel = $this->channel;
        $component->registerCleanup(fn () => app(Vibe::class)->unsubscribe($channel));
    }

    public function channel(): string
    {
        return $this->channel;
    }
}
