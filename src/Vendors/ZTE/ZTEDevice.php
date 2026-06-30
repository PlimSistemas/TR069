<?php

namespace Plimsistemas\TR069\Vendors\ZTE;

use Plimsistemas\TR069\Device\AbstractDevice;

/**
 * Camada MARCA (ZTE) da resolução de parâmetros.
 *
 * Define os paths TR-069 PADRÃO de todo equipamento ZTE. Modelos estendem
 * esta classe e sobrescrevem apenas o que diferir; firmwares específicos
 * estendem o modelo e sobrescrevem o que mudar naquele firmware.
 *
 * Precedência: ZTEDevice (marca) < {Model}Device (modelo) < firmware.
 */
abstract class ZTEDevice extends AbstractDevice
{
    public function vendor(): string
    {
        return 'ZTE';
    }

    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // Chaves canônicas (genéricas) — padrão ZTE
            'uptime'           => 'InternetGatewayDevice.DeviceInfo.UpTime',
            'sw_version'       => 'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'hw_version'       => 'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'gpon_sn'          => 'InternetGatewayDevice.DeviceInfo.X_ZTE-COM_GPONSN',
            'optical.tx_power' => 'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.TXPower',
            'optical.rx_power' => 'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower',

            // DeviceInfo
            'device.serial'    => 'InternetGatewayDevice.DeviceInfo.SerialNumber',
            'device.uptime'    => 'InternetGatewayDevice.DeviceInfo.UpTime',
            'device.firmware'  => 'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'device.hardware'  => 'InternetGatewayDevice.DeviceInfo.HardwareVersion',

            // LAN
            'lan.isp_dns'      => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.X_ZTE-COM_IspDNS', // true|false

        ]);
    }

    /**
     * Acrescenta o `isp_dns` (proprietário ZTE) à configuração LAN canônica.
     * A leaf é relativa ao objeto LANHostConfigManagement de lanConfigPath().
     */
    protected function lanConfigMap(): array
    {
        return array_merge(parent::lanConfigMap(), [
            'isp_dns' => 'X_ZTE-COM_IspDNS', // true|false
        ]);
    }

    /**
     * VLAN/COS da WAN (proprietários ZTE) — leaves no nível da própria conexão.
     */
    protected function wanConnectionMap(): array
    {
        return array_merge(parent::wanConnectionMap(), [
            'service' => 'X_ZTE-COM_ServiceList',
            'vlan'    => 'X_ZTE-COM_VLANID',
            'cos'     => 'X_ZTE-COM_8021P',
        ]);
    }

    /**
     * Extensões de voz proprietárias ZTE (vão para $extra do VoiceProfile):
     * proxy/registrar standby, marca de VLAN/prioridade do RTP e DTMF — ZTE usa
     * DTMFMethod=InBand + DTMFMethodG711=RFC2833 (FiberHome usa só DTMFMethod).
     */
    protected function voiceProfileMap(): array
    {
        return array_merge(parent::voiceProfileMap(), [
            'dtmf_method'       => 'DTMFMethod',
            'dtmf_method_g711'  => 'DTMFMethodG711',
            'standby_proxy'     => 'SIP.X_ZTE-COM_Standby-ProxyServer',
            'standby_registrar' => 'SIP.X_ZTE-COM_Standby-RegistrarServer',
            'rtp_vlan'          => 'RTP.VLANIDMark',
        ]);
    }

    /**
     * Funções suplementares da linha (TR-104 CallingFeatures) — ricas na ZTE.
     * Vão para $extra do VoiceLine.
     */
    protected function voiceLineMap(): array
    {
        return array_merge(parent::voiceLineMap(), [
            'call_waiting' => 'CallingFeatures.CallWaitingEnable',
            'caller_id'    => 'CallingFeatures.CallerIDEnable',
            'mwi'          => 'CallingFeatures.MWIEnable',
        ]);
    }

    /**
     * Extensões de Wi-Fi proprietárias ZTE (vão para $extra do WifiNetwork):
     * banda/largura de canal e nº máx. de clientes. Variam por firmware (ex.:
     * F670L usa `BandWidth` em vez de X_ZTE-COM_OperatingChannelBandwidth) — leaf
     * ausente vira null.
     */
    protected function wifiNetworkMap(): array
    {
        return array_merge(parent::wifiNetworkMap(), [
            'freq_band'         => 'X_ZTE-COM_OperatingFrequencyBand',
            'bandwidth'         => 'X_ZTE-COM_OperatingChannelBandwidth', // largura EM USO (read-only)
            'channel_width'     => 'BandWidth',                           // largura CONFIGURADA (Auto/20MHz/...)
            'extension_channel' => 'X_ZTE-COM_ExtensionChannel',          // Below/AboveControlChannel
            'beacon_interval'   => 'X_ZTE-COM_BeaconPeriod',
            'max_clients'       => 'X_ZTE-COM_MaximumClients',
        ]);
    }

    /**
     * Modos de segurança suportados pela ZTE (combinação fixa BeaconType + cifra +
     * auth). Mapa: modo canônico → [BeaconType, EncryptionModes, AuthMode]. AuthMode
     * `null` = não escreve (rede aberta). wpa2-psk/wpa-wpa2-psk e open são derivados
     * de 3 capturas reais (F6600P). Nos dumps `IEEE11iAuthenticationMode` é sempre
     * `PSKAuthentication` e o `WPA3AuthenticationMode` (estático) é `SAEAuthentication`
     * — ou seja, o WPA3 é selecionado só pelo `BeaconType` (`WPA3`/`11iandWPA3`),
     * mantendo o auth 11i como PSK. WPA3 ainda a confirmar na bancada.
     */
    private const WIFI_SECURITY_MODES = [
        'open'          => ['None', null, null],
        'wpa2-psk'      => ['11i', 'AESEncryption', 'PSKAuthentication'],              // WPA2-PSK / AES
        'wpa-wpa2-psk'  => ['WPAand11i', 'TKIPandAESEncryption', 'PSKAuthentication'],  // WPA/WPA2-PSK-TKIP / AES
        'wpa3-sae'      => ['WPA3', 'AESEncryption', 'PSKAuthentication'],             // WPA3 (SAE)
        'wpa2-wpa3-sae' => ['11iandWPA3', 'TKIPandAESEncryption', 'PSKAuthentication'], // WPA2-PSK(TKIP/AES) / WPA3(SAE)
    ];

    /**
     * Escrita do SSID (ZTE). Derivado da comparação de capturas reais (F6600P):
     *
     *  - Nome      → SSID + Name (mesmo valor)
     *  - Segurança → BeaconType/EncryptionModes/AuthMode via WIFI_SECURITY_MODES
     *  - Senha     → KeyPassphrase + PreSharedKey.1.KeyPassphrase
     *  - Ocultar   → SSIDAdvertisementEnabled (invertido)
     *  - Máx users → X_ZTE-COM_MaxUserNum + X_ZTE-COM_MaximumClients
     *
     * "Isolar usuários" não é exposto via TR-069 nesta linha ZTE → ignorado.
     */
    protected function buildWifiSsidWrite(array $v): array
    {
        $pairs = [];

        if (isset($v['ssid']) && $v['ssid'] !== '') {
            $pairs['SSID'] = (string) $v['ssid'];
            $pairs['Name'] = (string) $v['ssid'];
        }

        if (array_key_exists('hide_ssid', $v) && $v['hide_ssid'] !== null) {
            $pairs['SSIDAdvertisementEnabled'] = ! (bool) $v['hide_ssid'];
        }

        if (isset($v['max_users']) && $v['max_users'] !== null) {
            $pairs['X_ZTE-COM_MaxUserNum']     = (int) $v['max_users'];
            $pairs['X_ZTE-COM_MaximumClients'] = (int) $v['max_users'];
        }

        $security = $v['security'] ?? null;
        if ($security !== null && isset(self::WIFI_SECURITY_MODES[$security])) {
            [$beacon, $enc, $auth] = self::WIFI_SECURITY_MODES[$security];

            $pairs['BeaconType'] = $beacon;

            if ($enc !== null) {
                $pairs['IEEE11iEncryptionModes']    = $enc;
                $pairs['WPAEncryptionModes']        = $enc;
                $pairs['IEEE11iAuthenticationMode'] = $auth;
                $pairs['WPAAuthenticationMode']     = $auth;

                if (isset($v['password']) && $v['password'] !== '') {
                    $pairs['KeyPassphrase']                = (string) $v['password'];
                    $pairs['PreSharedKey.1.KeyPassphrase'] = (string) $v['password'];
                }
            }
        }

        return $pairs;
    }

    /**
     * Instâncias de WLANConfiguration por banda na ZTE (F6600P e linha atual):
     * 1-4 = 2.4 GHz, 5-8 = 5 GHz. `RadioEnabled` é nível-rádio (espelha nas 4
     * instâncias da banda) — confirmado em 3 capturas reais (wifi1/2/3.json).
     */
    private const WIFI_RADIO_INSTANCES = [
        'radio_24' => [1, 2, 3, 4],
        'radio_5'  => [5, 6, 7, 8],
    ];

    /**
     * Liga/desliga o rádio por banda escrevendo `RadioEnabled` em todas as
     * instâncias daquela banda (paths completos).
     */
    protected function buildWifiRadioWrite(array $v): array
    {
        $root  = $this->wifiRootPath();
        $pairs = [];

        foreach (self::WIFI_RADIO_INSTANCES as $key => $instances) {
            if (! array_key_exists($key, $v) || $v[$key] === null) {
                continue;
            }

            foreach ($instances as $i) {
                $pairs["{$root}.{$i}.RadioEnabled"] = (bool) $v[$key];
            }
        }

        return $pairs;
    }

    /**
     * Config avançada do rádio por banda (ZTE). Escreve só na instância PRINCIPAL
     * da banda (1 = 2.4 GHz, 5 = 5 GHz) — confirmado por captura: estes parâmetros
     * são nível-rádio e só existem/mudam na principal (≠ RadioEnabled que espelha).
     *
     *  - power             → TransmitPower (%)
     *  - bandwidth         → BandWidth (Auto/20MHz/40MHz/80MHz/160MHz)
     *  - channel (0=auto)  → AutoChannelEnable (+ Channel quando fixo)
     *  - extension_channel → X_ZTE-COM_ExtensionChannel (below/above)
     *  - beacon_interval   → X_ZTE-COM_BeaconPeriod
     *  - region            → RegulatoryDomain (códigos ZTE: BRI, USI, CAI...)
     *
     * `Standard` (modo) e SGI não são graváveis via TR-069 neste modelo → ignorados.
     */
    protected function buildWifiRadioConfigWrite(string $band, array $v): array
    {
        $key       = $band === '5' ? 'radio_5' : 'radio_24';
        $instance  = self::WIFI_RADIO_INSTANCES[$key][0]; // principal da banda
        $base      = $this->wifiRootPath() . '.' . $instance;
        $pairs     = [];

        if (isset($v['power']) && $v['power'] !== null) {
            $pairs["{$base}.TransmitPower"] = (int) $v['power'];
        }

        if (isset($v['bandwidth']) && $v['bandwidth'] !== null && $v['bandwidth'] !== '') {
            $pairs["{$base}.BandWidth"] = (string) $v['bandwidth'];
        }

        if (isset($v['channel']) && $v['channel'] !== null) {
            $channel = (int) $v['channel'];
            if ($channel === 0) {
                $pairs["{$base}.AutoChannelEnable"] = true;
            }
            else {
                $pairs["{$base}.AutoChannelEnable"] = false;
                $pairs["{$base}.Channel"]           = $channel;
            }
        }

        if (isset($v['extension_channel']) && $v['extension_channel'] !== null && $v['extension_channel'] !== '') {
            $pairs["{$base}.X_ZTE-COM_ExtensionChannel"] = $v['extension_channel'] === 'above'
                ? 'AboveControlChannel'
                : 'BelowControlChannel';
        }

        if (isset($v['beacon_interval']) && $v['beacon_interval'] !== null) {
            $pairs["{$base}.X_ZTE-COM_BeaconPeriod"] = (int) $v['beacon_interval'];
        }

        if (isset($v['region']) && $v['region'] !== null && $v['region'] !== '') {
            $pairs["{$base}.RegulatoryDomain"] = (string) $v['region'];
        }

        return $pairs;
    }
}
