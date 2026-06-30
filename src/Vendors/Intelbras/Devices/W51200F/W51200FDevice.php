<?php

namespace Plimsistemas\TR069\Vendors\Intelbras\Devices\W51200F;

use Plimsistemas\TR069\Device\AbstractDevice;
use Plimsistemas\TR069\GenieACS\Responses\TaskResponse;

/**
 * Intelbras W5-1200F — generic handler (all firmware versions).
 *
 * To add firmware-specific support, create a subclass in a sub-namespace
 * and register it in config/tr069.php with the exact firmware version.
 *
 * Example parameter keys:
 *   wifi.2g.ssid, wifi.2g.password, wifi.2g.channel, wifi.2g.enabled
 *   wifi.5g.ssid, wifi.5g.password, wifi.5g.channel, wifi.5g.enabled
 *   pppoe.username, pppoe.password
 *   device.uptime, device.firmware
 */
class W51200FDevice extends AbstractDevice
{
    public function vendor(): string
    {
        return 'Intelbras';
    }

    public function model(): string
    {
        return 'W5-1200F';
    }

    public function firmwareVersion(): string
    {
        return $this->deviceInfo->softwareVersion ?? '*';
    }

    protected function parameterMap(): array
    {
        return [
            // Device info
            'device.firmware'      => 'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'device.hardware'      => 'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'device.manufacturer'  => 'InternetGatewayDevice.DeviceInfo.Manufacturer',
            'device.model'         => 'InternetGatewayDevice.DeviceInfo.ModelName',
            'device.serial'        => 'InternetGatewayDevice.DeviceInfo.SerialNumber',
            'device.uptime'        => 'InternetGatewayDevice.DeviceInfo.UpTime',

            // 2.4 GHz Wi-Fi
            'wifi.2g.enabled'      => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
            'wifi.2g.ssid'         => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'wifi.2g.password'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
            'wifi.2g.channel'      => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel',
            'wifi.2g.security'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
            'wifi.2g.encryption'   => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.IEEE11iEncryptionModes',

            // 5 GHz Wi-Fi
            'wifi.5g.enabled'      => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Enable',
            'wifi.5g.ssid'         => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
            'wifi.5g.password'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
            'wifi.5g.channel'      => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Channel',
            'wifi.5g.security'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.BeaconType',
            'wifi.5g.encryption'   => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.IEEE11iEncryptionModes',

            // WAN / PPPoE
            'wan.connection.type'     => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionType',
            'wan.connection.status'   => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionStatus',
            'wan.pppoe.username'      => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'wan.pppoe.password'      => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password',
            'wan.ip'                  => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'wan.gateway'             => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.DefaultGateway',
            'wan.dns.primary'         => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.DNSServers',

            // LAN
            'lan.ip'                  => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceIPAddress',
            'lan.subnet'              => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceSubnetMask',
            'lan.dhcp.enabled'        => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.DHCPServerEnable',
            'lan.dhcp.start'          => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MinAddress',
            'lan.dhcp.end'            => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MaxAddress',
        ];
    }

    // -------------------------------------------------------------------------
    // Convenience methods for this model
    // -------------------------------------------------------------------------

    public function getWifi2gSsid(): ?string
    {
        return $this->get('wifi.2g.ssid');
    }

    public function setWifi2gSsid(string $ssid): TaskResponse
    {
        return $this->set('wifi.2g.ssid', $ssid);
    }

    public function getWifi5gSsid(): ?string
    {
        return $this->get('wifi.5g.ssid');
    }

    public function setWifi5gSsid(string $ssid): TaskResponse
    {
        return $this->set('wifi.5g.ssid', $ssid);
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

    public function getWanIp(): ?string
    {
        return $this->get('wan.ip');
    }
}
