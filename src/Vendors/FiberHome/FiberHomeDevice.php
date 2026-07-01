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

    /**
     * Monta os parâmetros TR-069 (paths completos → valor) de uma WAN do FiberHome
     * no WANConnectionDevice `$index`. Derivado de capturas reais (HG6143D, dumps
     * fh1–fh6). Tipos suportados: `dhcp`, `static`, `pppoe` (bridge fora de escopo).
     *
     *  - DHCP/Static → WANIPConnection.1 (AddressingType DHCP/Static)
     *  - PPPoE       → WANPPPConnection.1 (Username/Password/ConnectionTrigger)
     *  - VLAN/COS/Enable/Mode/WanIndex → X_FH_WANGponLinkConfig (nível WCD)
     *  - LAN binding → X_FH_LanInterface (CSV de paths LANEth/WLANConfiguration)
     *
     * @param  array<string,mixed> $v  config canônica da WAN
     * @return array<string,mixed>     path completo → valor
     */
    public function buildWanWrite(int $index, array $v): array
    {
        $mode     = strtolower((string) ($v['mode'] ?? 'dhcp'));   // dhcp | static | pppoe
        $isPppoe  = $mode === 'pppoe';
        $service  = strtoupper((string) ($v['service'] ?? 'INTERNET'));
        $type     = strtolower((string) ($v['type'] ?? 'route'));  // route (bridge fora de escopo)
        $wanIndex = (int) ($v['wan_index'] ?? $index);             // índice interno da ONU (≠ instância WCD)

        // Só serviços de INTERNET roteados recebem IP mode / DS-lite / LAN binding.
        $routeNeeded = $type === 'route'
            && in_array($service, ['INTERNET', 'TR069_INTERNET', 'VOIP_INTERNET', 'TR069_VOIP_INTERNET'], true);

        $wcd  = $this->wanRootPath() . '.1.WANConnectionDevice.' . $index;
        $conn = $wcd . '.' . ($isPppoe ? 'WANPPPConnection' : 'WANIPConnection') . '.1';
        $gpon = $wcd . '.X_FH_WANGponLinkConfig';

        $enabled = array_key_exists('enabled', $v) ? (bool) $v['enabled'] : true;

        $p = [];

        // ── Conexão ──
        $p["{$conn}.Enable"]           = $enabled;
        $p["{$conn}.ConnectionType"]   = 'IP_Routed';
        $p["{$conn}.X_FH_ServiceList"] = $service;

        if ($isPppoe) {
            if (isset($v['username'])) {
                $p["{$conn}.Username"] = (string) $v['username'];
            }
            if (isset($v['password']) && $v['password'] !== '') {
                $p["{$conn}.Password"] = (string) $v['password'];
            }
            $p["{$conn}.ConnectionTrigger"] = 'AlwaysOn';
            if (isset($v['mtu'])) {
                $p["{$conn}.MaxMRUSize"] = (int) $v['mtu'];
            }
        }
        else {
            $p["{$conn}.AddressingType"] = $mode === 'static' ? 'Static' : 'DHCP';
            if (isset($v['mtu'])) {
                $p["{$conn}.MaxMTUSize"] = (int) $v['mtu'];
            }
            if ($mode === 'static') {
                if (isset($v['ip'])) {
                    $p["{$conn}.ExternalIPAddress"] = (string) $v['ip'];
                }
                if (isset($v['mask'])) {
                    $p["{$conn}.SubnetMask"] = (string) $v['mask'];
                }
                if (isset($v['gateway'])) {
                    $p["{$conn}.DefaultGateway"] = (string) $v['gateway'];
                }

                $dns = array_values(array_filter([$v['dns1'] ?? null, $v['dns2'] ?? null], fn ($d) => $d !== null && $d !== ''));
                if ($dns !== []) {
                    $p["{$conn}.DNSServers"] = implode(',', $dns);
                }
            }
        }

        // Opcionais comuns (só gravados quando informados).
        if (array_key_exists('nat', $v)) {
            $p["{$conn}.NATEnabled"] = (bool) $v['nat'];
        }
        if (array_key_exists('dns_relay', $v)) {
            $p["{$conn}.DNSEnabled"] = (bool) $v['dns_relay'];
        }
        if (array_key_exists('upnp', $v)) {
            $p["{$conn}.UPNPControl"] = $v['upnp'] ? 1 : 0;
        }

        // Extras só para serviço de INTERNET roteado.
        if ($routeNeeded) {
            $p["{$conn}.X_FH_Dslite_Enable"] = false;
            $p["{$conn}.X_FH_IPMode"]        = 1; // IPv4

            $bind = ! empty($v['lan_bind']) ? $this->wanLanBindPaths((array) $v['lan_bind']) : '';
            if ($bind !== '') {
                $p["{$conn}.X_FH_LanInterface"] = $bind;
            }
        }

        // ── Nível WANConnectionDevice (link GPON / VLAN / COS) — Mode de escrita = 1. ──
        if (isset($v['cos'])) {
            $p["{$gpon}.802-1pMark"] = (int) $v['cos'];
        }
        $p["{$gpon}.Enable"] = $enabled;
        $p["{$gpon}.Mode"]   = 1;
        if (isset($v['vlan'])) {
            $p["{$gpon}.VLANID"]     = (int) $v['vlan'];
            $p["{$gpon}.VLANIDMark"] = (int) $v['vlan'];
        }
        $p["{$gpon}.WanIndex"] = $wanIndex;

        return $p;
    }

    /**
     * Converte nomes amigáveis de interface (LAN1-4, SSID1-8) nos paths TR-069 que
     * o `X_FH_LanInterface` espera (CSV).
     *
     * @param  array<int,string> $names
     */
    private function wanLanBindPaths(array $names): string
    {
        $base  = 'InternetGatewayDevice.LANDevice.1';
        $paths = [];
        foreach ($names as $n) {
            if (preg_match('/^LAN(\d+)$/i', trim((string) $n), $m)) {
                $paths[] = "{$base}.LANEthernetInterfaceConfig.{$m[1]}";
            }
            elseif (preg_match('/^SSID(\d+)$/i', trim((string) $n), $m)) {
                $paths[] = "{$base}.WLANConfiguration.{$m[1]}";
            }
        }

        return implode(',', $paths);
    }

    /**
     * Cria uma nova WAN (fluxo homologado do HG6143D): `addObject` do
     * WANConnectionDevice → descobre a instância criada → `addObject` da conexão
     * (IP/PPP) → grava os parâmetros. Cada passo espera ~1s (o firmware precisa de
     * folga entre connection_requests). Faz **rollback** (`deleteObject`) se algo
     * falhar depois de criar o WCD. Retorna o índice do WANConnectionDevice criado.
     *
     * @param  array<string,mixed> $config  config canônica (ver buildWanWrite)
     * @throws \RuntimeException  em qualquer falha (rollback já aplicado)
     */
    public function createWan(array $config, int $timeoutMs = 30000): int
    {
        $root    = $this->wanRootPath() . '.1.WANConnectionDevice';
        $isPppoe = strtolower((string) ($config['mode'] ?? 'dhcp')) === 'pppoe';

        // 1) Estado atual: instâncias WCD e WanIndex internos em uso.
        $before    = $this->getObjectInstances($root, $timeoutMs);
        $beforeWcd = array_map('intval', array_keys($before));
        $usedIndex = [];
        foreach ($before as $inst) {
            if (isset($inst['X_FH_WANGponLinkConfig.WanIndex'])) {
                $usedIndex[] = (int) $inst['X_FH_WANGponLinkConfig.WanIndex'];
            }
        }

        // 2) Próximo WanIndex interno livre (1..16).
        $wanIndex = 0;
        for ($i = 1; $i <= 16; $i++) {
            if (! in_array($i, $usedIndex, true)) {
                $wanIndex = $i;
                break;
            }
        }
        if ($wanIndex === 0) {
            throw new \RuntimeException('Sem WanIndex livre (máximo de 16 WANs).');
        }

        // 3) Cria o WANConnectionDevice.
        usleep(1_000_000);
        if (! $this->addObject($root, $timeoutMs)) {
            throw new \RuntimeException('Falha ao criar o WANConnectionDevice (addObject não aplicou).');
        }
        usleep(1_000_000);

        // 4) Descobre a instância recém-criada.
        $after  = $this->getObjectInstances($root, $timeoutMs);
        $newWcd = null;
        foreach (array_map('intval', array_keys($after)) as $i) {
            if (! in_array($i, $beforeWcd, true)) {
                $newWcd = $i;
                break;
            }
        }
        if ($newWcd === null) {
            throw new \RuntimeException('Não foi possível identificar o WANConnectionDevice criado.');
        }

        try {
            // 5) Cria a conexão (IP ou PPP).
            usleep(1_000_000);
            $connObj = $root . '.' . $newWcd . '.' . ($isPppoe ? 'WANPPPConnection' : 'WANIPConnection');
            if (! $this->addObject($connObj, $timeoutMs)) {
                throw new \RuntimeException('Falha ao criar a conexão WAN (addObject não aplicou).');
            }

            // 6) Grava os parâmetros.
            usleep(1_000_000);
            $config['wan_index'] = $wanIndex;
            if (! $this->writeWanParams($newWcd, $config, $timeoutMs)) {
                throw new \RuntimeException('Falha ao gravar os parâmetros da WAN.');
            }
        }
        catch (\Throwable $e) {
            usleep(1_000_000);

            try {
                $this->deleteObject($root . '.' . $newWcd, $timeoutMs);
            }
            catch (\Throwable) {
                // best-effort
            }

            throw $e;
        }

        return $newWcd;
    }

    /**
     * Edita uma WAN existente (só `setParameterValues`, síncrono). `$index` = nº do
     * WANConnectionDevice.
     *
     * @param  array<string,mixed> $config
     */
    public function updateWan(int $index, array $config, int $timeoutMs = 30000): bool
    {
        return $this->writeWanParams($index, $config, $timeoutMs);
    }

    /** Remove uma WAN (o WANConnectionDevice inteiro). `$index` = nº do WCD. */
    public function deleteWan(int $index, int $timeoutMs = 30000): bool
    {
        return $this->deleteObject($this->wanRootPath() . '.1.WANConnectionDevice.' . $index, $timeoutMs);
    }

    /** Executa o setParameterValues (síncrono) dos pares do buildWanWrite. */
    private function writeWanParams(int $index, array $config, int $timeoutMs): bool
    {
        $pairs = $this->buildWanWrite($index, $config);
        if ($pairs === []) {
            return false;
        }

        $parameterValues = [];
        foreach ($pairs as $path => $val) {
            $parameterValues[] = [$path, $val];
        }

        return $this->client->executeTask($this->deviceInfo->id, [
            'name'            => 'setParameterValues',
            'parameterValues' => $parameterValues,
        ], $timeoutMs);
    }
}
