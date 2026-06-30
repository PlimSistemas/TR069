<?php

namespace Plimsistemas\TR069\Tests\Unit\GenieACS\Responses;

use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;
use PHPUnit\Framework\TestCase;

class DeviceResponseTest extends TestCase
{
    /**
     * A realistic GenieACS device payload (trimmed) using the
     * InternetGatewayDevice (TR-098) namespace.
     */
    private function igdPayload(): array
    {
        return [
            '_id' => '202BC1-BM632w-000000',
            '_deviceId' => [
                '_Manufacturer'  => 'Intelbras',
                '_OUI'           => '202BC1',
                '_ProductClass'  => 'W5-1200F',
                '_SerialNumber'  => 'ABC123',
            ],
            'InternetGatewayDevice' => [
                'DeviceInfo' => [
                    'SoftwareVersion' => ['_value' => '1.0.0'],
                ],
            ],
        ];
    }

    public function test_reads_top_level_and_device_id_fields(): void
    {
        $response = new DeviceResponse($this->igdPayload());

        $this->assertSame('202BC1-BM632w-000000', $response->getId());
        $this->assertSame('Intelbras', $response->getManufacturer());
        $this->assertSame('202BC1', $response->getOui());
        $this->assertSame('W5-1200F', $response->getProductClass());
        $this->assertSame('ABC123', $response->getSerialNumber());
    }

    public function test_software_version_read_from_internet_gateway_device_namespace(): void
    {
        $response = new DeviceResponse($this->igdPayload());

        $this->assertSame('1.0.0', $response->getSoftwareVersion());
    }

    public function test_software_version_falls_back_to_device_namespace(): void
    {
        // TR-181 style devices expose the version under "Device" instead.
        $response = new DeviceResponse([
            'Device' => [
                'DeviceInfo' => [
                    'SoftwareVersion' => ['_value' => 'V9.0.11P1N49'],
                ],
            ],
        ]);

        $this->assertSame('V9.0.11P1N49', $response->getSoftwareVersion());
    }

    public function test_software_version_is_null_when_absent(): void
    {
        $response = new DeviceResponse(['_id' => 'x']);

        $this->assertNull($response->getSoftwareVersion());
    }

    public function test_missing_fields_return_null(): void
    {
        $response = new DeviceResponse([]);

        $this->assertNull($response->getId());
        $this->assertNull($response->getManufacturer());
        $this->assertNull($response->getSerialNumber());
    }

    public function test_get_parameter_returns_value_leaf(): void
    {
        $response = new DeviceResponse($this->igdPayload());

        $this->assertSame(
            '1.0.0',
            $response->getParameter('InternetGatewayDevice.DeviceInfo.SoftwareVersion')
        );
    }

    public function test_get_parameter_returns_raw_node_when_no_value_leaf(): void
    {
        $response = new DeviceResponse($this->igdPayload());

        $this->assertSame(
            ['SoftwareVersion' => ['_value' => '1.0.0']],
            $response->getParameter('InternetGatewayDevice.DeviceInfo')
        );
    }

    public function test_get_parameter_returns_null_for_unknown_path(): void
    {
        $response = new DeviceResponse($this->igdPayload());

        $this->assertNull($response->getParameter('InternetGatewayDevice.Does.Not.Exist'));
    }

    public function test_raw_returns_the_original_array(): void
    {
        $payload  = $this->igdPayload();
        $response = new DeviceResponse($payload);

        $this->assertSame($payload, $response->raw());
    }
}
