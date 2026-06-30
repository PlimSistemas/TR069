<?php

namespace Plimsistemas\TR069\Vendors\ZTE\Devices\F670L;

use Plimsistemas\TR069\Vendors\ZTE\ZTEDevice;
use Plimsistemas\TR069\GenieACS\Responses\TaskResponse;

/**
 * ZTE F670L — handler de modelo (wildcard de firmware).
 *
 * Camada MODELO: herda os padrões ZTE de {@see ZTEDevice} (uptime, gpon_sn,
 * ópticas, device.*) e adiciona os parâmetros específicos do F670L. Classes
 * de firmware específico herdam esta e sobrescrevem só o que muda no firmware.
 *
 * Chaves adicionais:
 *   Wi-Fi 2.4GHz : wifi.2g.enabled, wifi.2g.ssid, wifi.2g.password, wifi.2g.channel, wifi.2g.mode
 *   Wi-Fi 5GHz   : wifi.5g.enabled, wifi.5g.ssid, wifi.5g.password, wifi.5g.channel, wifi.5g.mode
 *   WAN/PPPoE    : wan.pppoe.username, wan.pppoe.password, wan.connection.status, wan.ip, wan.dns
 *   LAN/DHCP     : lan.ip, lan.subnet, lan.dhcp.enabled, lan.dhcp.start, lan.dhcp.end, lan.dhcp.lease
 *   VOIP         : voip.sip.server, voip.sip.username, voip.sip.password, voip.sip.port
 */
class F670LDevice extends ZTEDevice
{
    public function model(): string
    {
        return 'F670L';
    }

    public function firmwareVersion(): string
    {
        return $this->deviceInfo->softwareVersion ?? '*';
    }

    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // ----------------------------------------------------------------
            // Informações extras do dispositivo (além das herdadas da marca)
            // ----------------------------------------------------------------
            'device.manufacturer' => 'InternetGatewayDevice.DeviceInfo.Manufacturer',
            'device.model'        => 'InternetGatewayDevice.DeviceInfo.ModelName',
            'device.description'  => 'InternetGatewayDevice.DeviceInfo.Description',

            // ----------------------------------------------------------------
            // Wi-Fi 2.4 GHz (WLANConfiguration.1)
            // ----------------------------------------------------------------
            'wifi.2g.enabled'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable',
            'wifi.2g.ssid'        => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'wifi.2g.password'    => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
            'wifi.2g.channel'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Channel',
            'wifi.2g.auto_channel'=> 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AutoChannelEnable',
            'wifi.2g.mode'        => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Standard',
            'wifi.2g.security'    => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BeaconType',
            'wifi.2g.bandwidth'   => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.OperatingChannelBandwidth',
            'wifi.2g.mac'         => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BSSID',
            'wifi.2g.tx_power'    => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.TransmitPower',

            // ----------------------------------------------------------------
            // Wi-Fi 5 GHz (WLANConfiguration.5)
            // ----------------------------------------------------------------
            'wifi.5g.enabled'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Enable',
            'wifi.5g.ssid'        => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.SSID',
            'wifi.5g.password'    => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.PreSharedKey.1.KeyPassphrase',
            'wifi.5g.channel'     => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Channel',
            'wifi.5g.auto_channel'=> 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.AutoChannelEnable',
            'wifi.5g.mode'        => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.Standard',
            'wifi.5g.security'    => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.BeaconType',
            'wifi.5g.bandwidth'   => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.OperatingChannelBandwidth',
            'wifi.5g.mac'         => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.BSSID',
            'wifi.5g.tx_power'    => 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.TransmitPower',

            // ----------------------------------------------------------------
            // WAN / PPPoE
            // ----------------------------------------------------------------
            'wan.connection.status'  => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionStatus',
            'wan.connection.type'    => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionType',
            'wan.pppoe.username'     => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'wan.pppoe.password'     => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password',
            'wan.ip'                 => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
            'wan.gateway'            => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.DefaultGateway',
            'wan.dns'                => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.DNSServers',
            'wan.uptime'             => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Uptime',
            'wan.mac'                => 'InternetGatewayDevice.WANDevice.1.WANEthernetInterfaceConfig.1.MACAddress',


            // ----------------------------------------------------------------
            // VoIP / SIP (porta ATA integrada)
            // ----------------------------------------------------------------
            'voip.sip.server'        => 'InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.1.SIP.ProxyServer',
            'voip.sip.port'          => 'InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.1.SIP.ProxyServerPort',
            'voip.sip.username'      => 'InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.1.Line.1.SIP.AuthUserName',
            'voip.sip.password'      => 'InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.1.Line.1.SIP.AuthPassword',
            'voip.sip.uri'           => 'InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.1.Line.1.SIP.URI',
        ]);
    }

    // -------------------------------------------------------------------------
    // Métodos de conveniência
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

    /**
     * Altera SSID e senha de 2.4GHz e 5GHz em uma única task.
     */
    public function setAllWifiCredentials(
        string $ssid2g,
        string $password2g,
        string $ssid5g,
        string $password5g
    ): TaskResponse {
        return $this->setMany([
            'wifi.2g.ssid'     => $ssid2g,
            'wifi.2g.password' => $password2g,
            'wifi.5g.ssid'     => $ssid5g,
            'wifi.5g.password' => $password5g,
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

    public function isWanConnected(): bool
    {
        return strtolower((string) $this->getWanStatus()) === 'connected';
    }
}
