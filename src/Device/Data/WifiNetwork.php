<?php

namespace Plimsistemas\TR069\Device\Data;

/**
 * DTO tipado de uma rede Wi-Fi (uma instância de WLANConfiguration) de uma ONU —
 * item de {@see \Plimsistemas\TR069\Device\AbstractDevice::getWifiNetworks()}.
 *
 * Cada instância `LANDevice.1.WLANConfiguration.{i}` é um BSS (um SSID numa
 * banda). Convenção observada (FiberHome/ZTE): instâncias 1–4 = 2.4 GHz e
 * 5–8 = 5 GHz, sendo a 1ª de cada faixa a principal e as demais SSIDs extras.
 *
 * Os campos canônicos são TR-098 padrão (idênticos entre FiberHome e ZTE);
 * extensões proprietárias (banda/largura X_ZTE-COM_*, etc.) entram via override
 * de `wifiNetworkMap()` e ficam em $extra. `band` é DERIVADO (não é uma leaf):
 * resolvido a partir de PossibleChannels/Channel pelo reader.
 *
 * Observação: ao contrário da senha SIP (VoIP), a senha do Wi-Fi
 * (`KeyPassphrase`) é LEGÍVEL nestes modelos.
 */
final class WifiNetwork
{
    /**
     * @param array<string,mixed> $extra  campos específicos de fabricante
     */
    public function __construct(
        public readonly ?int    $instance = null,         // nº da WLANConfiguration
        public readonly ?string $band = null,             // '2.4GHz' | '5GHz' (derivado)
        public readonly ?bool   $enabled = null,          // SSID habilitado (Enable)
        public readonly ?string $ssid = null,
        public readonly ?string $password = null,         // KeyPassphrase
        public readonly ?string $bssid = null,            // MAC do BSS
        public readonly ?int    $channel = null,
        public readonly ?bool   $autoChannel = null,
        public readonly ?string $possibleChannels = null,
        public readonly ?string $standard = null,         // modos: b,g,n / a,n,ac...
        public readonly ?string $security = null,         // BeaconType (vocab varia por marca)
        public readonly ?bool   $broadcast = null,        // SSIDAdvertisementEnabled (false = oculto)
        public readonly ?int    $txPower = null,          // TransmitPower (%)
        public readonly ?string $maxBitrate = null,
        public readonly ?string $authMode = null,         // IEEE11iAuthenticationMode
        public readonly ?string $encryption = null,       // IEEE11iEncryptionModes
        public readonly ?string $status = null,           // Up/Down/...
        public readonly ?string $name = null,
        public readonly ?int    $clients = null,          // TotalAssociations
        public readonly ?int    $bytesSent = null,
        public readonly ?int    $bytesReceived = null,
        public readonly ?bool   $radioEnabled = null,     // RadioEnabled (rádio físico)
        public readonly ?string $ref = null,              // path da instância (reuso)
        public readonly array   $extra = [],
    ) {}

    /**
     * @param array<string,mixed> $data  item canônico de getWifiNetworks()
     */
    public static function fromArray(array $data): self
    {
        $base = [
            'instance', 'band', 'enabled', 'ssid', 'password', 'bssid', 'channel',
            'auto_channel', 'possible_channels', 'standard', 'security', 'broadcast',
            'tx_power', 'max_bitrate', 'auth_mode', 'encryption', 'status', 'name',
            'clients', 'bytes_sent', 'bytes_received', 'radio_enabled', 'ref',
        ];

        return new self(
            instance:         self::asInt($data['instance'] ?? null),
            band:             self::asString($data['band'] ?? null),
            enabled:          self::asBool($data['enabled'] ?? null),
            ssid:             self::asString($data['ssid'] ?? null),
            password:         self::asString($data['password'] ?? null),
            bssid:            self::asString($data['bssid'] ?? null),
            channel:          self::asInt($data['channel'] ?? null),
            autoChannel:      self::asBool($data['auto_channel'] ?? null),
            possibleChannels: self::asString($data['possible_channels'] ?? null),
            standard:         self::asString($data['standard'] ?? null),
            security:         self::asString($data['security'] ?? null),
            broadcast:        self::asBool($data['broadcast'] ?? null),
            txPower:          self::asInt($data['tx_power'] ?? null),
            maxBitrate:       self::asString($data['max_bitrate'] ?? null),
            authMode:         self::asString($data['auth_mode'] ?? null),
            encryption:       self::asString($data['encryption'] ?? null),
            status:           self::asString($data['status'] ?? null),
            name:             self::asString($data['name'] ?? null),
            clients:          self::asInt($data['clients'] ?? null),
            bytesSent:        self::asInt($data['bytes_sent'] ?? null),
            bytesReceived:    self::asInt($data['bytes_received'] ?? null),
            radioEnabled:     self::asBool($data['radio_enabled'] ?? null),
            ref:              self::asString($data['ref'] ?? null),
            extra:            array_diff_key($data, array_flip($base)),
        );
    }

    /** Conveniência: SSID oculto (não anunciado no beacon). */
    public function isHidden(): bool
    {
        return $this->broadcast === false;
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
            'instance'          => $this->instance,
            'band'              => $this->band,
            'enabled'           => $this->enabled,
            'ssid'              => $this->ssid,
            'password'          => $this->password,
            'bssid'             => $this->bssid,
            'channel'           => $this->channel,
            'auto_channel'      => $this->autoChannel,
            'possible_channels' => $this->possibleChannels,
            'standard'          => $this->standard,
            'security'          => $this->security,
            'broadcast'         => $this->broadcast,
            'tx_power'          => $this->txPower,
            'max_bitrate'       => $this->maxBitrate,
            'auth_mode'         => $this->authMode,
            'encryption'        => $this->encryption,
            'status'            => $this->status,
            'name'              => $this->name,
            'clients'           => $this->clients,
            'bytes_sent'        => $this->bytesSent,
            'bytes_received'    => $this->bytesReceived,
            'radio_enabled'     => $this->radioEnabled,
            'ref'               => $this->ref,
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
