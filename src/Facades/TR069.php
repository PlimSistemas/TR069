<?php

namespace Plimsistemas\TR069\Facades;

use Illuminate\Support\Facades\Facade;
use Plimsistemas\TR069\Device\AbstractDevice;
use Plimsistemas\TR069\GenieACS\QueryBuilder;
use Plimsistemas\TR069\TR069Manager;

/**
 * @method static TR069Manager connection(array $config)
 * @method static AbstractDevice findBySerial(string $serial)
 * @method static AbstractDevice findByGponSn(string $gponSn)
 * @method static AbstractDevice find(string $deviceId)
 * @method static bool isOnline(string $deviceId, int $timeoutMs = 15000)
 * @method static bool exists(string $deviceId)
 * @method static bool existsBySerial(string $serial)
 * @method static bool existsByGponSn(string $gponSn)
 * @method static \Illuminate\Support\Collection devices(QueryBuilder $query)
 * @method static \Plimsistemas\TR069\GenieACS\Client client()
 * @method static \Plimsistemas\TR069\Device\DeviceRegistry registry()
 *
 * @see TR069Manager
 */
class TR069 extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TR069Manager::class;
    }
}
