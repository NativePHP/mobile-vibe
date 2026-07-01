<?php

namespace Nativephp\Vibe\Attributes;

use Attribute;
use Native\Mobile\Attributes\On;

/**
 * Listen for a broadcast (websocket) event on a subscribed channel.
 *
 * A broadcast event is delivered to the component as a native (type-20) event,
 * so it rides the exact same dispatch path as #[On] — this subclass exists so
 * the API reads as "listen for a server broadcast" and gives channel-binding
 * metadata a home later. Discovery relies on NativeComponent scanning for
 * On-and-subclasses (ReflectionAttribute::IS_INSTANCEOF).
 *
 *     #[OnEcho('OrderShipped')]
 *     public function shipped(string $trackingCode): void { ... }
 *
 * The event name must match what the server broadcasts — use broadcastAs() on
 * your Laravel event to keep it short instead of the default FQCN.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class OnEcho extends On
{
}
