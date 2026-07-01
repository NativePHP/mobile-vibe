<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Websocket connection (Pusher-protocol: Vask, Reverb, Pusher, ...)
    |--------------------------------------------------------------------------
    |
    | The mobile app is a *client* of a remote Pusher-protocol server, exactly
    | like a browser Echo client. Users configure this in the app's .env (the
    | same PUSHER_* vars Laravel already uses); NativePHP bundles .env at
    | compile time, so the on-device PHP reads these and hands them to the
    | native socket at subscribe-time. The APP SECRET is never needed on the
    | client (public channels don't use it), so it is not read here.
    |
    | `host` is the WEBSOCKET host (e.g. wss.vask.dev) — NOT the HTTP API host.
    |
    */
    'connection' => [
        'key' => env('PUSHER_APP_KEY'),
        'host' => env('PUSHER_HOST'),
        'port' => (int) env('PUSHER_PORT', 443),
        'scheme' => env('PUSHER_SCHEME', 'https'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Private / presence channel authorization
    |--------------------------------------------------------------------------
    |
    | Private (`private-`) and presence (`presence-`) channels require a signed
    | auth token. The app secret is NEVER on the device, so the native SDK POSTs
    | { socket_id, channel_name } to this REMOTE endpoint (your Laravel backend's
    | /broadcasting/auth, guarded by auth:sanctum) with the user's bearer token.
    | Supply the current token at runtime via Vibe::resolveTokenUsing(fn () => ...)
    | — it can't be a compile-time value because it's per-user and rotates.
    |
    */
    'auth' => [
        'endpoint' => env('VIBE_AUTH_ENDPOINT'),

        // Optional static bearer token — a convenience for POC / single-token
        // setups. Real per-user apps should instead register a runtime resolver
        // (Vibe::resolveTokenUsing(...)), which takes precedence over this.
        'token' => env('VIBE_AUTH_TOKEN'),
    ],
];
