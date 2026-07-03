<?php

namespace NativePHP\Vibe\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \NativePHP\Vibe\PendingSubscription channel(string $name)
 * @method static \NativePHP\Vibe\PendingSubscription private(string $name)
 * @method static \NativePHP\Vibe\PendingSubscription presence(string $name)
 * @method static \NativePHP\Vibe\PendingSubscription subscribe(string $channel)
 * @method static void unsubscribe(string $channel)
 * @method static void trigger(string $channel, string $event, array $data = [])
 * @method static void resolveTokenUsing(\Closure $resolver)
 * @method static void withToken(string $token)
 *
 * @see \NativePHP\Vibe\Vibe
 */
class Vibe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NativePHP\Vibe\Vibe::class;
    }
}
