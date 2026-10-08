<?php

namespace Vipertecpro\PausewallUsage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Vipertecpro\PausewallUsage\Enums\PermissionStatus permission()
 * @method static bool requestPermission()
 * @method static \Illuminate\Support\Collection<int, \Vipertecpro\PausewallUsage\Data\UsageDay> usage(int $days = 1)
 * @method static \Vipertecpro\PausewallUsage\Data\UsageDay today(int $limit = 10)
 * @method static bool isAvailable()
 * @method static ?string lastReason()
 *
 * @see \Vipertecpro\PausewallUsage\PausewallUsage
 */
class PausewallUsage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vipertecpro\PausewallUsage\PausewallUsage::class;
    }
}
