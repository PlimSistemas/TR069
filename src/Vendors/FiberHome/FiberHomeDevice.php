<?php

namespace Plimsistemas\TR069\Vendors\FiberHome;

use Plimsistemas\TR069\Device\AbstractDevice;

/**
 * Camada MARCA (FiberHome) da resolução de parâmetros.
 *
 * Define os paths TR-069 PADRÃO de todo equipamento FiberHome. Modelos
 * estendem esta classe e sobrescrevem apenas o que diferir.
 *
 * Observação: no FiberHome o GPON SN É o próprio SerialNumber (formato FHTT…),
 * diferente do ZTE que usa X_ZTE-COM_GPONSN.
 */
abstract class FiberHomeDevice extends AbstractDevice
{
    public function vendor(): string
    {
        return 'FiberHome';
    }

    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // Chaves canônicas (genéricas) — padrão FiberHome
            'uptime'           => 'InternetGatewayDevice.DeviceInfo.UpTime',
            'sw_version'       => 'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'hw_version'       => 'InternetGatewayDevice.DeviceInfo.HardwareVersion',
            'gpon_sn'          => 'InternetGatewayDevice.DeviceInfo.SerialNumber',
            'optical.tx_power' => 'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.TXPower',
            'optical.rx_power' => 'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower',

            // DeviceInfo
            'device.serial'    => 'InternetGatewayDevice.DeviceInfo.SerialNumber',
            'device.uptime'    => 'InternetGatewayDevice.DeviceInfo.UpTime',
            'device.firmware'  => 'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'device.hardware'  => 'InternetGatewayDevice.DeviceInfo.HardwareVersion',

        ]);
    }

    // Parametros extras específicos de FiberHome
    protected function lanConfigMap(): array
    {
        return array_merge(parent::lanConfigMap(), []);
    }

    /**
     * VLAN/COS da WAN (proprietários FiberHome) — ficam no nível
     * WANConnectionDevice (X_FH_WANGponLinkConfig.*), trazidos pelo merge em
     * getWanConnections().
     */
    protected function wanConnectionMap(): array
    {
        return array_merge(parent::wanConnectionMap(), [
            'service' => 'X_FH_ServiceList',
            'vlan'    => 'X_FH_WANGponLinkConfig.VLANID',
            'cos'     => 'X_FH_WANGponLinkConfig.802-1pMark',
        ]);
    }

    /**
     * Extensões de voz proprietárias FiberHome (vão para $extra do VoiceProfile):
     * proxy/registrar standby (X_FH_Standby-*), marca de prioridade 802.1p e
     * DTMF (FiberHome usa só DTMFMethod, sem o split G711 da ZTE).
     */
    protected function voiceProfileMap(): array
    {
        return array_merge(parent::voiceProfileMap(), [
            'dtmf_method'       => 'DTMFMethod',
            'standby_proxy'     => 'SIP.X_FH_Standby-ProxyServer',
            'standby_registrar' => 'SIP.X_FH_Standby-RegistrarServer',
            'cos'               => 'SIP.X_FH_802-1pMark',
        ]);
    }

    /**
     * Funções suplementares da linha no FiberHome ficam sob X_FH_IMS.* (não no
     * CallingFeatures padrão). Vão para $extra do VoiceLine.
     */
    protected function voiceLineMap(): array
    {
        return array_merge(parent::voiceLineMap(), [
            'call_waiting' => 'X_FH_IMS.cw-service',
            'hold'         => 'X_FH_IMS.hold-service',
        ]);
    }

    /**
     * Instâncias de WLANConfiguration por banda (mesma convenção da ZTE/TR-098):
     * 1-4 = 2.4 GHz, 5-8 = 5 GHz.
     */
    private const WIFI_RADIO_INSTANCES = [
        'radio_24' => [1, 2, 3, 4],
        'radio_5'  => [5, 6, 7, 8],
    ];

    /**
     * Modos de segurança (BeaconType + cifra + auth) — vocabulário TR-098 padrão,
     * igual ao da ZTE. Mapa: modo canônico → [BeaconType, EncryptionModes, AuthMode].
     */
    private const WIFI_SECURITY_MODES = [
        'open'          => ['None', null, null],
        'wpa2-psk'      => ['11i', 'AESEncryption', 'PSKAuthentication'],
        'wpa-wpa2-psk'  => ['WPAand11i', 'TKIPandAESEncryption', 'PSKAuthentication'],
        'wpa3-sae'      => ['WPA3', 'AESEncryption', 'PSKAuthentication'],
        'wpa2-wpa3-sae' => ['11iandWPA3', 'TKIPandAESEncryption', 'PSKAuthentication'],
    ];

    /**
     * Ajustes de leitura do FiberHome:
     *  - `broadcast` (VISÍVEL) é INVERTIDO vs TR-098 padrão (`true` = SSID OCULTO).
     *  - `radio_enabled` (`RadioEnabled`) é NÃO-CONFIÁVEL: o device reporta sempre
     *    `false`, mesmo com o rádio ligado (confirmado ao vivo via API do GenieACS).
     *    O estado real do rádio/banda está no `Enable` do SSID → espelhamos
     *    `enabled` → `radio_enabled` para o front (toggle + STATUS) bater com a
     *    realidade. A escrita do toggle também usa `Enable` (ver buildWifiRadioWrite).
     */
    protected function normalizeWifiEntry(array $entry): array
    {
        if (array_key_exists('broadcast', $entry) && $entry['broadcast'] !== null) {
            $entry['broadcast'] = ! $this->boolish($entry['broadcast']);
        }

        if (array_key_exists('enabled', $entry)) {
            $entry['radio_enabled'] = $entry['enabled'];
        }

        return $entry;
    }

    /**
     * Escrita do SSID (FiberHome). Espelha a ZTE nas leaves TR-098 padrão, mas
     * INVERTE o `hide_ssid` (ver normalizeWifiEntry: true=oculto no FiberHome).
     * `max_users` é proprietário FiberHome (leaf desconhecida sem captura) → ignorado.
     */
    protected function buildWifiSsidWrite(array $v): array
    {
        $pairs = [];

        if (isset($v['ssid']) && $v['ssid'] !== '') {
            $pairs['SSID'] = (string) $v['ssid'];
            $pairs['Name'] = (string) $v['ssid'];
        }

        if (array_key_exists('hide_ssid', $v) && $v['hide_ssid'] !== null) {
            // Invertido: ocultar → SSIDAdvertisementEnabled = true.
            $pairs['SSIDAdvertisementEnabled'] = (bool) $v['hide_ssid'];
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
     * Liga/desliga o rádio por banda no FiberHome. Como `RadioEnabled` não é
     * confiável (sempre lê `false`), o controle efetivo+legível é o `Enable` do
     * SSID PRINCIPAL da banda (1 = 2.4 GHz, 5 = 5 GHz) — ligar/desligar ele
     * liga/desliga o Wi-Fi daquela banda. Escrevemos também `RadioEnabled` em todas
     * as instâncias por garantia (firmwares que o respeitam), mas só o `Enable` da
     * principal é o que a leitura usa (ver normalizeWifiEntry). NÃO ligamos os SSIDs
     * extras (2-4/6-8) para não passar a difundi-los.
     */
    protected function buildWifiRadioWrite(array $v): array
    {
        $root  = $this->wifiRootPath();
        $pairs = [];

        foreach (self::WIFI_RADIO_INSTANCES as $key => $instances) {
            if (! array_key_exists($key, $v) || $v[$key] === null) {
                continue;
            }

            $val = (bool) $v[$key];

            foreach ($instances as $i) {
                $pairs["{$root}.{$i}.RadioEnabled"] = $val;
            }

            $pairs["{$root}.{$instances[0]}.Enable"] = $val; // SSID principal = sinal legível
        }

        return $pairs;
    }

    /**
     * Config avançada do rádio (FiberHome) — escreve na instância PRINCIPAL da
     * banda (1 = 2.4 GHz, 5 = 5 GHz). Só as leaves TR-098 padrão confirmadas:
     *
     *  - power            → TransmitPower (degraus expostos em TransmitPowerSupported)
     *  - channel (0=auto) → AutoChannelEnable (+ Channel quando fixo)
     *  - mode             → Standard (gravável no FiberHome; na ZTE é read-only)
     *  - region           → RegulatoryDomain (FiberHome usa nomes: BRAZIL, FCC...)
     *
     * Largura do canal NÃO existe na árvore TR-069 do FiberHome (confirmado em
     * captura) → não é configurável; `extension_channel`/`beacon_interval` idem.
     */
    protected function buildWifiRadioConfigWrite(string $band, array $v): array
    {
        $key      = $band === '5' ? 'radio_5' : 'radio_24';
        $instance = self::WIFI_RADIO_INSTANCES[$key][0];
        $base     = $this->wifiRootPath() . '.' . $instance;
        $pairs    = [];

        if (isset($v['power']) && $v['power'] !== null) {
            $pairs["{$base}.TransmitPower"] = (int) $v['power'];
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

        if (isset($v['mode']) && $v['mode'] !== null && $v['mode'] !== '') {
            $pairs["{$base}.Standard"] = (string) $v['mode'];
        }

        if (isset($v['region']) && $v['region'] !== null && $v['region'] !== '') {
            $pairs["{$base}.RegulatoryDomain"] = (string) $v['region'];
        }

        return $pairs;
    }

    /** Converte um valor cru TR-069 (bool/int/string) em booleano. */
    private function boolish(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'enabled', 'on'], true);
    }
}
