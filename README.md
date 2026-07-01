# Vibe — websockets for NativePHP Mobile

Vibe brings live server events into your NativePHP Mobile app over the **Pusher
protocol** — so it works with **Vask**, **Laravel Reverb**, or **Pusher** without
changing your code. Your PHP components subscribe to channels and react to
broadcasts, exactly like Laravel Echo does in the browser.

The websocket lives on the native side (Swift/Kotlin, via the official Pusher
SDKs); PHP just declares what to subscribe to and handles the events.

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
to what your server broadcasts — use `broadcastAs()` to keep it short. You can
also use the `#[Nativephp\Vibe\Attributes\OnEcho('OrderShipped')]` attribute
instead of the fluent `->on()`.

## Private channels

Private (`private-`) and presence (`presence-`) channels require a signed
authorization from **your remote Laravel backend** (`/broadcasting/auth`, guarded
by `auth:sanctum`). Point Vibe at it and give it the current bearer token:

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

Subscriptions are torn down automatically when the component unmounts.

## Notes

- Websockets are foreground-only on mobile (the OS suspends the socket in the
  background). For delivery while the app is closed, use push notifications.
- Websocket events signal *liveness*, not source of truth — on reconnect, refetch
  authoritative state.
