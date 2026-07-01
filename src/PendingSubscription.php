<?php

namespace Nativephp\Vibe;

use Closure;
use Native\Mobile\Edge\NativeComponent;
use ReflectionFunction;

/**
 * Returned by Vibe::subscribe(); attaches fluent per-event listeners:
 *
 *     Vibe::subscribe('orders')->on('OrderShipped', function ($event) {
 *         $this->status = $event->status;   // $this is the component
 *     });
 *
 * The callback is written inside a component method, so it is already bound to
 * that component ($this). We recover the component from the closure and register
 * the listener on it — no "current component" global needed. Listeners are
 * persistent (fire on every matching event) and cleared when the component
 * unmounts. Chainable: call ->on() as many times as you like.
 */
class PendingSubscription
{
    protected bool $cleanupRegistered = false;

    public function __construct(protected string $channel) {}

    /**
     * Run $callback every time $event arrives on this subscription. $event is a
     * plain object built from the broadcast payload ($event->field).
     */
    public function on(string $event, Closure $callback): static
    {
        $component = (new ReflectionFunction($callback))->getClosureThis();

        if ($component instanceof NativeComponent) {
            $component->registerNativeEventListener($event, $callback);
            $this->unsubscribeOnUnmount($component);
        }

        return $this;
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

    /** Listen for a whisper (client event) sent by another subscriber. */
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
        $component = (new ReflectionFunction($callback))->getClosureThis();

        if ($component instanceof NativeComponent) {
            $component->registerNativeEventListener('vibe:reconnected', $callback);
            $this->unsubscribeOnUnmount($component);
        }

        return $this;
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
     * event object into the shape here()/joining()/leaving() promise. Recovers the
     * component from the user's callback (already bound to $this), like on().
     */
    private function member(string $type, Closure $callback, Closure $forward): static
    {
        $component = (new ReflectionFunction($callback))->getClosureThis();

        if ($component instanceof NativeComponent) {
            $component->registerNativeEventListener(
                "vibe:{$type}:{$this->channel}",
                fn ($event) => $forward($event, $callback)
            );
            $this->unsubscribeOnUnmount($component);
        }

        return $this;
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
