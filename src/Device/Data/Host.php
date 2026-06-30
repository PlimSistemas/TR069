<?php

namespace Plimsistemas\TR069\Device\Data;

/**
 * DTO tipado de um host conectado na LAN — item da saída de
 * {@see \Plimsistemas\TR069\Device\AbstractDevice::getHosts()}.
 *
 * `interface` é o NOME amigável da interface (SSID do Wi-Fi ou nome da porta,
 * ex.: `eth2`), resolvido da referência `Layer2Interface`; `interfaceType` é o
 * tipo (Ethernet/802.11) e `interfaceRef` o path normalizado dessa referência
 * (casa diretamente com {@see LanPort::$interfaceRef}).
 *
 * Campos específicos de fabricante (overrides em hostMap()) ficam em $extra.
 */
final class Host
{
    /**
     * @param array<string,mixed> $extra  campos específicos de fabricante
     */
    public function __construct(
        public readonly ?string $hostname = null,
        public readonly ?string $ip = null,
        public readonly ?string $mac = null,
        public readonly ?bool   $active = null,
        public readonly ?string $interface = null,
        public readonly ?string $interfaceType = null,
        public readonly ?string $interfaceRef = null,
        public readonly ?string $addressSource = null,
        public readonly ?int    $leaseRemaining = null,
        public readonly array   $extra = [],
    ) {}

    /**
     * @param array<string,mixed> $data  item canônico de getHosts()
     */
    public static function fromArray(array $data): self
    {
        $base = [
            'hostname', 'ip', 'mac', 'active', 'interface',
            'interface_type', 'interface_ref', 'address_source', 'lease_remaining',
        ];

        return new self(
            hostname:       self::asString($data['hostname'] ?? null),
            ip:             self::asString($data['ip'] ?? null),
            mac:            self::asString($data['mac'] ?? null),
            active:         self::asBool($data['active'] ?? null),
            interface:      self::asString($data['interface'] ?? null),
            interfaceType:  self::asString($data['interface_type'] ?? null),
            interfaceRef:   self::asString($data['interface_ref'] ?? null),
            addressSource:  self::asString($data['address_source'] ?? null),
            leaseRemaining: self::asInt($data['lease_remaining'] ?? null),
            extra:          array_diff_key($data, array_flip($base)),
        );
    }

    /**
     * Conveniência: o host está conectado por Wi-Fi (interface 802.11).
     */
    public function isWireless(): bool
    {
        return str_contains((string) $this->interfaceType, '802.11');
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
            'hostname'        => $this->hostname,
            'ip'              => $this->ip,
            'mac'             => $this->mac,
            'active'          => $this->active,
            'interface'       => $this->interface,
            'interface_type'  => $this->interfaceType,
            'interface_ref'   => $this->interfaceRef,
            'address_source'  => $this->addressSource,
            'lease_remaining' => $this->leaseRemaining,
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
