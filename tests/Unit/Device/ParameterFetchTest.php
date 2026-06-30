<?php

namespace Plimsistemas\TR069\Tests\Unit\Device;

use Plimsistemas\TR069\Device\AbstractDevice;
use Plimsistemas\TR069\Device\DeviceInfo;
use Plimsistemas\TR069\Exceptions\TaskException;
use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;
use PHPUnit\Framework\TestCase;

class ParameterFetchTest extends TestCase
{
    /**
     * Client fake: não toca na rede. Registra a task executada e devolve um
     * device fixo na leitura.
     */
    private function fakeClient(bool $executes = true): Client
    {
        return new class(['base_url' => 'http://localhost'], $executes) extends Client {
            public array $executedTask = [];
            public bool $cleanedUp = false;

            public function __construct(array $config, private bool $executes)
            {
                parent::__construct($config);
            }

            public function executeTask(string $deviceId, array $taskData, int $timeoutMs = 30000): bool
            {
                $this->executedTask = $taskData;
                return $this->executes;
            }

            public function getDevice(string $deviceId, ?string $projection = null): DeviceResponse
            {
                return new DeviceResponse([
                    'Root' => [
                        'UpTime' => ['_value' => 1000],
                        'Tx'     => ['_value' => '2.5'],
                        'Rx'     => ['_value' => '-18.0'],
                        'Sw'     => ['_value' => 'V1'],
                    ],
                ]);
            }
        };
    }

    private function device(Client $client): AbstractDevice
    {
        $info = new DeviceInfo('dev1', 'Test', 'OUI', 'M', 'SER', '1', new DeviceResponse([]));

        return new class($info, $client) extends AbstractDevice {
            public function vendor(): string { return 'Test'; }
            public function model(): string { return 'M'; }
            public function firmwareVersion(): string { return '1'; }
            protected function parameterMap(): array
            {
                return [
                    'uptime'           => 'Root.UpTime',
                    'optical.tx_power' => 'Root.Tx',
                    'optical.rx_power' => 'Root.Rx',
                    'sw_version'       => 'Root.Sw',
                    // hw_version propositalmente NÃO mapeado
                ];
            }
        };
    }

    public function test_execute_reads_values_and_types_them(): void
    {
        $reading = $this->device($this->fakeClient())
            ->fetch()
            ->uptime()->txPower()->rxPower()->swVersion()->hwVersion()
            ->execute();

        $this->assertSame(1000, $reading->getUptime());
        $this->assertSame(2.5, $reading->getTxPower());
        $this->assertSame(-18.0, $reading->getRxPower());
        $this->assertSame('V1', $reading->getSwVersion());
        $this->assertNull($reading->getHwVersion()); // não mapeado
    }

    public function test_unmapped_keys_are_skipped_in_the_task(): void
    {
        $client = $this->fakeClient();
        $this->device($client)
            ->fetch()
            ->uptime()->hwVersion() // hwVersion não está mapeado
            ->execute();

        $this->assertSame(
            ['name' => 'getParameterValues', 'parameterNames' => ['Root.UpTime']],
            $client->executedTask
        );
    }

    public function test_failed_task_throws(): void
    {
        $device = $this->device($this->fakeClient(executes: false));

        $this->expectException(TaskException::class);
        $device->fetch()->uptime()->execute();
    }

    public function test_no_mapped_keys_throws(): void
    {
        $device = $this->device($this->fakeClient());

        $this->expectException(\InvalidArgumentException::class);
        $device->fetch()->hwVersion()->execute(); // só chave não mapeada
    }

    public function test_path_for_returns_null_when_unmapped(): void
    {
        $device = $this->device($this->fakeClient());

        $this->assertSame('Root.UpTime', $device->pathFor('uptime'));
        $this->assertNull($device->pathFor('hw_version'));
    }
}
