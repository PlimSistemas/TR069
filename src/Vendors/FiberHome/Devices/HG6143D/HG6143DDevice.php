<?php

namespace Plimsistemas\TR069\Vendors\FiberHome\Devices\HG6143D;

use Plimsistemas\TR069\Vendors\FiberHome\FiberHomeDevice;
use Plimsistemas\TR069\GenieACS\Responses\TaskResponse;

/**
 * FiberHome HG6143D — handler de modelo (wildcard de firmware).
 *
 * Camada MODELO: herda os padrões FiberHome de {@see FiberHomeDevice}
 * (uptime, gpon_sn=SerialNumber, ópticas, device.*) e adiciona o que é
 * específico do HG6143D (wifi/wan/lan).
 *
 * ⚠️ Índices de WLANConfiguration / paths de wifi validar contra device real.
 *
 * Chaves adicionais:
 *   Wi-Fi 2.4GHz : wifi.2g.enabled, wifi.2g.ssid, wifi.2g.password, wifi.2g.channel
 *   Wi-Fi 5GHz   : wifi.5g.enabled, wifi.5g.ssid, wifi.5g.password, wifi.5g.channel
 *   WAN/PPPoE    : wan.pppoe.username, wan.pppoe.password, wan.connection.status, wan.ip
 *   LAN/DHCP     : lan.ip, lan.subnet, lan.dhcp.enabled
 */
class HG6143DDevice extends FiberHomeDevice
{
    public function model(): string
    {
        return 'HG6143D';
    }

    public function firmwareVersion(): string
    {
        return $this->deviceInfo->softwareVersion ?? '*';
    }

    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // Informações extras (além das herdadas da marca)
            'device.manufacturer'    => 'InternetGatewayDevice.DeviceInfo.Manufacturer',
            'device.model'           => 'InternetGatewayDevice.DeviceInfo.ModelName',

            // Wi-Fi 2.4 GHz (WLANConfiguration.1)
            'wifi.2g.enabled'        => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
            'wifi.2g.ssid'           => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'wifi.2g.password'       => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
            'wifi.2g.channel'        => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel',
            'wifi.2g.security'       => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',

            // Wi-Fi 5 GHz (WLANConfiguration.5)
            'wifi.5g.enabled'        => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Enable',
            'wifi.5g.ssid'           => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
            'wifi.5g.password'       => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase',
            'wifi.5g.channel'        => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Channel',
            'wifi.5g.security'       => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.BeaconType',

            // WAN / PPPoE
            'wan.connection.status'  => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionStatus',
            'wan.pppoe.username'     => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'wan.pppoe.password'     => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password',
            'wan.ip'                 => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'wan.gateway'            => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.DefaultGateway',

            // LAN / DHCP
            'lan.ip'                 => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceIPAddress',
            'lan.subnet'             => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceSubnetMask',
            'lan.dhcp.enabled'       => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.DHCPServerEnable',
            'lan.dhcp.start'         => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MinAddress',
            'lan.dhcp.end'           => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MaxAddress',
        ]);
    }

    // -------------------------------------------------------------------------
    // Métodos de conveniência
    // -------------------------------------------------------------------------

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

    public function setPppoeCredentials(string $username, string $password): TaskResponse
    {
        return $this->setMany([
            'wan.pppoe.username' => $username,
            'wan.pppoe.password' => $password,
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
