<?php

namespace Plimsistemas\TR069\Vendors\ZTE\Devices\F6201B;

use Plimsistemas\TR069\Vendors\ZTE\ZTEDevice;
use Plimsistemas\TR069\GenieACS\Responses\TaskResponse;

/**
 * ZTE F6201B — ONT GPON (handler de modelo, wildcard de firmware).
 *
 * Camada MODELO: herda os padrões ZTE de {@see ZTEDevice} (uptime, gpon_sn,
 * ópticas, etc.) e adiciona apenas o que é específico do F6201B.
 * Paths validados contra device real (0C014B-F6201B-…, firmware V9.3.10P7N8).
 */
class F6201BDevice extends ZTEDevice
{
    public function model(): string
    {
        return 'F6201B';
    }

    public function firmwareVersion(): string
    {
        return $this->deviceInfo->softwareVersion ?? '*';
    }

    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // Wi-Fi 2.4 GHz
            'wifi.2g.ssid'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'wifi.2g.password' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',

            // Wi-Fi 5 GHz
            'wifi.5g.ssid'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
            'wifi.5g.password' => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase',

            // WAN / PPPoE
            'wan.connection.status' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionStatus',
            'wan.pppoe.username'    => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'wan.pppoe.password'    => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password',
            'wan.ip'                => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
        ]);
    }

    public function setWifi2gCredentials(string $ssid, string $password): TaskResponse
    {
        return $this->setMany([
            'wifi.2g.ssid'     => $ssid,
            'wifi.2g.password' => $password,
        ]);
    }

    public function setWifi5gCredentials(string $ssid, string $password): TaskResponse
    {
        return $this->setMany([
            'wifi.5g.ssid'     => $ssid,
            'wifi.5g.password' => $password,
        ]);
    }

    public function getWanStatus(): ?string
    {
        return $this->get('wan.connection.status');
    }

    public function isWanConnected(): bool
    {
        return strtolower((string) $this->getWanStatus()) === 'connected';
    }
}
