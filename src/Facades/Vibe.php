<?php

namespace Nativephp\Vibe\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed execute(array $options = [])
 * @method static object|null getStatus()
 *
 * @see \Nativephp\Vibe\Vibe
 */
class Vibe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nativephp\Vibe\Vibe::class;
    }
}