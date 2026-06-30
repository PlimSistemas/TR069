<?php

namespace Plimsistemas\TR069\Tests\Unit\Device;

use Plimsistemas\TR069\Device\AbstractDevice;
use Plimsistemas\TR069\Device\DeviceInfo;
use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;
use Plimsistemas\TR069\Vendors\FiberHome\Devices\HG6143D\HG6143DDevice;
use Plimsistemas\TR069\Vendors\ZTE\Devices\F6201B\F6201BDevice;
use PHPUnit\Framework\TestCase;

/**
 * Camada base (MARCA) fictícia para provar a precedência do merge.
 */
abstract class _VendorBase extends AbstractDevice
{
    public function vendor(): string { return 'V'; }
    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            'shared'      => 'vendor.path',
            'vendor_only' => 'vendor.only',
        ]);
    }
}

abstract class _ModelMid extends _VendorBase
{
    public function model(): string { return 'M'; }
    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            'shared'     => 'model.path',   // sobrescreve a marca
            'model_only' => 'model.only',
        ]);
    }
}

class _FirmwareLeaf extends _ModelMid
{
    public function firmwareVersion(): string { return '1'; }
    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            'shared' => 'firmware.path',    // sobrescreve modelo e marca
        ]);
    }
}

class LayeredParameterMapTest extends TestCase
{
    private function info(): DeviceInfo
    {
        return new DeviceInfo('id', 'V', 'OUI', 'M', 'SER', '1', new DeviceResponse([]));
    }

    private function client(): Client
    {
        return new Client(['base_url' => 'http://localhost']);
    }

    public function test_more_specific_layer_wins(): void
    {
        $device = new _FirmwareLeaf($this->info(), $this->client());

        // firmware vence marca e modelo
        $this->assertSame('firmware.path', $device->pathFor('shared'));
        // chaves exclusivas de cada camada continuam acessíveis
        $this->assertSame('model.only', $device->pathFor('model_only'));
        $this->assertSame('vendor.only', $device->pathFor('vendor_only'));
    }

    public function test_zte_vendor_default_gpon_sn(): void
    {
        $device = new F6201BDevice($this->info(), $this->client());

        $this->assertSame(
            'InternetGatewayDevice.DeviceInfo.X_ZTE-COM_GPONSN',
            $device->pathFor('gpon_sn')
        );
    }

    public function test_fiberhome_vendor_uses_serial_number_as_gpon_sn(): void
    {
        $device = new HG6143DDevice($this->info(), $this->client());

        $this->assertSame(
            'InternetGatewayDevice.DeviceInfo.SerialNumber',
            $device->pathFor('gpon_sn')
        );
    }

    public function test_model_inherits_vendor_and_adds_own_paths(): void
    {
        $device = new F6201BDevice($this->info(), $this->client());

        // herdado da MARCA (ZTEDevice)
        $this->assertSame(
            'InternetGatewayDevice.DeviceInfo.X_ZTE-COM_GPONSN',
            $device->pathFor('gpon_sn')
        );
        // definido no MODELO (F6201BDevice)
        $this->assertSame(
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            $device->pathFor('wifi.2g.ssid')
        );
        // chave não mapeada
        $this->assertNull($device->pathFor('inexistente'));
    }
}
