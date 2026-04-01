<?php

namespace ManticoreLaravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array resolveConfig(?string $connection = null)
 * @method static \Manticoresearch\Client client(?string $connection = null)
 * @method static \Manticoresearch\Table table(array|string $index, ?string $connection = null)
 * @method static array<int, string> connectionNames()
 * @method static void forgetClient(?string $connection = null)
 *
 * @see \ManticoreLaravel\Support\ManticoreManager
 */
class Manticore extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'manticore';
    }
}