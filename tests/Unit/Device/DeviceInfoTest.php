<?php

namespace Plimsistemas\TR069\Tests\Unit\Device;

use Plimsistemas\TR069\Device\DeviceInfo;
use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;
use PHPUnit\Framework\TestCase;

class DeviceInfoTest extends TestCase
{
    public function test_from_response_maps_all_fields(): void
    {
        $response = new DeviceResponse([
            '_id' => '202BC1-W5-1200F-ABC123',
            '_deviceId' => [
                '_Manufacturer' => 'Intelbras',
                '_OUI'          => '202BC1',
                '_ProductClass' => 'W5-1200F',
                '_SerialNumber' => 'ABC123',
            ],
            'InternetGatewayDevice' => [
                'DeviceInfo' => [
                    'SoftwareVersion' => ['_value' => '1.0.0'],
                ],
            ],
        ]);

        $info = DeviceInfo::fromResponse($response);

        $this->assertSame('202BC1-W5-1200F-ABC123', $info->id);
        $this->assertSame('Intelbras', $info->manufacturer);
        $this->assertSame('202BC1', $info->oui);
        $this->assertSame('W5-1200F', $info->productClass);
        $this->assertSame('ABC123', $info->serialNumber);
        $this->assertSame('1.0.0', $info->softwareVersion);
    }

    public function test_missing_string_fields_default_to_empty_string(): void
    {
        $info = DeviceInfo::fromResponse(new DeviceResponse([]));

        $this->assertSame('', $info->id);
        $this->assertSame('', $info->manufacturer);
        $this->assertSame('', $info->oui);
        $this->assertSame('', $info->productClass);
        $this->assertSame('', $info->serialNumber);
    }

    public function test_software_version_stays_null_when_absent(): void
    {
        // Unlike the string fields, softwareVersion is nullable and must
        // not be coerced to an empty string.
        $info = DeviceInfo::fromResponse(new DeviceResponse([]));

        $this->assertNull($info->softwareVersion);
    }

    public function test_get_parameter_delegates_to_the_response(): void
    {
        $response = new DeviceResponse([
            'Device' => [
                'WiFi' => [
                    'SSID' => ['1' => ['SSID' => ['_value' => 'MinhaRede']]],
                ],
            ],
        ]);

        $info = DeviceInfo::fromResponse($response);

        $this->assertSame('MinhaRede', $info->getParameter('Device.WiFi.SSID.1.SSID'));
    }

    public function test_raw_exposes_the_underlying_payload(): void
    {
        $payload  = ['_id' => 'x', '_deviceId' => ['_SerialNumber' => 'S1']];
        $info     = DeviceInfo::fromResponse(new DeviceResponse($payload));

        $this->assertSame($payload, $info->raw());
    }
}
