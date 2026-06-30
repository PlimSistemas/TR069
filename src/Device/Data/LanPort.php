<?php

namespace Plimsistemas\TR069\Device\Data;

/**
 * DTO tipado de uma porta física LAN (Ethernet) de uma ONU — item da saída de
 * {@see \Plimsistemas\TR069\Device\AbstractDevice::getLanPorts()}.
 *
 * Campos base são o contrato comum a qualquer fabricante; campos específicos
 * (overrides de marca/modelo em lanPortMap()) ficam em {@see self::$extra}.
 *
 * `speed` é string porque o device pode reportar tanto um valor numérico
 * ("100", "1000") quanto "Auto".
 */
final class LanPort
{
    /**
     * @param array<string,mixed> $extra  campos específicos de fabricante
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?bool   $enabled = null,
        public readonly ?string $status = null,
        public readonly ?string $mac = null,
        public readonly ?string $speed = null,
        public readonly ?string $duplex = null,
        public readonly ?int    $bytesSent = null,
        public readonly ?int    $bytesReceived = null,
        public readonly ?int    $packetsSent = null,
        public readonly ?int    $packetsReceived = null,
        public readonly ?int    $errorsSent = null,
        public readonly ?int    $errorsReceived = null,
        public readonly ?string $interfaceRef = null,
        public readonly array   $extra = [],
    ) {}

    /**
     * @param array<string,mixed> $data  item canônico de getLanPorts()
     */
    public static function fromArray(array $data): self
    {
        $base = [
            'name', 'enabled', 'status', 'mac', 'speed', 'duplex',
            'bytes_sent', 'bytes_received', 'packets_sent', 'packets_received',
            'errors_sent', 'errors_received', 'interface_ref',
        ];

        return new self(
            name:            self::asString($data['name'] ?? null),
            enabled:         self::asBool($data['enabled'] ?? null),
            status:          self::asString($data['status'] ?? null),
            mac:             self::asString($data['mac'] ?? null),
            speed:           self::asString($data['speed'] ?? null),
            duplex:          self::asString($data['duplex'] ?? null),
            bytesSent:       self::asInt($data['bytes_sent'] ?? null),
            bytesReceived:   self::asInt($data['bytes_received'] ?? null),
            packetsSent:     self::asInt($data['packets_sent'] ?? null),
            packetsReceived: self::asInt($data['packets_received'] ?? null),
            errorsSent:      self::asInt($data['errors_sent'] ?? null),
            errorsReceived:  self::asInt($data['errors_received'] ?? null),
            interfaceRef:    self::asString($data['interface_ref'] ?? null),
            extra:           array_diff_key($data, array_flip($base)),
        );
    }

    /**
     * Conveniência: a porta tem link ativo (status "Up").
     */
    public function isUp(): bool
    {
        return strcasecmp((string) $this->status, 'Up') === 0;
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
            'name'             => $this->name,
            'enabled'          => $this->enabled,
            'status'           => $this->status,
            'mac'              => $this->mac,
            'speed'            => $this->speed,
            'duplex'           => $this->duplex,
            'bytes_sent'       => $this->bytesSent,
            'bytes_received'   => $this->bytesReceived,
            'packets_sent'     => $this->packetsSent,
            'packets_received' => $this->packetsReceived,
            'errors_sent'      => $this->errorsSent,
            'errors_received'  => $this->errorsReceived,
            'interface_ref'    => $this->interfaceRef,
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
