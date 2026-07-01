## nativephp/mobile-vibe

Live websocket events (Pusher protocol — Vask / Reverb / Pusher) into NativePHP
Mobile components. PHP is a client subscriber; the socket lives natively.

### Configuration

Standard `PUSHER_*` vars in `.env` (`PUSHER_APP_KEY`, `PUSHER_HOST`,
`PUSHER_PORT`, `PUSHER_SCHEME`). For private/presence channels also set
`VIBE_AUTH_ENDPOINT` (your remote Laravel `/broadcasting/auth`, `auth:sanctum`)
and register a bearer-token resolver.

### PHP Usage

Subscribe in a NativeComponent's `mount()`. The websocket only reaches the
component while that screen is foregrounded; subscriptions auto-teardown on
unmount.

@verbatim
<code-snippet name="Vibe channels" lang="php">
use Nativephp\Vibe\Facades\Vibe;

// Public
Vibe::channel('orders')->on('OrderShipped', fn ($e) => $this->status = $e->status);

// Private (requires auth)
Vibe::private('orders.42')->on('OrderShipped', fn ($e) => $this->status = $e->status);

// Presence (requires auth) — tracks members + events
Vibe::presence('room.1')
    ->here(fn (array $members) => $this->online = $members)
    ->joining(fn (array $member) => $this->online[] = $member)
    ->leaving(fn (array $member) => /* remove by $member['id'] */)
    ->on('MessageSent', fn ($e) => $this->messages[] = $e->body);
</code-snippet>
@endverbatim

The event name matches what the server broadcasts (use `broadcastAs()`). The
`#[Nativephp\Vibe\Attributes\OnEcho('EventName')]` attribute is an alternative to
`->on()`.

### Auth token (private / presence)

@verbatim
<code-snippet name="Token resolver" lang="php">
use Nativephp\Vibe\Facades\Vibe;
use Native\Mobile\Facades\SecureStorage;

// AppServiceProvider::boot()
Vibe::resolveTokenUsing(fn () => SecureStorage::get('api_token'));

// After re-login / refresh, update the live connection:
Vibe::withToken($freshToken);
</code-snippet>
@endverbatim

### Notes

- Foreground-only (the OS suspends background sockets — use push notifications
  for closed-app delivery).
- Events are liveness signals, not source of truth — refetch on reconnect.
- A screen mutating a live list frequently should set
  `protected bool $forceFullFrames = true;` on the component.
