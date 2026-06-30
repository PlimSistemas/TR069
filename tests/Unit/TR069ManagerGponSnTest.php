<?php

namespace Plimsistemas\TR069\Tests\Unit;

use Plimsistemas\TR069\Device\DeviceRegistry;
use Plimsistemas\TR069\Exceptions\DeviceNotFoundException;
use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\GenieACS\QueryBuilder;
use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;
use Plimsistemas\TR069\TR069Manager;
use Plimsistemas\TR069\Vendors\ZTE\Devices\F6201B\F6201BDevice;
use Plimsistemas\TR069\Vendors\ZTE\ZTEVendor;
use PHPUnit\Framework\TestCase;

class TR069ManagerGponSnTest extends TestCase
{
    private function clientReturning(array $devices): Client
    {
        return new class(['base_url' => 'http://localhost'], $devices) extends Client {
            public array $lastParams = [];

            public function __construct(array $config, private array $devices)
            {
                parent::__construct($config);
            }

            public function searchDevices(QueryBuilder $query): array
            {
                $this->lastParams = $query->toQueryParams();
                return $this->devices;
            }
        };
    }

    public function test_find_by_gpon_sn_queries_virtual_param_and_resolves_handler(): void
    {
        $response = new DeviceResponse([
            '_id'       => '0C014B-F6201B-ZTE0QT1R9X12701',
            '_deviceId' => [
                '_Manufacturer' => 'ZTE',
                '_ProductClass' => 'F6201B',
                '_SerialNumber' => 'ZTE0QT1R9X12701',
            ],
            'InternetGatewayDevice' => [
                'DeviceInfo' => ['SoftwareVersion' => ['_value' => 'V9.3.10P7N8']],
            ],
        ]);

        $client   = $this->clientReturning([$response]);
        $registry = new DeviceRegistry(['zte' => ['F6201B' => ['*' => F6201BDevice::class]]]);
        $manager  = new TR069Manager($client, $registry);
        $manager->registerVendor(new ZTEVendor());

        $device = $manager->findByGponSn('ZTEGdb18e99d');

        $this->assertInstanceOf(F6201BDevice::class, $device);

        $query = json_decode($client->lastParams['query'], true);
        $this->assertArrayHasKey('VirtualParameters.GponSN', $query);
        $this->assertSame('^ZTEGdb18e99d$', $query['VirtualParameters.GponSN']['$regex']);
    }

    public function test_find_by_gpon_sn_throws_when_not_found(): void
    {
        $manager = new TR069Manager($this->clientReturning([]), new DeviceRegistry());

        $this->expectException(DeviceNotFoundException::class);
        $this->expectExceptionMessage('GPON SN');
        $manager->findByGponSn('INEXISTENTE');
    }
}
