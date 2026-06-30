<?php

namespace Plimsistemas\TR069\Device;

use Plimsistemas\TR069\Exceptions\TaskException;
use Plimsistemas\TR069\GenieACS\Client;

/**
 * Builder fluente para obter dados ATUALIZADOS de um dispositivo, de forma
 * genérica (agnóstica de fabricante/firmware).
 *
 * Fluxo:
 *   1. Selecione as chaves canônicas desejadas (uptime, txPower, ...).
 *   2. execute(): dispara UMA task getParameterValues com connection_request
 *      (sem enfileirar). Se o device não responder, a task é descartada.
 *   3. Leia os valores no DeviceReading retornado.
 *
 * Exemplo:
 *   $reading = $device->fetch()
 *       ->uptime()->txPower()->rxPower()->swVersion()->hwVersion()
 *       ->execute();
 *
 *   $reading->getRxPower();  // float|null
 */
class ParameterFetch
{
    // Chaves canônicas — cada handler mapeia para o path TR-069 do fabricante.
    public const UPTIME     = 'uptime';
    public const TX_POWER   = 'optical.tx_power';
    public const RX_POWER   = 'optical.rx_power';
    public const SW_VERSION = 'sw_version';
    public const HW_VERSION = 'hw_version';
    public const GPON_SN    = 'gpon_sn';

    /** @var string[] */
    protected array $keys = [];

    protected int $timeout = 30000;

    public function __construct(
        protected AbstractDevice $device,
        protected Client $client,
    ) {}

    // -------------------------------------------------------------------------
    // Seleção de parâmetros
    // -------------------------------------------------------------------------

    public function add(string ...$keys): static
    {
        foreach ($keys as $key) {
            $this->keys[] = $key;
        }
        return $this;
    }

    public function uptime(): static     { return $this->add(self::UPTIME); }
    public function txPower(): static     { return $this->add(self::TX_POWER); }
    public function rxPower(): static     { return $this->add(self::RX_POWER); }
    public function swVersion(): static    { return $this->add(self::SW_VERSION); }
    public function hwVersion(): static    { return $this->add(self::HW_VERSION); }
    public function gponSn(): static       { return $this->add(self::GPON_SN); }

    /** Tempo máximo (ms) que o GenieACS aguarda o device executar a task. */
    public function timeout(int $milliseconds): static
    {
        $this->timeout = $milliseconds;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Execução
    // -------------------------------------------------------------------------

    /**
     * Dispara a task (sem enfileirar) e lê os valores atualizados.
     *
     * @throws TaskException quando o device não executa a task a tempo.
     */
    public function execute(): DeviceReading
    {
        // Resolve as chaves canônicas para paths TR-069; ignora as não mapeadas.
        $map = [];
        foreach (array_unique($this->keys) as $key) {
            $path = $this->device->pathFor($key);
            if ($path !== null) {
                $map[$key] = $path;
            }
        }

        if ($map === []) {
            throw new \InvalidArgumentException(
                'Nenhum parâmetro mapeado para este dispositivo. Selecione ao menos um.'
            );
        }

        $deviceId = $this->device->info()->id;

        // Uma única task com todos os parâmetros, executada via connection_request.
        $executed = $this->client->executeTask($deviceId, [
            'name'           => 'getParameterValues',
            'parameterNames' => array_values($map),
        ], $this->timeout);

        if (!$executed) {
            throw TaskException::failed(
                'getParameterValues',
                "device {$deviceId} não respondeu ao connection request a tempo"
            );
        }

        // Lê os valores recém-atualizados do device.
        $response = $this->client->getDevice($deviceId, implode(',', array_values($map)));

        $values = [];
        foreach ($map as $key => $path) {
            $values[$key] = $response->getParameter($path);
        }

        return new DeviceReading($values);
    }
}
