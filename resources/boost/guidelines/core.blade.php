## nativephp/mobile-vibe

Live websocket events (Pusher protocol — Vask / Reverb / Pusher) into NativePHP
Mobile components. PHP is a client subscriber; the socket lives natively.

### Configuration

Standard `PUSHER_*` vars in `.env` (`PUSHER_APP_KEY`, `PUSHER_HOST`,
`PUSHER_PORT`, `PUSHER_SCHEME`). For private/presence channels also set
`VIBE_AUTH_ENDPOINT` (your remote Laravel `/broadcasting/auth`, `auth:sanctum`)
and register a bearer-token resolver. Missing config throws `VibeException`
at subscribe-time on-device.

### PHP Usage

Subscribe in a NativeComponent's `mount()`. The websocket only reaches the
component while that screen is foregrounded; subscriptions auto-teardown on
unmount (fluent and attribute usage alike). Channels are refcounted natively.

@verbatim
<code-snippet name="Vibe channels" lang="php">
use NativePHP\Vibe\Facades\Vibe;

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

The event name matches what the server broadcasts (use `broadcastAs()`).
Listeners are channel-scoped — same-named events on other channels don't fire
them. The attribute alternative to `->on()` requires the channel (full name as
subscribed, e.g. `private-orders.42`):
`#[NativePHP\Vibe\Attributes\OnEcho('EventName', channel: 'orders')]`.

### Auth token (private / presence)

@verbatim
<code-snippet name="Token resolver" lang="php">
use NativePHP\Vibe\Facades\Vibe;
use Native\Mobile\Facades\SecureStorage;

// AppServiceProvider::boot()
Vibe::resolveTokenUsing(fn () => SecureStorage::get('api_token'));

// After re-login / refresh, update the live connection:
Vibe::withToken($freshToken);
</code-snippet>
@endverbatim

### Connection lifecycle & errors

@verbatim
<code-snippet name="Lifecycle hooks" lang="php">
Vibe::channel('orders')
    ->onDisconnect(fn () => $this->live = false)   // "reconnecting…" UI
    ->onReconnect(fn () => $this->refetch())       // refetch missed state
    ->onError(fn ($e) => /* $e->type, $e->channel, $e->message */);
</code-snippet>
@endverbatim

These are connection-level (fire for any subscription). `onError` surfaces
failed channel auth (403 from `/broadcasting/auth`), failed subscriptions, and
connection errors.

### Notes

- Foreground-only (the OS suspends background sockets — use push notifications
  for closed-app delivery).
- Events are liveness signals, not source of truth — refetch on reconnect.
  Presence `here` rosters re-deliver automatically after reconnect.
- Listeners must be registered from within a NativeComponent; elsewhere throws.
- A screen mutating a live list frequently should set
  `protected bool $forceFullFrames = true;` on the component.
