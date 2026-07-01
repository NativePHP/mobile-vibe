<?php

namespace Nativephp\Vibe\Exceptions;

use RuntimeException;

/**
 * Thrown for Vibe misconfiguration that would otherwise fail silently on the
 * device — a missing PUSHER_* connection, a private/presence subscription with
 * no auth endpoint, or a listener registered outside a NativeComponent.
 */
class VibeException extends RuntimeException
{
    public static function missingConnection(): self
    {
        return new self(
            'Vibe is not configured: PUSHER_APP_KEY and PUSHER_HOST must be set in your '
            .'app\'s .env (they are bundled into the app at build time). '
            .'See config/vibe.php.'
        );
    }

    public static function missingAuthEndpoint(string $channel): self
    {
        return new self(
            "Cannot subscribe to [{$channel}]: private- and presence- channels require "
            .'VIBE_AUTH_ENDPOINT (your remote Laravel /broadcasting/auth URL) to be set.'
        );
    }

    public static function listenerOutsideComponent(string $method): self
    {
        return new self(
            "Vibe::{$method}() listeners must be registered from within a NativeComponent "
            .'method (e.g. mount()) so events can be routed back to the component. '
            .'The callback you passed is not bound to a NativeComponent.'
        );
    }
}
