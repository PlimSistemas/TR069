<?php

namespace Plimsistemas\TR069\Tests\Unit\GenieACS;

use Plimsistemas\TR069\GenieACS\QueryBuilder;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase
{
    public function test_serial_query_produces_correct_params(): void
    {
        $params = QueryBuilder::make()
            ->whereSerial('ABC123')
            ->projectDeviceId()
            ->toQueryParams();

        $query = json_decode($params['query'], true);

        $this->assertSame('^ABC123$', $query['_deviceId._SerialNumber']['$regex']);
        $this->assertSame('i', $query['_deviceId._SerialNumber']['$options']);
        $this->assertSame('_deviceId', $params['projection']);
    }

    public function test_projection_deduplicates_fields(): void
    {
        $params = QueryBuilder::make()
            ->project('_deviceId', '_deviceId')
            ->toQueryParams();

        $this->assertSame('_deviceId', $params['projection']);
    }

    public function test_gpon_sn_query_targets_virtual_parameter(): void
    {
        $params = QueryBuilder::make()
            ->whereGponSn('ZTEGdb18e99d')
            ->projectGponSn()
            ->toQueryParams();

        $query = json_decode($params['query'], true);

        $this->assertSame('^ZTEGdb18e99d$', $query['VirtualParameters.GponSN']['$regex']);
        $this->assertSame('i', $query['VirtualParameters.GponSN']['$options']);
        $this->assertSame('VirtualParameters.GponSN', $params['projection']);
    }
}
