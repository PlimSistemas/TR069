<?php

namespace Plimsistemas\TR069\Vendors\ZTE\Devices\F6600P;

use Plimsistemas\TR069\Vendors\ZTE\ZTEDevice;

/**
 * ZTE F6600P — handler de modelo (wildcard de firmware).
 *
 * Camada MODELO: herda os padrões ZTE de {@see ZTEDevice} (uptime, gpon_sn,
 * ópticas, device.*, lan.isp_dns) e os paths LAN/DHCP genéricos do
 * AbstractDevice. O F6600P usa o namespace InternetGatewayDevice padrão e
 * expõe LANEthernetInterfaceConfig.* e Hosts.Host.* nos paths default, então
 * getLanConfig()/getLanPorts()/getHosts() funcionam sem overrides.
 *
 * Parâmetros específicos (Wi-Fi, WAN/PPPoE, VoIP) podem ser adicionados aqui
 * via array_merge(parent::parameterMap(), [...]) conforme forem homologados.
 */
class F6600PDevice extends ZTEDevice
{
    public function model(): string
    {
        return 'F6600P';
    }

    public function firmwareVersion(): string
    {
        return $this->deviceInfo->softwareVersion ?? '*';
    }
}
