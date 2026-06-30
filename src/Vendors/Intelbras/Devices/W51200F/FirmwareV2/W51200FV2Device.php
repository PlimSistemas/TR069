<?php

namespace Plimsistemas\TR069\Vendors\Intelbras\Devices\W51200F\FirmwareV2;

use Plimsistemas\TR069\Vendors\Intelbras\Devices\W51200F\W51200FDevice;

/**
 * Intelbras W5-1200F — firmware 2.x.
 *
 * Override only the parameters that changed in this firmware version.
 * All others are inherited from W51200FDevice.
 */
class W51200FV2Device extends W51200FDevice
{
    public function firmwareVersion(): string
    {
        return '2.*';
    }

    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // Example: firmware v2 moved the 5GHz config to a different index
            'wifi.5g.ssid'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.6.SSID',
            'wifi.5g.password' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.6.KeyPassphrase',
            'wifi.5g.channel'  => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.6.Channel',
            'wifi.5g.enabled'  => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.6.Enable',
        ]);
    }
}
