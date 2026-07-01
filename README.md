# Vibe — websockets for NativePHP Mobile

Vibe brings live server events into your NativePHP Mobile app over the **Pusher
protocol** — so it works with **Vask**, **Laravel Reverb**, or **Pusher** without
changing your code. Your PHP components subscribe to channels and react to
broadcasts, exactly like Laravel Echo does in the browser.

The websocket lives on the native side (Swift/Kotlin, via the official Pusher
SDKs); PHP just declares what to subscribe to and handles the events.

## Requirements

- PHP 8.2+, `nativephp/mobile` with Edge components (`NativeComponent`)
- iOS 15+ / Android API 21+
- A Pusher-protocol websocket server: [Vask](https://vask.dev), [Laravel Reverb](https://laravel.com/docs/reverb), or [Pusher](https://pusher.com)

## Install

```bash
composer require nativephp/mobile-vibe
php artisan native:plugin:register nativephp/mobile-vibe
```

## Configure

Use the standard Laravel `PUSHER_*` vars in your app's `.env` (they're bundled
into the app at build time):

```dotenv
PUSHER_APP_KEY=your-app-key
PUSHER_HOST=wss.vask.dev      # the WEBSOCKET host
PUSHER_PORT=443
PUSHER_SCHEME=https
```

The app secret is **never** shipped to the device.

If `PUSHER_APP_KEY` / `PUSHER_HOST` are missing when you subscribe on-device,
Vibe throws a `VibeException` immediately rather than failing silently.

## Public channels

```php
use Nativephp\Vibe\Facades\Vibe;

public function mount(): void
{
    Vibe::channel('orders')->on('OrderShipped', function ($event) {
        $this->status = $event->status;   // $this is the component
    });
}
```

Events arrive as native events and re-render the component. Match the event name
to what your server broadcasts — **without** `broadcastAs()` that's the full
class name (`App\Events\OrderShipped`), so use `broadcastAs()` to keep it short.
Unlike Laravel Echo, do **not** prefix the name with a dot: Vibe matches the raw
broadcast name (`'OrderShipped'`, not `'.OrderShipped'`).

Listeners are **channel-scoped**: an `OrderShipped` listener on `orders` never
fires for a different channel that happens to broadcast the same event name.

You can also use an attribute instead of the fluent `->on()` — pass the channel
so the listener is scoped (use the full name as subscribed, e.g.
`private-orders.42` for `Vibe::private('orders.42')`):

```php
#[\Nativephp\Vibe\Attributes\OnEcho('OrderShipped', channel: 'orders')]
public function shipped(string $status): void { ... }
```

## Private channels

Private (`private-`) and presence (`presence-`) channels require a signed
authorization from **your remote Laravel backend**. The default
`/broadcasting/auth` route is session-guarded, which won't work for a mobile
bearer token — register it under your API middleware instead:

```php
// Your BACKEND's broadcasting setup (e.g. AppServiceProvider::boot())
Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']]);
```

Then point Vibe at it and give it the current bearer token:

```dotenv
VIBE_AUTH_ENDPOINT=https://your-backend.example.com/api/v1/broadcasting/auth
```

```php
// e.g. in AppServiceProvider::boot()
use Nativephp\Vibe\Facades\Vibe;
use Native\Mobile\Facades\SecureStorage;

Vibe::resolveTokenUsing(fn () => SecureStorage::get('api_token'));
```

```php
Vibe::private('orders.42')->on('OrderShipped', fn ($e) => $this->status = $e->status);
```

Subscribing to a private/presence channel without `VIBE_AUTH_ENDPOINT` set
throws a `VibeException`.

After a re-login / token refresh, push the new token to the live connection:

```php
Vibe::withToken($freshToken);
```

## Presence channels

Presence channels track who's online. The auth response carries `channel_data`
(user id + info); Vibe surfaces the roster and member changes:

```php
Vibe::presence('room.1')
    ->here(fn (array $members) => $this->online = $members)   // each: ['id' => , 'info' => [...]]
    ->joining(fn (array $member) => $this->online[] = $member)
    ->leaving(fn (array $member) => /* remove */)
    ->on('MessageSent', fn ($e) => $this->messages[] = $e->body);
```

On reconnect the SDKs re-subscribe and the `here` roster is delivered again —
you don't need to rebuild presence state manually.

## Whispers (client events)

Send ephemeral events directly to the other subscribers of a private/presence
channel (no server round-trip, not persisted) — typing indicators, cursors:

```php
$room = Vibe::presence('room.1')
    ->listenForWhisper('typing', fn ($e) => $this->typing = $e->name);

$room->whisper('typing', ['name' => $this->name]);   // sender doesn't receive its own whisper
```

## Connection lifecycle & errors

```php
Vibe::channel('orders')
    ->on('OrderShipped', fn ($e) => $this->refresh())
    ->onDisconnect(fn () => $this->live = false)        // show "reconnecting…"
    ->onReconnect(fn () => $this->refetch())            // refetch missed state
    ->onError(fn ($e) => logger()->warning("vibe: {$e->type} {$e->message}"));
```

- `onReconnect` / `onDisconnect` / `onError` are **connection-level** — they
  fire regardless of which subscription registered them.
- `onError` receives `{ type, channel, message }` for failed channel auth
  (e.g. a 403 from `/broadcasting/auth`), failed subscriptions, and connection
  errors — the failures that are otherwise invisible on a device.

## Lifecycle

- Subscriptions are torn down automatically when the component unmounts —
  including attribute-only usage (`Vibe::channel(...)` in `mount()` +
  `#[OnEcho]`).
- Channels are refcounted natively: if two components subscribe to the same
  channel, it stays open until the last one leaves. The socket disconnects
  when no channels remain.
- Listeners must be registered from within a `NativeComponent` (typically
  `mount()`); registering elsewhere throws a `VibeException`.

## On your server

The app is a plain Pusher-protocol subscriber, so the server side is standard
[Laravel broadcasting](https://laravel.com/docs/broadcasting). The three pieces
Vibe cares about:

```php
// 1. The event — broadcastAs() keeps the client-side name short; the payload
//    ($event->message on the device) comes from public properties or broadcastWith().
class OrderShipped implements ShouldBroadcast
{
    public function __construct(public string $message) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('orders.'.$this->orderId);
    }

    public function broadcastAs(): string
    {
        return 'OrderShipped';
    }
}
```

```php
// 2. routes/channels.php — authorize private channels (true/false)...
Broadcast::channel('orders.{orderId}', fn ($user, $orderId) => $user->canSee($orderId));

// 3. ...and presence channels, which must RETURN THE MEMBER ARRAY — this becomes
//    channel_data in the auth response and is what here()/joining() receive as
//    ['id' => ..., 'info' => [...]]. Returning true breaks the roster.
Broadcast::channel('room.{id}', fn ($user, $id) => ['id' => $user->id, 'name' => $user->name]);
```

Whispers require client events to be enabled on your websocket server (Pusher:
app settings → "Enable client events"; check your Reverb/Vask config).

## Troubleshooting

**Nothing arrives:**
- Event name mismatch — no `broadcastAs()` means the name is the FQCN
  (`App\Events\OrderShipped`); and no leading dot, unlike Echo.
- `#[OnEcho]` channel mismatch — use the **full** channel name as subscribed:
  `channel: 'private-orders.42'`, not `channel: 'orders.42'`.
- The app is backgrounded — the OS suspends the socket; events are
  foreground-only.
- Queued broadcasts need a running queue worker on the server (or
  `ShouldBroadcastNow`).

**Private/presence fails:** attach `->onError()` — auth rejections arrive as
`auth_failed` / `subscription_failed` with the reason. Usual suspects: expired
bearer token, `VIBE_AUTH_ENDPOINT` pointing at a dead tunnel/host, or the
backend route not guarded by an API (token) middleware.

**Misconfiguration throws:** missing `PUSHER_APP_KEY`/`PUSHER_HOST`, or a
private/presence subscribe without `VIBE_AUTH_ENDPOINT`, throws `VibeException`
at subscribe-time rather than failing silently.

## Notes

- Websockets are foreground-only on mobile (the OS suspends the socket in the
  background). For delivery while the app is closed, use push notifications.
- Websocket events signal *liveness*, not source of truth — on reconnect, refetch
  authoritative state (`onReconnect` is built for exactly this).
