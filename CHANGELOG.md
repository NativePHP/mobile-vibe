# Changelog

All notable changes to `nativephp/mobile-vibe` will be documented in this file.

## 1.0.0 — 2026-07-01

Initial release.

- Public, private and presence channels over the Pusher protocol (Vask,
  Laravel Reverb, Pusher) with the websocket living on the native side
  (PusherSwift / pusher-java-client).
- Channel-scoped event delivery (`vibe:event:<channel>:<name>`) — listeners on
  one channel never fire for another channel broadcasting the same event name.
- Fluent `->on()` listeners and the `#[OnEcho('Event', channel: '...')]`
  attribute, both auto-torn-down when the component unmounts.
- Presence rosters (`here` / `joining` / `leaving`) and client events
  (`whisper` / `listenForWhisper`).
- Refcounted native subscriptions: a channel closes when its last subscriber
  leaves; the socket tears down when no channels remain.
- Bearer-token auth for private/presence channels against a remote
  `/broadcasting/auth`, with runtime token resolution
  (`Vibe::resolveTokenUsing()`) and live refresh (`Vibe::withToken()`).
- Connection lifecycle hooks: `onReconnect()`, `onDisconnect()`, `onError()`
  (`vibe:reconnected` / `vibe:disconnected` / `vibe:error`).
- Fail-fast configuration errors: `VibeException` for missing `PUSHER_*`
  values or a missing `VIBE_AUTH_ENDPOINT` on private/presence subscribes.
