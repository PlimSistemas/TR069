<?php

namespace Plimsistemas\TR069\Device\Data;

/**
 * DTO tipado da configuração LAN/DHCP de uma ONU (saída de
 * {@see \Plimsistemas\TR069\Device\AbstractDevice::getLanConfig()}).
 *
 * Garante o CONTRATO dos campos base (mesmos em qualquer fabricante), já com os
 * tipos corretos — útil ao consumir o pacote em outros backends sem depender da
 * forma crua do GenieACS. Campos específicos de fabricante (ex.: `isp_dns` da
 * ZTE) não fazem parte do contrato fixo e ficam em {@see self::$extra}, pois não
 * existem em todos os devices.
 */
final class LanConfig
{
    /**
     * @param array<string,mixed> $extra  campos específicos de fabricante
     *                                     (ex.: ['isp_dns' => true])
     */
    public function __construct(
        public readonly ?string $ip = null,
        public readonly ?string $subnet = null,
        public readonly ?string $dns = null,
        public readonly ?bool   $dhcpEnabled = null,
        public readonly ?string $dhcpStart = null,
        public readonly ?string $dhcpEnd = null,
        public readonly ?int    $dhcpLease = null,
        public readonly array   $extra = [],
    ) {}

    /**
     * Hidrata o DTO a partir da saída canônica (array) de getLanConfig(),
     * normalizando tipos. Chaves fora do contrato base caem em $extra.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $base = ['ip', 'subnet', 'dns', 'dhcp_enabled', 'dhcp_start', 'dhcp_end', 'dhcp_lease'];

        return new self(
            ip:          self::asString($data['ip'] ?? null),
            subnet:      self::asString($data['subnet'] ?? null),
            dns:         self::asString($data['dns'] ?? null),
            dhcpEnabled: self::asBool($data['dhcp_enabled'] ?? null),
            dhcpStart:   self::asString($data['dhcp_start'] ?? null),
            dhcpEnd:     self::asString($data['dhcp_end'] ?? null),
            dhcpLease:   self::asInt($data['dhcp_lease'] ?? null),
            extra:       array_diff_key($data, array_flip($base)),
        );
    }

    /**
     * Lista de servidores DNS já separada (o device os reporta como string
     * separada por vírgula).
     *
     * @return string[]
     */
    public function dnsServers(): array
    {
        if ($this->dns === null || $this->dns === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->dns))));
    }

    /**
     * Acessa um campo específico de fabricante de forma segura.
     */
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
            'ip'           => $this->ip,
            'subnet'       => $this->subnet,
            'dns'          => $this->dns,
            'dhcp_enabled' => $this->dhcpEnabled,
            'dhcp_start'   => $this->dhcpStart,
            'dhcp_end'     => $this->dhcpEnd,
            'dhcp_lease'   => $this->dhcpLease,
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
