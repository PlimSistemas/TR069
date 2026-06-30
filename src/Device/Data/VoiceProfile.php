<?php

namespace Plimsistemas\TR069\Device\Data;

use Illuminate\Support\Collection;

/**
 * DTO tipado de um PERFIL de voz de uma ONU — item de
 * {@see \Plimsistemas\TR069\Device\AbstractDevice::getVoiceProfiles()}.
 *
 * Mapeia `InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{i}` do
 * TR-104. O perfil agrupa a configuração SIP do softswitch (proxy/registrar/
 * outbound + transporte/expira) e a faixa de portas RTP; as contas SIP em si
 * ficam em `lines` ({@see VoiceLine}).
 *
 * Os campos canônicos são padrão TR-104 (idênticos entre FiberHome e ZTE);
 * extensões proprietárias (standby proxy X_FH_ / X_ZTE-COM_, DTMF, jitter...)
 * são resolvidas via override de `voiceProfileMap()` e ficam em $extra.
 */
final class VoiceProfile
{
    /**
     * @param Collection<int,VoiceLine> $lines
     * @param array<string,mixed>       $extra  campos específicos de fabricante
     */
    public function __construct(
        public readonly ?int    $index = null,             // nº do VoiceProfile
        public readonly ?string $name = null,
        public readonly ?bool   $enabled = null,
        public readonly ?string $signalingProtocol = null, // SIP | H.248
        public readonly ?int    $numLines = null,          // NumberOfLines
        public readonly ?string $digitMap = null,
        public readonly ?string $proxyServer = null,
        public readonly ?int    $proxyPort = null,
        public readonly ?string $registrarServer = null,
        public readonly ?int    $registrarPort = null,
        public readonly ?string $outboundProxy = null,
        public readonly ?int    $outboundProxyPort = null,
        public readonly ?string $transport = null,         // UDP | TCP | TLS
        public readonly ?int    $registerExpires = null,
        public readonly ?int    $registrationPeriod = null,
        public readonly ?int    $vlan = null,              // SIP.VLANIDMark (-1 = não usado na ZTE)
        public readonly ?int    $rtpPortMin = null,
        public readonly ?int    $rtpPortMax = null,
        public readonly ?string $ref = null,               // path da instância (reuso)
        public readonly ?Collection $lines = null,
        public readonly array   $extra = [],
    ) {}

    /**
     * @param array<string,mixed> $data  item canônico de getVoiceProfiles()
     */
    public static function fromArray(array $data): self
    {
        $base = [
            'index', 'name', 'enabled', 'signaling_protocol', 'num_lines',
            'digit_map', 'proxy_server', 'proxy_port', 'registrar_server',
            'registrar_port', 'outbound_proxy', 'outbound_proxy_port',
            'transport', 'register_expires', 'registration_period', 'vlan',
            'rtp_port_min', 'rtp_port_max', 'ref', 'lines',
        ];

        $lines = $data['lines'] ?? null;

        return new self(
            index:              self::asInt($data['index'] ?? null),
            name:               self::asString($data['name'] ?? null),
            enabled:            self::asBool($data['enabled'] ?? null),
            signalingProtocol:  self::asString($data['signaling_protocol'] ?? null),
            numLines:           self::asInt($data['num_lines'] ?? null),
            digitMap:           self::asString($data['digit_map'] ?? null),
            proxyServer:        self::asString($data['proxy_server'] ?? null),
            proxyPort:          self::asInt($data['proxy_port'] ?? null),
            registrarServer:    self::asString($data['registrar_server'] ?? null),
            registrarPort:      self::asInt($data['registrar_port'] ?? null),
            outboundProxy:      self::asString($data['outbound_proxy'] ?? null),
            outboundProxyPort:  self::asInt($data['outbound_proxy_port'] ?? null),
            transport:          self::asString($data['transport'] ?? null),
            registerExpires:    self::asInt($data['register_expires'] ?? null),
            registrationPeriod: self::asInt($data['registration_period'] ?? null),
            vlan:               self::asInt($data['vlan'] ?? null),
            rtpPortMin:         self::asInt($data['rtp_port_min'] ?? null),
            rtpPortMax:         self::asInt($data['rtp_port_max'] ?? null),
            ref:                self::asString($data['ref'] ?? null),
            lines:              $lines instanceof Collection ? $lines : collect($lines ?? []),
            extra:              array_diff_key($data, array_flip($base)),
        );
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
            'index'               => $this->index,
            'name'                => $this->name,
            'enabled'             => $this->enabled,
            'signaling_protocol'  => $this->signalingProtocol,
            'num_lines'           => $this->numLines,
            'digit_map'           => $this->digitMap,
            'proxy_server'        => $this->proxyServer,
            'proxy_port'          => $this->proxyPort,
            'registrar_server'    => $this->registrarServer,
            'registrar_port'      => $this->registrarPort,
            'outbound_proxy'      => $this->outboundProxy,
            'outbound_proxy_port' => $this->outboundProxyPort,
            'transport'           => $this->transport,
            'register_expires'    => $this->registerExpires,
            'registration_period' => $this->registrationPeriod,
            'vlan'                => $this->vlan,
            'rtp_port_min'        => $this->rtpPortMin,
            'rtp_port_max'        => $this->rtpPortMax,
            'ref'                 => $this->ref,
            'lines'               => ($this->lines ?? collect())->map(
                fn (VoiceLine $l) => $l->toArray()
            )->values()->all(),
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
