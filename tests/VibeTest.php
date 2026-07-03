<?php

/**
 * Behavioral tests for the pure-PHP surface of Vibe. These run without the
 * native runtime (nativephp_call is undefined), where subscribe() skips the
 * bridge and config entirely — so channel naming, scoping, and listener
 * guards are testable in isolation.
 */

use NativePHP\Vibe\Attributes\OnEcho;
use NativePHP\Vibe\Exceptions\VibeException;
use NativePHP\Vibe\PendingSubscription;
use NativePHP\Vibe\Vibe;

describe('Channel naming', function () {
    it('subscribes public channels by bare name', function () {
        expect((new Vibe)->channel('orders')->channel())->toBe('orders');
    });

    it('prefixes private channels', function () {
        expect((new Vibe)->private('orders.42')->channel())->toBe('private-orders.42');
    });

    it('prefixes presence channels', function () {
        expect((new Vibe)->presence('room.1')->channel())->toBe('presence-room.1');
    });
});

describe('Listener guards', function () {
    it('throws when a listener is registered outside a NativeComponent', function () {
        (new PendingSubscription('orders'))->on('OrderShipped', static fn ($e) => null);
    })->throws(VibeException::class, 'NativeComponent');

    it('throws for presence listeners outside a NativeComponent', function () {
        (new PendingSubscription('presence-room.1'))->here(static fn (array $members) => null);
    })->throws(VibeException::class);

    it('throws for lifecycle hooks outside a NativeComponent', function () {
        (new PendingSubscription('orders'))->onReconnect(static fn () => null);
    })->throws(VibeException::class);
});

describe('OnEcho attribute', function () {
    it('scopes the event name to the channel', function () {
        $attribute = new OnEcho('OrderShipped', channel: 'orders');

        expect($attribute->event)->toBe('vibe:event:orders:OrderShipped');
    })->skip(
        ! class_exists(\Native\Mobile\Attributes\On::class),
        'requires nativephp/mobile with the Attributes\\On class'
    );
});
