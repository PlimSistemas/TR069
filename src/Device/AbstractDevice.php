<?php

namespace Plimsistemas\TR069\Device;

use Illuminate\Support\Collection;
use Plimsistemas\TR069\Contracts\DeviceInterface;
use Plimsistemas\TR069\Device\Data\Host;
use Plimsistemas\TR069\Device\Data\LanConfig;
use Plimsistemas\TR069\Device\Data\LanPort;
use Plimsistemas\TR069\Device\Data\VoiceLine;
use Plimsistemas\TR069\Device\Data\VoiceProfile;
use Plimsistemas\TR069\Device\Data\WanConnection;
use Plimsistemas\TR069\Device\Data\WifiNetwork;
use Plimsistemas\TR069\Exceptions\DeviceNotSupportedException;
use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\GenieACS\Responses\TaskResponse;

abstract class AbstractDevice implements DeviceInterface
{
    public function __construct(
        protected DeviceInfo $deviceInfo,
        protected Client $client,
    ) {}

    // -------------------------------------------------------------------------
    // Identity — subclasses define these
    // -------------------------------------------------------------------------

    abstract public function vendor(): string;

    abstract public function model(): string;

    abstract public function firmwareVersion(): string;

    /**
     * Mapeia chaves amigáveis para paths TR-069.
     *
     * A resolução é em CAMADAS, via herança de classes, com a mais específica
     * vencendo (cada nível faz array_merge sobre o pai):
     *
     *   AbstractDevice (base [])
     *     └─ {Vendor}Device      (Marca — padrões do fabricante)
     *          └─ {Model}Device   (Modelo — sobrescreve a marca)
     *               └─ {Model}{Firmware}Device  (Modelo/Firmware — sobrescreve o modelo)
     *
     * Cada nível implementa:
     *   protected function parameterMap(): array
     *   {
     *       return array_merge(parent::parameterMap(), [
     *           // apenas o que muda nesta camada
     *       ]);
     *   }
     */
    protected function parameterMap(): array
    {
        return [

            // ----------------------------------------------------------------
            // LAN / DHCP
            // ----------------------------------------------------------------
            'lan.ip'                 => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceIPAddress',
            'lan.subnet'             => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceSubnetMask',
            'lan.dns'                => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.DNSServers',
            'lan.isp_dns'            => 'specific', // Nem todos tem suporte
            'lan.dhcp.enabled'       => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.DHCPServerEnable',
            'lan.dhcp.start'         => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MinAddress',
            'lan.dhcp.end'           => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.MaxAddress',
            'lan.dhcp.lease'         => 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.DHCPLeaseTime',

        ];
    }

    // -------------------------------------------------------------------------
    // DeviceInterface implementation
    // -------------------------------------------------------------------------

    public function info(): DeviceInfo
    {
        return $this->deviceInfo;
    }

    /**
     * Inicia um fetch genérico de dados atualizados (uptime, potências, etc.),
     * agnóstico de fabricante/firmware.
     *
     *   $device->fetch()->uptime()->txPower()->rxPower()->execute();
     */
    public function fetch(): ParameterFetch
    {
        return new ParameterFetch($this, $this->client);
    }

    /**
     * Resolve uma chave amigável para o path TR-069 deste device, ou null se
     * a chave não estiver mapeada (diferente de get()/resolvePath(), não lança).
     */
    public function pathFor(string $key): ?string
    {
        return $this->parameterMap()[$key] ?? null;
    }

    /**
     * Get a parameter value by friendly key.
     */
    public function get(string $key): mixed
    {
        $path = $this->resolvePath($key);
        return $this->getPath($path);
    }

    /**
     * Set a parameter value by friendly key.
     */
    public function set(string $key, mixed $value): TaskResponse
    {
        $path = $this->resolvePath($key);
        return $this->setPath($path, $value);
    }

    /**
     * Get multiple parameter values by friendly keys.
     * Returns [ 'key' => value, ... ]
     *
     * Dispara UMA task getParameterValues síncrona para atualizar os valores no
     * documento do device e então os lê de lá (ver readPaths()).
     */
    public function getMany(array $keys, int $timeoutMs = 30000): array
    {
        $map = [];
        foreach ($keys as $key) {
            $map[$key] = $this->resolvePath($key);
        }

        $values = $this->readPaths(array_values($map), $timeoutMs);

        $result = [];
        foreach ($map as $key => $path) {
            $result[$key] = $values[$path] ?? null;
        }

        return $result;
    }

    /**
     * Set multiple parameter values by friendly keys.
     * Accepts [ 'key' => value, ... ]
     */
    public function setMany(array $params): TaskResponse
    {
        $parameterValues = [];

        foreach ($params as $key => $value) {
            $path              = $this->resolvePath($key);
            $parameterValues[] = [$path, $value];
        }

        return $this->client->setParameterValues($this->deviceInfo->id, $parameterValues);
    }

    /**
     * Escreve várias leaves de um objeto via TR-069 e CONFIRMA de forma SÍNCRONA
     * (connection request, igual aos refresh dos get*). Cada chave canônica é
     * resolvida para `$basePath . '.' . $map[$key]`; chaves fora do mapa e
     * valores `null` são ignorados.
     *
     * @param  array<string,string> $map     chave canônica → leaf relativa
     * @param  array<string,mixed>  $values  chave canônica → valor
     * @return bool  true = device aplicou a tempo; false = nada a escrever / não conectou.
     */
    protected function writeMapped(string $basePath, array $map, array $values, int $timeoutMs = 30000): bool
    {
        $parameterValues = [];
        foreach ($values as $key => $value) {
            if ($value === null || ! isset($map[$key])) {
                continue;
            }
            $parameterValues[] = [$basePath . '.' . $map[$key], $value];
        }

        if ($parameterValues === []) {
            return false;
        }

        return $this->client->executeTask($this->deviceInfo->id, [
            'name'            => 'setParameterValues',
            'parameterValues' => $parameterValues,
        ], $timeoutMs);
    }

    /**
     * Escreve a configuração LAN/DHCP (objeto único LANHostConfigManagement) via
     * TR-069, com as MESMAS chaves canônicas de getLanConfig() (ip/subnet/dns/
     * dhcp_enabled/dhcp_start/dhcp_end/dhcp_lease). Chaves desconhecidas e valores
     * `null` são ignorados.
     *
     * @param  array<string,mixed> $values
     */
    public function setLanConfig(array $values, int $timeoutMs = 30000): bool
    {
        return $this->writeMapped($this->lanConfigPath(), $this->lanConfigMap(), $values, $timeoutMs);
    }

    /**
     * Escreve a configuração de UMA porta LAN (LANEthernetInterfaceConfig.{port})
     * via TR-069, com as chaves canônicas de getLanPorts(). `auto_negotiation` é
     * açúcar: `true` → speed/duplex = "Auto"; quando `false`, usa `speed`
     * (10/100/1000) e `duplex` (full/half → Full/Half). `enabled` liga/desliga a
     * porta.
     *
     * @param  array{enabled?:bool,auto_negotiation?:bool,speed?:int|string,duplex?:string} $values
     */
    public function setLanPort(int $port, array $values, int $timeoutMs = 30000): bool
    {
        $base  = $this->lanPortsPath() . '.' . $port;
        $write = [];

        if (array_key_exists('enabled', $values) && $values['enabled'] !== null) {
            $write['enabled'] = (bool) $values['enabled'];
        }

        $auto = $values['auto_negotiation'] ?? null;
        if ($auto === true) {
            $write['speed']  = 'Auto';
            $write['duplex'] = 'Auto';
        } elseif ($auto === false || isset($values['speed']) || isset($values['duplex'])) {
            if (isset($values['speed'])) {
                $write['speed'] = (string) $values['speed'];
            }
            if (isset($values['duplex'])) {
                $write['duplex'] = ucfirst(strtolower((string) $values['duplex'])); // full→Full
            }
        }

        return $this->writeMapped($base, $this->lanPortMap(), $write, $timeoutMs);
    }

    /**
     * Escreve a configuração de um SSID Wi-Fi (`WLANConfiguration.{instance}`) via
     * TR-069, de forma SÍNCRONA. As leaves/valores a escrever são montados por
     * `buildWifiSsidWrite()` (específico de cada fabricante). Chaves canônicas de
     * entrada: `ssid`, `security` (open/wpa2-psk/wpa-wpa2-psk), `encryption`
     * (aes/tkip/tkip-aes), `password`, `max_users`, `hide_ssid`, `user_isolation`.
     *
     * @param  array<string,mixed> $values
     * @return bool  true = device aplicou a tempo; false = nada a escrever / não conectou.
     */
    public function setWifiSsid(int $instance, array $values, int $timeoutMs = 30000): bool
    {
        $pairs = $this->buildWifiSsidWrite($values);
        if ($pairs === []) {
            return false;
        }

        $base            = $this->wifiRootPath() . '.' . $instance;
        $parameterValues = [];
        foreach ($pairs as $leaf => $val) {
            $parameterValues[] = [$base . '.' . $leaf, $val];
        }

        return $this->client->executeTask($this->deviceInfo->id, [
            'name'            => 'setParameterValues',
            'parameterValues' => $parameterValues,
        ], $timeoutMs);
    }

    /**
     * Monta as leaves (relativas a `WLANConfiguration.{i}`) → valor a escrever no
     * SSID, a partir das chaves canônicas. Específico por fabricante (BeaconType,
     * modos de cripto/auth e extensões proprietárias variam) — a base não suporta.
     *
     * @param  array<string,mixed> $values
     * @return array<string,mixed>
     */
    protected function buildWifiSsidWrite(array $values): array
    {
        throw new DeviceNotSupportedException('Configuração de Wi-Fi via TR-069 não suportada para este modelo.');
    }

    /**
     * Liga/desliga o rádio Wi-Fi por banda. `$values` aceita as chaves opcionais
     * `radio_24` e `radio_5` (bool); ausente/null = não altera aquela banda.
     * Escrita síncrona — retorna se o ACS aplicou.
     *
     * @param  array<string,mixed> $values
     */
    public function setWifiRadio(array $values, int $timeoutMs = 30000): bool
    {
        $pairs = $this->buildWifiRadioWrite($values); // [pathCompleto => valor]
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

    /**
     * Monta os paths TR-069 COMPLETOS → valor para ligar/desligar o rádio de cada
     * banda. As instâncias de WLANConfiguration por banda variam por fabricante —
     * a base não suporta.
     *
     * @param  array<string,mixed> $values
     * @return array<string,mixed>
     */
    protected function buildWifiRadioWrite(array $values): array
    {
        throw new DeviceNotSupportedException('Controle de rádio Wi-Fi via TR-069 não suportado para este modelo.');
    }

    /**
     * Config avançada do rádio de uma banda (potência, largura, canal, etc.).
     * `$band` = '2.4' | '5'. Escrita síncrona — retorna se o ACS aplicou.
     *
     * @param  array<string,mixed> $values
     */
    public function setWifiRadioConfig(string $band, array $values, int $timeoutMs = 30000): bool
    {
        $pairs = $this->buildWifiRadioConfigWrite($band, $values); // [pathCompleto => valor]
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

    /**
     * Monta os paths TR-069 COMPLETOS → valor da config avançada do rádio de uma
     * banda. Leaves/instâncias variam por fabricante — a base não suporta.
     *
     * @param  array<string,mixed> $values
     * @return array<string,mixed>
     */
    protected function buildWifiRadioConfigWrite(string $band, array $values): array
    {
        throw new DeviceNotSupportedException('Configuração de rádio Wi-Fi via TR-069 não suportada para este modelo.');
    }

    /**
     * Configuração da interface LAN/DHCP, com nomes de parâmetro PADRONIZADOS
     * (canônicos), independentes do fabricante — mesmo padrão de getLanPorts()/
     * getHosts(): lê o objeto LANHostConfigManagement (instância única) e
     * normaliza via `lanConfigMap()` (chave canônica → leaf relativa).
     *
     * Chaves que o device não expõe vêm como null, então o shape é o MESMO
     * para qualquer ONU.
     *
     * @return LanConfig  DTO tipado; campos de fabricante (ex.: isp_dns) em ->extra
     */
    public function getLanConfig(int $timeoutMs = 30000): LanConfig
    {
        $raw = $this->getObject($this->lanConfigPath(), $timeoutMs);

        return LanConfig::fromArray($this->mapInstance($raw, $this->lanConfigMap()));
    }

    /**
     * Path do objeto (instância única) com a configuração LAN/DHCP. Subclasses
     * cujo namespace difira do padrão TR-098/InternetGatewayDevice sobrescrevem.
     */
    protected function lanConfigPath(): string
    {
        return 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement';
    }

    /**
     * Mapa canônico da configuração LAN: chave padronizada → leaf relativa ao
     * objeto `lanConfigPath()`. Mesma lógica em camadas do lanPortMap()/
     * hostMap(): cada nível sobrescreve só o que muda via
     * `array_merge(parent::lanConfigMap(), [...])`.
     *
     * @return array<string,string>
     */
    protected function lanConfigMap(): array
    {
        return [
            'ip'           => 'IPInterface.1.IPInterfaceIPAddress',
            'subnet'       => 'IPInterface.1.IPInterfaceSubnetMask',
            'dns'          => 'DNSServers',
            'dhcp_enabled' => 'DHCPServerEnable',
            'dhcp_start'   => 'MinAddress',
            'dhcp_end'     => 'MaxAddress',
            'dhcp_lease'   => 'DHCPLeaseTime',
        ];
    }

    /**
     * Lê um objeto MULTI-INSTÂNCIA do TR-069 (ex.: as portas físicas LAN em
     * `...LANEthernetInterfaceConfig.*`, ou hosts conectados, WANConnection, etc.)
     * e devolve uma entrada por instância.
     *
     * Diferente de getMany() (chaves→paths fixos), aqui não se sabe quantas
     * instâncias existem: faz um refreshObject síncrono para o device
     * descobrir/atualizar a subárvore inteira e então lê esse ramo do documento,
     * achatando os parâmetros escalares de cada instância em chaves pontilhadas.
     *
     * @param  string  $objectPath  path do objeto-pai, SEM o `.*` final
     *                              (ex.: 'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig')
     * @return array<int,array<string,mixed>>
     *   ex.: [1 => ['Enable'=>true,'Status'=>'Up','MACAddress'=>'...','Stats.BytesSent'=>123], 2 => [...]]
     */
    public function getObjectInstances(string $objectPath, int $timeoutMs = 30000): array
    {
        $node = $this->refreshAndReadNode($objectPath, $timeoutMs);

        $instances = [];
        foreach ($node as $key => $child) {
            // Instâncias = chaves numéricas; ignora metadados (_object, _writable…).
            if (!ctype_digit((string) $key) || !is_array($child)) {
                continue;
            }
            $instances[(int) $key] = $this->flattenLeaves($child);
        }

        ksort($instances);

        return $instances;
    }

    /**
     * Lê um objeto de instância ÚNICA do TR-069 (ex.: LANHostConfigManagement)
     * e devolve seus parâmetros escalares achatados em chaves pontilhadas
     * relativas. Mesmo mecanismo (refreshObject + leitura do documento) de
     * getObjectInstances(), mas sem desmembrar por instância numérica.
     *
     * @return array<string,mixed>  ex.: ['DNSServers'=>'8.8.8.8','IPInterface.1.IPInterfaceIPAddress'=>'192.168.1.1']
     */
    public function getObject(string $objectPath, int $timeoutMs = 30000): array
    {
        return $this->flattenLeaves($this->refreshAndReadNode($objectPath, $timeoutMs));
    }

    /**
     * Atualiza (refreshObject) uma subárvore do device em UM único contato com a
     * ONU. Útil para, em seguida, ler vários grupos do cache sem novos contatos:
     *
     *   $device->refresh('InternetGatewayDevice.LANDevice.1'); // 1 request (~Ns)
     *   $cfg    = $device->getLanConfig(0);   // cache, instantâneo
     *   $portas = $device->getLanPorts(0);    // cache
     *   $hosts  = $device->getHosts(0);       // cache
     *
     * Aceita um path único ou um ARRAY de paths — neste caso todos são
     * refrescados em UMA só sessão (um único connection request), em vez de um
     * contato por objeto.
     *
     * @param  string|string[] $objectPaths
     * @return bool  true = ONU executou a tempo; false = não respondeu (offline).
     */
    public function refresh(string|array $objectPaths, int $timeoutMs = 30000): bool
    {
        $paths = array_values(array_filter(array_unique((array) $objectPaths)));

        if ($paths === []) {
            return false;
        }

        $tasks = array_map(
            fn (string $path) => ['name' => 'refreshObject', 'objectName' => $path],
            $paths
        );

        return $this->client->executeTasks($this->deviceInfo->id, $tasks, $timeoutMs);
    }

    /**
     * Atalho: atualiza os objetos LAN lidos por getLanConfig()/getLanPorts()/
     * getHosts() em UM único contato com a ONU (sessão única). Refresca apenas
     * esses objetos (mais leve que a subárvore `LANDevice.1` inteira). Depois
     * leia cada grupo com `get*(0)` (cache).
     */
    public function refreshLan(int $timeoutMs = 30000): bool
    {
        return $this->refresh([
            $this->lanConfigPath(),
            $this->lanPortsPath(),
            $this->hostsPath(),
        ], $timeoutMs);
    }

    /**
     * Portas físicas LAN (Ethernet) — uma entrada por porta, com nomes de
     * parâmetro PADRONIZADOS (canônicos), independentes do fabricante.
     *
     * O documento cru do GenieACS traz leaves com nomes/quantidades diferentes
     * por modelo; aqui cada porta é normalizada via `lanPortMap()` (chave
     * canônica → leaf relativa). Chaves que o device não expõe vêm como null,
     * então o shape é o MESMO para qualquer ONU.
     *
     * @return Collection<int,LanPort>  indexada pelo número da porta
     */
    public function getLanPorts(int $timeoutMs = 30000): Collection
    {
        $instances = $this->getObjectInstances($this->lanPortsPath(), $timeoutMs);
        $map       = $this->lanPortMap();
        $basePath  = $this->lanPortsPath();

        $ports = [];
        foreach ($instances as $num => $raw) {
            $port = $this->mapInstance($raw, $map);
            // Path da própria instância, p/ reuso posterior (ex.: cruzar com o
            // `interface_ref` dos hosts em getHosts(), via groupBy/keyBy).
            $port['interface_ref'] = $basePath . '.' . $num;
            $ports[$num] = LanPort::fromArray($port);
        }

        return collect($ports);
    }

    /**
     * Path do objeto multi-instância das portas LAN. Subclasses cujo namespace
     * difira do padrão TR-098/InternetGatewayDevice (ex.: TR-181 `Device.*`)
     * sobrescrevem este método.
     */
    protected function lanPortsPath(): string
    {
        return 'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig';
    }

    /**
     * Mapa canônico de uma porta LAN: chave padronizada → leaf relativa à
     * instância (resolvida sobre o resultado achatado de getObjectInstances()).
     *
     * Segue a mesma lógica em camadas do parameterMap(): cada nível
     * (marca/modelo/firmware) sobrescreve apenas o que muda, via
     * `array_merge(parent::lanPortMap(), [...])`.
     *
     * @return array<string,string>
     */
    protected function lanPortMap(): array
    {
        return [
            'name'             => 'Name',
            'enabled'          => 'Enable',
            'status'           => 'Status',
            'mac'              => 'MACAddress',
            'speed'            => 'MaxBitRate',
            'duplex'           => 'DuplexMode',
            'bytes_sent'       => 'Stats.BytesSent',
            'bytes_received'   => 'Stats.BytesReceived',
            'packets_sent'     => 'Stats.PacketsSent',
            'packets_received' => 'Stats.PacketsReceived',
            'errors_sent'      => 'Stats.ErrorsSent',
            'errors_received'  => 'Stats.ErrorsReceived',
        ];
    }

    /**
     * Hosts conectados na LAN (tabela DHCP/ARP) — uma entrada por host, com
     * nomes de parâmetro PADRONIZADOS (canônicos), independentes do fabricante.
     *
     * Mesmo mecanismo de getLanPorts(): refreshObject + leitura do documento +
     * normalização via `hostMap()`. Se nenhum host estiver conectado
     * (HostNumberOfEntries = 0), retorna `[]`.
     *
     * O campo `interface` é o NOME amigável da interface por onde o host entrou
     * (SSID do Wi-Fi ou nome da porta, ex.: `eth2`), resolvido a partir da
     * referência `Layer2Interface`. `interface_type` mantém o tipo (Ethernet/
     * 802.11) e `interface_ref` o path cru da referência.
     *
     * @return Collection<int,Host>  indexada pelo número da instância do host
     */
    public function getHosts(int $timeoutMs = 30000): Collection
    {
        $instances = $this->getObjectInstances($this->hostsPath(), $timeoutMs);
        $map       = $this->hostMap();

        // 1ª passada: normaliza cada host e coleta as referências de interface.
        $rows = [];
        foreach ($instances as $num => $raw) {
            $host = $this->mapInstance($raw, $map);

            // Alguns devices (ex.: FiberHome) reportam a referência com ponto
            // final; normaliza p/ casar com o `interface_ref` de getLanPorts().
            $ref = is_string($host['interface_ref'] ?? null)
                ? rtrim($host['interface_ref'], '.')
                : null;
            $host['interface_ref'] = $ref;

            $rows[$num] = $host;
        }

        // Resolve TODOS os nomes de interface numa ÚNICA leitura do documento
        // (em vez de um getDevice por referência — principal causa de lentidão).
        $names = $this->resolveInterfaceNames(array_filter(array_column($rows, 'interface_ref')));

        // 2ª passada: aplica o nome resolvido e monta os DTOs.
        $hosts = [];
        foreach ($rows as $num => $host) {
            $ref = $host['interface_ref'];
            $host['interface'] = ($ref !== null && $ref !== '') ? ($names[$ref] ?? $ref) : null;
            $hosts[$num] = Host::fromArray($host);
        }

        return collect($hosts);
    }

    /**
     * Resolve VÁRIAS referências `Layer2Interface` para nomes amigáveis em UMA
     * única leitura do documento (projeção combinada): SSID quando aponta p/ um
     * Wi-Fi (WLANConfiguration) ou o nome da porta (ex.: `eth2`) quando aponta
     * p/ uma porta Ethernet. Lê do cache (dado estático). Não resolvida → o
     * próprio path.
     *
     * Subclasses com namespace/leaves diferentes podem sobrescrever.
     *
     * @param  string[] $refs  referências normalizadas (sem ponto final)
     * @return array<string,string>  [ ref => nome ]
     */
    protected function resolveInterfaceNames(array $refs): array
    {
        $refs = array_values(array_unique(array_filter($refs)));
        if ($refs === []) {
            return [];
        }

        // Monta a leaf de cada referência e projeta tudo num só getDevice.
        $leafOf = [];
        foreach ($refs as $ref) {
            if (str_contains($ref, 'WLANConfiguration')) {
                $leafOf[$ref] = $ref . '.SSID';
            } elseif (str_contains($ref, 'LANEthernetInterfaceConfig')) {
                $leafOf[$ref] = $ref . '.Name';
            }
        }

        $resp = $leafOf === []
            ? null
            : $this->client->getDevice($this->deviceInfo->id, implode(',', $leafOf));

        $names = [];
        foreach ($refs as $ref) {
            $leaf = $leafOf[$ref] ?? null;
            $value = ($leaf !== null && $resp !== null) ? $resp->getParameter($leaf) : null;
            $names[$ref] = ($value === null || $value === '') ? $ref : (string) $value;
        }

        return $names;
    }

    /**
     * Resolve UMA referência `Layer2Interface` (conveniência sobre
     * resolveInterfaceNames()).
     */
    protected function resolveInterfaceName(string $ref): string
    {
        $ref = rtrim($ref, '.');

        return $this->resolveInterfaceNames([$ref])[$ref] ?? $ref;
    }

    /**
     * Path do objeto multi-instância dos hosts conectados. Subclasses cujo
     * namespace difira do padrão TR-098/InternetGatewayDevice (ex.: TR-181
     * `Device.Hosts.Host`) sobrescrevem este método.
     */
    protected function hostsPath(): string
    {
        return 'InternetGatewayDevice.LANDevice.1.Hosts.Host';
    }

    /**
     * Mapa canônico de um host conectado: chave padronizada → leaf relativa à
     * instância. Mesma lógica em camadas do parameterMap()/lanPortMap(): cada
     * nível sobrescreve só o que muda via `array_merge(parent::hostMap(), [...])`.
     *
     * @return array<string,string>
     */
    protected function hostMap(): array
    {
        return [
            'hostname'        => 'HostName',
            'ip'              => 'IPAddress',
            'mac'             => 'MACAddress',
            'active'          => 'Active',
            'interface_type'  => 'InterfaceType',   // tipo: Ethernet | 802.11
            'interface_ref'   => 'Layer2Interface',  // referência crua p/ a interface real
            'address_source'  => 'AddressSource',
            'lease_remaining' => 'LeaseTimeRemaining',
        ];
    }

    /**
     * Conexões WAN da ONU, com nomes de parâmetro PADRONIZADOS, independentes do
     * fabricante. O TR-069 aninha as conexões em
     * `WANDevice.{i}.WANConnectionDevice.{j}.WAN{PPP,IP}Connection.{k}` —
     * percorremos toda essa árvore e normalizamos cada conexão via
     * `wanConnectionMap()`.
     *
     * `type` = 'ppp' (WANPPPConnection) | 'ip' (WANIPConnection); `gateway`
     * coalesce DefaultGateway (IP) ↔ RemoteIPAddress (PPP); `ref` é o path da
     * instância (reuso).
     *
     * @return Collection<int,WanConnection>
     */
    public function getWanConnections(int $timeoutMs = 30000): Collection
    {
        $root = $this->wanRootPath();
        $node = $this->refreshAndReadNode($root, $timeoutMs);
        $map  = $this->wanConnectionMap();

        $connections = [];

        foreach ($node as $wd => $wanDevice) {                       // WANDevice.{i}
            if (! ctype_digit((string) $wd) || ! is_array($wanDevice)) {
                continue;
            }

            $wcdNode = $wanDevice['WANConnectionDevice'] ?? null;
            if (! is_array($wcdNode)) {
                continue;
            }

            foreach ($wcdNode as $wcd => $connDevice) {              // WANConnectionDevice.{j}
                if (! ctype_digit((string) $wcd) || ! is_array($connDevice)) {
                    continue;
                }

                foreach (['WANPPPConnection' => 'ppp', 'WANIPConnection' => 'ip'] as $object => $type) {
                    $connsNode = $connDevice[$object] ?? null;
                    if (! is_array($connsNode)) {
                        continue;
                    }

                    // Leaves de nível WANConnectionDevice (VLAN/COS proprietários
                    // ficam aqui em alguns fabricantes, ex.: FiberHome
                    // X_FH_WANGponLinkConfig.*), exceto os objetos de conexão.
                    $deviceRaw = [];
                    foreach ($connDevice as $dk => $dv) {
                        if (str_starts_with((string) $dk, '_')
                            || in_array($dk, ['WANPPPConnection', 'WANIPConnection'], true)
                            || ! is_array($dv)) {
                            continue;
                        }
                        $deviceRaw += $this->flattenLeaves([$dk => $dv]);
                    }

                    foreach ($connsNode as $ci => $conn) {           // WAN{PPP,IP}Connection.{k}
                        if (! ctype_digit((string) $ci) || ! is_array($conn)) {
                            continue;
                        }

                        // Merge: nível conexão vence o nível WANConnectionDevice.
                        $raw   = array_merge($deviceRaw, $this->flattenLeaves($conn));
                        $entry = $this->mapInstance($raw, $map);

                        $entry['index']   = (int) $wcd; // nº do WANConnectionDevice
                        $entry['type']    = $type;
                        // mode: AddressingType (STATIC/DHCP) nas conexões IP;
                        // PPPOE derivado nas conexões PPP.
                        $entry['mode']    = $raw['AddressingType'] ?? ($type === 'ppp' ? 'PPPOE' : null);
                        // IP/gateway: 0.0.0.0 (ou vazio) vira null.
                        $entry['ip']      = $this->cleanAddress($entry['ip'] ?? null);
                        $entry['gateway'] = $this->cleanAddress($raw['DefaultGateway'] ?? $raw['RemoteIPAddress'] ?? null);
                        $entry['ref']     = "{$root}.{$wd}.WANConnectionDevice.{$wcd}.{$object}.{$ci}";

                        // DNS separado; nulo se inválido (0.0.0.0) ou desconectado.
                        $connected = strcasecmp((string) ($raw['ConnectionStatus'] ?? ''), 'Connected') === 0;
                        $dnsParts  = $connected ? explode(',', (string) ($raw['DNSServers'] ?? '')) : [];
                        $entry['dns1'] = $this->cleanAddress($dnsParts[0] ?? null);
                        $entry['dns2'] = $this->cleanAddress($dnsParts[1] ?? null);

                        $connections[] = WanConnection::fromArray($entry);
                    }
                }
            }
        }

        return collect($connections);
    }

    /**
     * Path raiz dos objetos WAN. Subclasses cujo namespace difira do padrão
     * TR-098/InternetGatewayDevice sobrescrevem.
     */
    protected function wanRootPath(): string
    {
        return 'InternetGatewayDevice.WANDevice';
    }

    /**
     * Mapa canônico de uma conexão WAN: chave padronizada → leaf. As leaves
     * podem ser do nível da conexão (WAN{PPP,IP}Connection.{k}) ou do nível
     * WANConnectionDevice (ex.: VLAN/COS proprietários — ver merge em
     * getWanConnections()). Camadas de marca sobrescrevem para `vlan`/`cos`.
     *
     * `type`, `mode`, `gateway`, `dns1`/`dns2` e `ref` são montados em
     * getWanConnections(), não aqui.
     *
     * @return array<string,string>
     */
    protected function wanConnectionMap(): array
    {
        return [
            'name'            => 'Name',
            'enabled'         => 'Enable',
            'status'          => 'ConnectionStatus',
            'connection_type' => 'ConnectionType',
            'ip'              => 'ExternalIPAddress',
            'mac'             => 'MACAddress',
            'uptime'          => 'Uptime',
            'nat'             => 'NATEnabled',
            'username'        => 'Username',
        ];
    }

    /**
     * Perfis de voz (VoIP/SIP) da ONU, com nomes de parâmetro PADRONIZADOS,
     * independentes do fabricante. O TR-104 aninha a config em
     * `Services.VoiceService.1.VoiceProfile.{i}` e cada perfil em
     * `Line.{j}` (as contas SIP) — percorremos toda a árvore e normalizamos o
     * perfil via `voiceProfileMap()` e cada linha via `voiceLineMap()`.
     *
     * O núcleo (proxy/registrar/AuthUserName/URI/DirectoryNumber...) é TR-104
     * padrão e idêntico entre FiberHome e ZTE; extensões proprietárias
     * (standby proxy, DTMF, jitter, IMS/CallingFeatures) entram via override de
     * marca e ficam em `->extra`. A senha SIP é write-only no aparelho e NÃO é
     * lida. Chaves que o device não expõe vêm null → shape igual p/ qualquer ONU.
     *
     * @return Collection<int,VoiceProfile>  indexada pelo nº do VoiceProfile
     */
    public function getVoiceProfiles(int $timeoutMs = 30000): Collection
    {
        $root = $this->voiceServicePath();
        $node = $this->refreshAndReadNode($root, $timeoutMs);

        $profilesNode = $node['VoiceProfile'] ?? null;
        if (! is_array($profilesNode)) {
            return collect();
        }

        $profileMap = $this->voiceProfileMap();
        $lineMap    = $this->voiceLineMap();

        $profiles = [];

        foreach ($profilesNode as $pi => $profileRaw) {                  // VoiceProfile.{i}
            if (! ctype_digit((string) $pi) || ! is_array($profileRaw)) {
                continue;
            }

            // Leaves de nível perfil (exclui Line, multi-instância tratado abaixo).
            $profileLeafSource = $profileRaw;
            unset($profileLeafSource['Line']);

            $entry          = $this->mapInstance($this->flattenLeaves($profileLeafSource), $profileMap);
            $entry['index'] = (int) $pi;
            $entry['ref']   = "{$root}.VoiceProfile.{$pi}";

            // Linhas (contas SIP) do perfil.
            $lines     = [];
            $linesNode = $profileRaw['Line'] ?? null;
            if (is_array($linesNode)) {
                foreach ($linesNode as $li => $lineRaw) {                // Line.{j}
                    if (! ctype_digit((string) $li) || ! is_array($lineRaw)) {
                        continue;
                    }

                    $line            = $this->mapInstance($this->flattenLeaves($lineRaw), $lineMap);
                    $line['profile'] = (int) $pi;
                    $line['line']    = (int) $li;
                    $line['ref']     = "{$root}.VoiceProfile.{$pi}.Line.{$li}";

                    $lines[(int) $li] = VoiceLine::fromArray($line);
                }
                ksort($lines);
            }

            $entry['lines'] = collect($lines);

            $profiles[(int) $pi] = VoiceProfile::fromArray($entry);
        }

        ksort($profiles);

        return collect($profiles);
    }

    /**
     * Apenas as LINHAS (contas SIP) de um perfil de voz — conveniência sobre
     * getVoiceProfiles() quando só interessam as contas, não a config do proxy.
     *
     * @return Collection<int,VoiceLine>  indexada pelo nº da Line
     */
    public function getVoiceLines(int $profile = 1, int $timeoutMs = 30000): Collection
    {
        $p = $this->getVoiceProfiles($timeoutMs)->get($profile);

        return $p?->lines ?? collect();
    }

    /**
     * Path do objeto (instância única) do serviço de voz. Subclasses cujo
     * namespace difira do padrão TR-104/InternetGatewayDevice sobrescrevem.
     */
    protected function voiceServicePath(): string
    {
        return 'InternetGatewayDevice.Services.VoiceService.1';
    }

    /**
     * Mapa canônico de um PERFIL de voz: chave padronizada → leaf relativa a
     * `VoiceProfile.{i}`. Núcleo TR-104 (vale FiberHome/ZTE); camadas de marca
     * acrescentam extensões proprietárias via `array_merge(parent::..., [...])`
     * (que caem em `->extra` do DTO).
     *
     * @return array<string,string>
     */
    protected function voiceProfileMap(): array
    {
        return [
            'name'                => 'Name',
            'enabled'             => 'Enable',                 // enum Enabled/Disabled
            'signaling_protocol'  => 'SignalingProtocol',
            'num_lines'           => 'NumberOfLines',
            'digit_map'           => 'DigitMap',
            'proxy_server'        => 'SIP.ProxyServer',
            'proxy_port'          => 'SIP.ProxyServerPort',
            'registrar_server'    => 'SIP.RegistrarServer',
            'registrar_port'      => 'SIP.RegistrarServerPort',
            'outbound_proxy'      => 'SIP.OutboundProxy',
            'outbound_proxy_port' => 'SIP.OutboundProxyPort',
            'transport'           => 'SIP.ProxyServerTransport',
            'register_expires'    => 'SIP.RegisterExpires',
            'registration_period' => 'SIP.RegistrationPeriod',
            'vlan'                => 'SIP.VLANIDMark',
            'rtp_port_min'        => 'RTP.LocalPortMin',
            'rtp_port_max'        => 'RTP.LocalPortMax',
        ];
    }

    /**
     * Mapa canônico de uma LINHA (conta SIP): chave padronizada → leaf relativa
     * a `VoiceProfile.{i}.Line.{j}`. Núcleo TR-104; marcas acrescentam extensões
     * (IMS/CallingFeatures) via override → `->extra`. `SIP.AuthPassword` é
     * write-only no aparelho (lê vazio) e por isso NÃO é mapeada na leitura.
     *
     * @return array<string,string>
     */
    protected function voiceLineMap(): array
    {
        return [
            'enabled'    => 'Enable',          // enum Enabled/Disabled
            'number'     => 'DirectoryNumber',
            'auth_user'  => 'SIP.AuthUserName',
            'uri'        => 'SIP.URI',
            'status'     => 'Status',
            'call_state' => 'CallState',
        ];
    }

    /**
     * Redes Wi-Fi (SSIDs) da ONU, com nomes de parâmetro PADRONIZADOS,
     * independentes do fabricante. Cada instância de
     * `LANDevice.1.WLANConfiguration.{i}` é um BSS (um SSID numa banda) — uma
     * entrada por instância, normalizada via `wifiNetworkMap()`.
     *
     * O núcleo é TR-098 padrão e idêntico entre FiberHome e ZTE
     * (Enable/SSID/KeyPassphrase/Channel/Standard/BeaconType/TransmitPower/
     * TotalAssociations...); extensões proprietárias entram via override de marca
     * e ficam em `->extra`. `band` ('2.4GHz'/'5GHz') é DERIVADO de
     * PossibleChannels/Channel (não há leaf canônica universal). Chaves que o
     * device não expõe vêm null → shape igual p/ qualquer ONU.
     *
     * @return Collection<int,WifiNetwork>  indexada pelo nº da WLANConfiguration
     */
    public function getWifiNetworks(int $timeoutMs = 30000): Collection
    {
        $root      = $this->wifiRootPath();
        $instances = $this->getObjectInstances($root, $timeoutMs);
        $map       = $this->wifiNetworkMap();

        $networks = [];
        foreach ($instances as $num => $raw) {
            $entry             = $this->mapInstance($raw, $map);
            $entry['instance'] = (int) $num;
            $entry['band']     = $this->deriveWifiBand($raw);
            $entry['ref']      = $root . '.' . $num;

            $networks[$num] = WifiNetwork::fromArray($this->normalizeWifiEntry($entry));
        }

        return collect($networks);
    }

    /**
     * Hook por fabricante para ajustar um item Wi-Fi já mapeado (chaves canônicas)
     * antes de virar DTO. Base = identidade; ex.: FiberHome inverte `broadcast`.
     *
     * @param  array<string,mixed> $entry
     * @return array<string,mixed>
     */
    protected function normalizeWifiEntry(array $entry): array
    {
        return $entry;
    }

    /**
     * Uma rede Wi-Fi específica (conveniência sobre getWifiNetworks()).
     */
    public function getWifiNetwork(int $instance, int $timeoutMs = 30000): ?WifiNetwork
    {
        return $this->getWifiNetworks($timeoutMs)->get($instance);
    }

    /**
     * Path do objeto multi-instância das redes Wi-Fi. Subclasses cujo namespace
     * difira do padrão TR-098/InternetGatewayDevice sobrescrevem.
     */
    protected function wifiRootPath(): string
    {
        return 'InternetGatewayDevice.LANDevice.1.WLANConfiguration';
    }

    /**
     * Mapa canônico de uma rede Wi-Fi: chave padronizada → leaf relativa à
     * instância `WLANConfiguration.{i}`. Núcleo TR-098 (vale FiberHome/ZTE);
     * camadas de marca acrescentam extensões via `array_merge(parent::..., [...])`
     * (que caem em `->extra` do DTO). `band`/`instance`/`ref` são montados em
     * getWifiNetworks(), não aqui.
     *
     * @return array<string,string>
     */
    protected function wifiNetworkMap(): array
    {
        return [
            'enabled'           => 'Enable',
            'ssid'              => 'SSID',
            'password'          => 'KeyPassphrase',
            'bssid'             => 'BSSID',
            'channel'           => 'Channel',
            'auto_channel'      => 'AutoChannelEnable',
            'possible_channels' => 'PossibleChannels',
            'standard'          => 'Standard',
            'security'          => 'BeaconType',
            'broadcast'         => 'SSIDAdvertisementEnabled',
            'tx_power'          => 'TransmitPower',
            'tx_power_supported' => 'TransmitPowerSupported',
            'region'            => 'RegulatoryDomain', // país/região (TR-098 padrão)
            'max_bitrate'       => 'MaxBitRate',
            'auth_mode'         => 'IEEE11iAuthenticationMode',
            'encryption'        => 'IEEE11iEncryptionModes',
            'status'            => 'Status',
            'name'              => 'Name',
            'clients'           => 'TotalAssociations',
            'bytes_sent'        => 'TotalBytesSent',
            'bytes_received'    => 'TotalBytesReceived',
            'radio_enabled'     => 'RadioEnabled',
        ];
    }

    /**
     * Deriva a banda ('2.4GHz'/'5GHz') de uma instância Wi-Fi a partir dos canais
     * físicos (vendor-agnóstico): canais até 14 são 2.4 GHz, 32+ são 5 GHz. Usa
     * o 1º canal de PossibleChannels (separadores `, . -`) e cai no Channel atual.
     *
     * @param array<string,mixed> $raw  instância achatada (getObjectInstances)
     */
    protected function deriveWifiBand(array $raw): ?string
    {
        $possible = (string) ($raw['PossibleChannels'] ?? '');
        if ($possible !== '' && preg_match('/\d+/', $possible, $m)) {
            return ((int) $m[0]) >= 32 ? '5GHz' : '2.4GHz';
        }

        $channel = (int) ($raw['Channel'] ?? 0);
        if ($channel > 0) {
            return $channel > 14 ? '5GHz' : '2.4GHz';
        }

        return null;
    }

    /** Normaliza um endereço (IP/gateway/DNS): nulo quando vazio ou 0.0.0.0. */
    protected function cleanAddress(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : ($value === null ? null : (string) $value);

        return ($value === null || $value === '' || $value === '0.0.0.0') ? null : $value;
    }

    /**
     * Lista as chaves do parameterMap() que começam com um prefixo (ex.: 'lan.',
     * 'wan.', 'wifi.'). Útil para ler um "grupo" de configurações de uma vez.
     *
     * @return string[]
     */
    public function keysWithPrefix(string $prefix): array
    {
        return array_values(array_filter(
            array_keys($this->parameterMap()),
            fn (string $key) => str_starts_with($key, $prefix)
        ));
    }

    /**
     * Get a parameter value by raw TR-069 path.
     *
     * Dispara uma task getParameterValues síncrona para atualizar o valor no
     * documento do device e então o lê de lá (ver readPaths()).
     */
    public function getPath(string $path, int $timeoutMs = 30000): mixed
    {
        return $this->readPaths([$path], $timeoutMs)[$path] ?? null;
    }

    /**
     * Set a parameter value by raw TR-069 path.
     */
    public function setPath(string $path, mixed $value): TaskResponse
    {
        return $this->client->setParameterValues($this->deviceInfo->id, [[$path, $value]]);
    }

    /**
     * Online = acessível pelo ACS AGORA (dispara um connection request com uma
     * task leve; se o device não responder a tempo, está offline).
     *
     * Diferente de `isWanConnected()` nos handlers, que lê o status (possivelmente
     * cacheado) da conexão WAN/internet.
     */
    public function isOnline(int $timeoutMs = 15000): bool
    {
        $probe = $this->pathFor('uptime') ?? 'InternetGatewayDevice.DeviceInfo.UpTime';

        return $this->client->isReachable($this->deviceInfo->id, $probe, $timeoutMs);
    }

    public function reboot(): TaskResponse
    {
        return $this->client->reboot($this->deviceInfo->id);
    }

    public function factoryReset(): TaskResponse
    {
        return $this->client->factoryReset($this->deviceInfo->id);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Achata uma subárvore TR-069 do GenieACS em [ 'a.b.c' => valor ], pegando
     * só os nós folha (com `_value`) e descartando metadados (`_object`,
     * `_timestamp`, `_writable`, ...). Objetos aninhados (ex.: `Stats`) viram
     * chaves pontilhadas.
     *
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    /**
     * Dispara um refreshObject síncrono no objeto (best-effort: se o device
     * estiver offline, segue lendo o cache) e devolve o nó CRU desse ramo do
     * documento do GenieACS, ou [] se ausente.
     *
     * ⚠️ Custo: o refreshObject CONTATA a ONU (connection request CWMP) e pode
     * levar vários segundos. Passe `$timeoutMs <= 0` para PULAR o refresh e ler
     * direto do cache do GenieACS — instantâneo, porém possivelmente defasado
     * (última leitura do device). Vale para todos os get* que passam por aqui.
     *
     * Base compartilhada de getObject() (instância única) e
     * getObjectInstances() (multi-instância).
     *
     * @return array<string,mixed>
     */
    protected function refreshAndReadNode(string $objectPath, int $timeoutMs = 30000): array
    {
        $deviceId = $this->deviceInfo->id;

        if ($timeoutMs > 0) {
            $this->client->executeTask($deviceId, [
                'name'       => 'refreshObject',
                'objectName' => $objectPath,
            ], $timeoutMs);
        }

        $node = $this->client->getDevice($deviceId, $objectPath)->getParameter($objectPath);

        return is_array($node) ? $node : [];
    }

    protected function flattenLeaves(array $node, string $prefix = ''): array
    {
        $out = [];

        foreach ($node as $key => $child) {
            if (str_starts_with((string) $key, '_')) {
                continue; // metadados do GenieACS
            }
            if (!is_array($child)) {
                continue;
            }
            if (array_key_exists('_value', $child)) {
                $out[$prefix . $key] = $child['_value'];
                continue;
            }
            // Objeto aninhado — recursão (ex.: Stats.BytesSent).
            $out += $this->flattenLeaves($child, $prefix . $key . '.');
        }

        return $out;
    }

    /**
     * Normaliza uma instância achatada para chaves canônicas, dado um mapa
     * `chave_canônica => leaf_relativa`. Leaves ausentes no device viram null,
     * garantindo o MESMO shape entre fabricantes.
     *
     * @param  array<string,mixed>   $raw  saída achatada de uma instância
     * @param  array<string,string>  $map  chave canônica → leaf relativa
     * @return array<string,mixed>
     */
    protected function mapInstance(array $raw, array $map): array
    {
        $out = [];
        foreach ($map as $canonical => $relPath) {
            $out[$canonical] = $raw[$relPath] ?? null;
        }

        return $out;
    }

    /**
     * Lê os valores ATUALIZADOS de um conjunto de paths TR-069.
     *
     * O endpoint de criação de task do GenieACS retorna apenas a task (não os
     * valores); os valores lidos caem no DOCUMENTO do device. Por isso aqui:
     *   1. dispara UMA task getParameterValues síncrona (connection_request +
     *      timeout) para o device atualizar o documento no GenieACS;
     *   2. lê os valores desse documento via getDevice()/getParameter().
     *
     * Best-effort: se o device não responder a tempo (offline), não lança —
     * retorna os últimos valores em cache do documento (null para paths nunca
     * lidos).
     *
     * @param  string[]  $paths
     * @return array<string,mixed>  [ path => value ]
     */
    protected function readPaths(array $paths, int $timeoutMs = 30000): array
    {
        $paths = array_values(array_unique($paths));

        if ($paths === []) {
            return [];
        }

        $deviceId = $this->deviceInfo->id;

        // Atualiza os valores no documento do device (síncrono via connection
        // request); ignoramos o retorno para ainda ler o cache se estiver offline.
        $this->client->executeTask($deviceId, [
            'name'           => 'getParameterValues',
            'parameterNames' => $paths,
        ], $timeoutMs);

        $response = $this->client->getDevice($deviceId, implode(',', $paths));

        $values = [];
        foreach ($paths as $path) {
            $values[$path] = $response->getParameter($path);
        }

        return $values;
    }

    protected function resolvePath(string $key): string
    {
        $map = $this->parameterMap();

        if (!array_key_exists($key, $map)) {
            throw new \InvalidArgumentException(
                "Parameter key '{$key}' is not mapped for device {$this->vendor()} {$this->model()} ({$this->firmwareVersion()})."
            );
        }

        return $map[$key];
    }
}
