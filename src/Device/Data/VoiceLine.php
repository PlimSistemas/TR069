<?php

namespace Plimsistemas\TR069\Device\Data;

/**
 * DTO tipado de uma LINHA de voz (conta SIP) de uma ONU — item de
 * {@see \Plimsistemas\TR069\Device\AbstractDevice::getVoiceLines()} e da coleção
 * `lines` de {@see VoiceProfile}.
 *
 * Mapeia `VoiceProfile.{profile}.Line.{line}` do TR-104. Os campos canônicos
 * (`number`, `authUser`, `uri`, `status`...) são padrão TR-104 e idênticos entre
 * fabricantes; extensões proprietárias (X_FH_IMS.*, CallingFeatures.* da ZTE)
 * são resolvidas via override de `voiceLineMap()` e ficam em $extra.
 *
 * `enabled` deriva do enum TR-104 `Enabled`/`Disabled`. A senha SIP
 * (`SIP.AuthPassword`) é write-only no aparelho (lê vazio) e por isso NÃO é
 * exposta na leitura.
 */
final class VoiceLine
{
    /**
     * @param array<string,mixed> $extra  campos específicos de fabricante
     */
    public function __construct(
        public readonly ?int    $profile = null,    // nº do VoiceProfile
        public readonly ?int    $line = null,        // nº da Line (porta FXS)
        public readonly ?bool   $enabled = null,
        public readonly ?string $number = null,      // DirectoryNumber
        public readonly ?string $authUser = null,    // SIP.AuthUserName
        public readonly ?string $uri = null,         // SIP.URI (AOR)
        public readonly ?string $status = null,      // Status (registro: Up/Down/...)
        public readonly ?string $callState = null,   // CallState (Idle/InCall/...)
        public readonly ?string $ref = null,         // path da instância (reuso)
        public readonly array   $extra = [],
    ) {}

    /**
     * @param array<string,mixed> $data  item canônico de getVoiceLines()
     */
    public static function fromArray(array $data): self
    {
        $base = [
            'profile', 'line', 'enabled', 'number', 'auth_user', 'uri',
            'status', 'call_state', 'ref',
        ];

        return new self(
            profile:   self::asInt($data['profile'] ?? null),
            line:      self::asInt($data['line'] ?? null),
            enabled:   self::asBool($data['enabled'] ?? null),
            number:    self::asString($data['number'] ?? null),
            authUser:  self::asString($data['auth_user'] ?? null),
            uri:       self::asString($data['uri'] ?? null),
            status:    self::asString($data['status'] ?? null),
            callState: self::asString($data['call_state'] ?? null),
            ref:       self::asString($data['ref'] ?? null),
            extra:     array_diff_key($data, array_flip($base)),
        );
    }

    /**
     * Conveniência: a linha está registrada no softswitch (Status "Up"/"Registered").
     */
    public function isRegistered(): bool
    {
        $s = strtolower((string) $this->status);

        return in_array($s, ['up', 'registered'], true);
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
            'profile'    => $this->profile,
            'line'       => $this->line,
            'enabled'    => $this->enabled,
            'number'     => $this->number,
            'auth_user'  => $this->authUser,
            'uri'        => $this->uri,
            'status'     => $this->status,
            'call_state' => $this->callState,
            'ref'        => $this->ref,
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
