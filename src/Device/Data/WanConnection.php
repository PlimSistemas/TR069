<?php

namespace Plimsistemas\TR069\Device\Data;

/**
 * DTO tipado de uma conexão WAN de uma ONU — item da saída de
 * {@see \Plimsistemas\TR069\Device\AbstractDevice::getWanConnections()}.
 *
 * Conceitos (não confundir):
 * - `type`           — estrutura TR-069: 'ppp' (WANPPPConnection) | 'ip' (WANIPConnection).
 * - `mode`           — modo de endereçamento: 'PPPOE' | 'DHCP' | 'STATIC' (AddressingType;
 *                      derivado 'PPPOE' nas conexões PPP).
 * - `connectionType` — encaminhamento: 'IP_Routed' | 'IP_Bridged' | 'PPPoE_Bridged' (ConnectionType).
 *
 * `username` só existe em PPPoE. `vlan`/`cos` são proprietários de fabricante
 * (resolvidos via override de `wanConnectionMap()`). `dns1`/`dns2` já vêm
 * separados e nulos quando inválidos (0.0.0.0) ou conexão não conectada.
 *
 * Campos específicos de fabricante extras ficam em $extra.
 */
final class WanConnection
{
    /**
     * @param array<string,mixed> $extra  campos específicos de fabricante
     */
    public function __construct(
        public readonly ?int    $index = null,           // nº do WANConnectionDevice
        public readonly ?string $name = null,
        public readonly ?bool   $enabled = null,
        public readonly ?string $status = null,
        public readonly ?string $type = null,            // 'ppp' | 'ip'
        public readonly ?string $mode = null,            // PPPOE | DHCP | STATIC
        public readonly ?string $connectionType = null,  // IP_Routed | IP_Bridged | PPPoE_Bridged
        public readonly ?string $service = null,         // INTERNET | TR069 | VOIP | combinações
        public readonly ?int    $vlan = null,
        public readonly ?int    $cos = null,
        public readonly ?string $ip = null,
        public readonly ?string $gateway = null,
        public readonly ?string $mac = null,
        public readonly ?string $dns1 = null,
        public readonly ?string $dns2 = null,
        public readonly ?int    $uptime = null,
        public readonly ?bool   $nat = null,
        public readonly ?string $username = null,
        public readonly ?string $ref = null,             // path da instância (reuso)
        public readonly array   $extra = [],
    ) {}

    /**
     * @param array<string,mixed> $data  item canônico de getWanConnections()
     */
    public static function fromArray(array $data): self
    {
        $base = [
            'index', 'name', 'enabled', 'status', 'type', 'mode', 'connection_type',
            'service', 'vlan', 'cos', 'ip', 'gateway', 'mac', 'dns1', 'dns2',
            'uptime', 'nat', 'username', 'ref',
        ];

        return new self(
            index:          self::asInt($data['index'] ?? null),
            name:           self::asString($data['name'] ?? null),
            enabled:        self::asBool($data['enabled'] ?? null),
            status:         self::asString($data['status'] ?? null),
            type:           self::asString($data['type'] ?? null),
            mode:           self::asString($data['mode'] ?? null),
            connectionType: self::asString($data['connection_type'] ?? null),
            service:        self::asString($data['service'] ?? null),
            vlan:           self::asInt($data['vlan'] ?? null),
            cos:            self::asInt($data['cos'] ?? null),
            ip:             self::asString($data['ip'] ?? null),
            gateway:        self::asString($data['gateway'] ?? null),
            mac:            self::asString($data['mac'] ?? null),
            dns1:           self::asString($data['dns1'] ?? null),
            dns2:           self::asString($data['dns2'] ?? null),
            uptime:         self::asInt($data['uptime'] ?? null),
            nat:            self::asBool($data['nat'] ?? null),
            username:       self::asString($data['username'] ?? null),
            ref:            self::asString($data['ref'] ?? null),
            extra:          array_diff_key($data, array_flip($base)),
        );
    }

    /**
     * Conveniência: a conexão está ativa (status "Connected").
     */
    public function isConnected(): bool
    {
        return strcasecmp((string) $this->status, 'Connected') === 0;
    }

    /**
     * Lista de servidores DNS válidos (dns1/dns2 não-nulos).
     *
     * @return string[]
     */
    public function dnsServers(): array
    {
        return array_values(array_filter([$this->dns1, $this->dns2]));
    }

    public function extra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'index'           => $this->index,
            'name'            => $this->name,
            'enabled'         => $this->enabled,
            'status'          => $this->status,
            'type'            => $this->type,
            'mode'            => $this->mode,
            'connection_type' => $this->connectionType,
            'service'         => $this->service,
            'vlan'            => $this->vlan,
            'cos'             => $this->cos,
            'ip'              => $this->ip,
            'gateway'         => $this->gateway,
            'mac'             => $this->mac,
            'dns1'            => $this->dns1,
            'dns2'            => $this->dns2,
            'uptime'          => $this->uptime,
            'nat'             => $this->nat,
            'username'        => $this->username,
            'ref'             => $this->ref,
            ...$this->extra,
        ];
    }

    private static function asString(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    private static function asInt(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private static function asBool(mixed $v): ?bool
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }

        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'enabled', 'on'], true);
    }
}
