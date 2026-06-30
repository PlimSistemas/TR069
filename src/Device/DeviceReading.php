<?php

namespace Plimsistemas\TR069\Device;

/**
 * Resultado de um fetch de parâmetros (ParameterFetch::execute()).
 *
 * Guarda os valores lidos por chave canônica e expõe acessadores tipados,
 * independentes de fabricante/firmware. Chaves não solicitadas/não mapeadas
 * retornam null nos getters.
 */
class DeviceReading
{
    /**
     * @param array<string,mixed> $values  chave canônica => valor lido
     */
    public function __construct(protected array $values = [])
    {
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }

    // -------------------------------------------------------------------------
    // Acessadores tipados genéricos
    // -------------------------------------------------------------------------

    public function getUptime(): ?int
    {
        $v = $this->get(ParameterFetch::UPTIME);
        return $v === null ? null : (int) $v;
    }

    public function getTxPower(): ?float
    {
        $v = $this->get(ParameterFetch::TX_POWER);
        return $v === null ? null : (float) $v;
    }

    public function getRxPower(): ?float
    {
        $v = $this->get(ParameterFetch::RX_POWER);
        return $v === null ? null : (float) $v;
    }

    public function getSwVersion(): ?string
    {
        $v = $this->get(ParameterFetch::SW_VERSION);
        return $v === null ? null : (string) $v;
    }

    public function getHwVersion(): ?string
    {
        $v = $this->get(ParameterFetch::HW_VERSION);
        return $v === null ? null : (string) $v;
    }

    public function getGponSn(): ?string
    {
        $v = $this->get(ParameterFetch::GPON_SN);
        return $v === null ? null : (string) $v;
    }
}
