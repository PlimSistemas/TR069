<?php

namespace Plimsistemas\TR069\Tests\Unit\Device;

use Plimsistemas\TR069\Device\DeviceReading;
use Plimsistemas\TR069\Device\ParameterFetch;
use PHPUnit\Framework\TestCase;

class DeviceReadingTest extends TestCase
{
    public function test_typed_getters_cast_values(): void
    {
        $reading = new DeviceReading([
            ParameterFetch::UPTIME     => '1152602',
            ParameterFetch::TX_POWER   => '2.43',
            ParameterFetch::RX_POWER   => '-18.96',
            ParameterFetch::SW_VERSION => 'V9.3.10P7N8',
            ParameterFetch::HW_VERSION => 'V9.3.12',
            ParameterFetch::GPON_SN    => 'ZTEGDB18E99D',
        ]);

        $this->assertSame(1152602, $reading->getUptime());
        $this->assertSame(2.43, $reading->getTxPower());
        $this->assertSame(-18.96, $reading->getRxPower());
        $this->assertSame('V9.3.10P7N8', $reading->getSwVersion());
        $this->assertSame('V9.3.12', $reading->getHwVersion());
        $this->assertSame('ZTEGDB18E99D', $reading->getGponSn());
    }

    public function test_missing_keys_return_null(): void
    {
        $reading = new DeviceReading([ParameterFetch::UPTIME => 10]);

        $this->assertSame(10, $reading->getUptime());
        $this->assertNull($reading->getTxPower());
        $this->assertNull($reading->getRxPower());
        $this->assertNull($reading->getSwVersion());
        $this->assertNull($reading->getHwVersion());
    }

    public function test_generic_access_and_all(): void
    {
        $reading = new DeviceReading(['custom' => 42]);

        $this->assertTrue($reading->has('custom'));
        $this->assertFalse($reading->has('nope'));
        $this->assertSame(42, $reading->get('custom'));
        $this->assertNull($reading->get('nope'));
        $this->assertSame(['custom' => 42], $reading->all());
    }
}
