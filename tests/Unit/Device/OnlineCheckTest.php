<?php

namespace Plimsistemas\TR069\Tests\Unit\Device;

use Plimsistemas\TR069\Device\AbstractDevice;
use Plimsistemas\TR069\Device\DeviceInfo;
use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;
use Plimsistemas\TR069\TR069Manager;
use Plimsistemas\TR069\Device\DeviceRegistry;
use PHPUnit\Framework\TestCase;

class OnlineCheckTest extends TestCase
{
    /** Client fake que registra a task executada e devolve um resultado fixo. */
    private function fakeClient(bool $reachable): Client
    {
        return new class(['base_url' => 'http://localhost'], $reachable) extends Client {
            public array $lastTask = [];
            public ?int $lastTimeout = null;

            public function __construct(array $config, private bool $reachable)
            {
                parent::__construct($config);
            }

            public function executeTask(string $deviceId, array $taskData, int $timeoutMs = 30000): bool
            {
                $this->lastTask    = $taskData;
                $this->lastTimeout = $timeoutMs;
                return $this->reachable;
            }
        };
    }

    private function device(Client $client): AbstractDevice
    {
        $info = new DeviceInfo('dev-1', 'ZTE', 'OUI', 'F6201B', 'SER', '1', new DeviceResponse([]));

        return new class($info, $client) extends AbstractDevice {
            public function vendor(): string { return 'ZTE'; }
            public function model(): string { return 'F6201B'; }
            public function firmwareVersion(): string { return '1'; }
            protected function parameterMap(): array
            {
                return ['uptime' => 'InternetGatewayDevice.DeviceInfo.UpTime'];
            }
        };
    }

    public function test_is_online_true_when_device_executes_task(): void
    {
        $this->assertTrue($this->device($this->fakeClient(true))->isOnline());
    }

    public function test_is_online_false_when_device_unreachable(): void
    {
        $this->assertFalse($this->device($this->fakeClient(false))->isOnline());
    }

    public function test_is_online_probes_a_lightweight_getparametervalues(): void
    {
        $client = $this->fakeClient(true);
        $this->device($client)->isOnline(8000);

        $this->assertSame('getParameterValues', $client->lastTask['name']);
        $this->assertSame(
            ['InternetGatewayDevice.DeviceInfo.UpTime'],
            $client->lastTask['parameterNames']
        );
        $this->assertSame(8000, $client->lastTimeout);
    }

    public function test_manager_is_online_delegates_to_client(): void
    {
        $manager = new TR069Manager($this->fakeClient(true), new DeviceRegistry());
        $this->assertTrue($manager->isOnline('any-device-id'));

        $manager = new TR069Manager($this->fakeClient(false), new DeviceRegistry());
        $this->assertFalse($manager->isOnline('any-device-id'));
    }
}
