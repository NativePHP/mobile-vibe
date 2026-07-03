<?php

namespace NativePHP\Vibe\Attributes;

use Attribute;
use Native\Mobile\Attributes\On;

/**
 * Listen for a broadcast (websocket) event on a subscribed channel.
 *
 * Broadcast events are delivered as channel-scoped native events
 * (vibe:event:<channel>:<name>), so the channel is part of the listener: the
 * method only fires for that channel's broadcasts, never for another channel
 * that happens to use the same event name. Discovery rides the same dispatch
 * path as #[On] (NativeComponent scans for On-and-subclasses via
 * ReflectionAttribute::IS_INSTANCEOF).
 *
 *     // paired with Vibe::channel('orders') in mount()
 *     #[OnEcho('OrderShipped', channel: 'orders')]
 *     public function shipped(string $trackingCode): void { ... }
 *
 * Use the FULL channel name as subscribed — 'private-orders.42' for
 * Vibe::private('orders.42'), 'presence-room.1' for Vibe::presence('room.1').
 * The event name must match what the server broadcasts — use broadcastAs() on
 * your Laravel event to keep it short instead of the default FQCN.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class OnEcho extends On
{
    public function __construct(string $event, string $channel)
    {
        // Bypass On's 'native:' prefix — broadcast events are emitted under
        // the scoped vibe:event: name, which dispatch matches exactly.
        $this->event = "vibe:event:{$channel}:{$event}";
    }
}
