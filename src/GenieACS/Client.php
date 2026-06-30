<?php

namespace Plimsistemas\TR069\GenieACS;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Plimsistemas\TR069\Exceptions\GenieACSException;
use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;
use Plimsistemas\TR069\GenieACS\Responses\TaskResponse;

class Client
{
    protected HttpClient $http;

    public function __construct(protected array $config)
    {
        $options = [
            'base_uri' => rtrim($config['base_url'], '/') . '/',
            'timeout'  => $config['timeout'] ?? 30,
            'verify'   => $config['verify_ssl'] ?? true,
            'headers'  => ['Accept' => 'application/json'],
        ];

        if (!empty($config['username'])) {
            $options['auth'] = [$config['username'], $config['password'] ?? ''];
        }

        $this->http = new HttpClient($options);
    }

    // -------------------------------------------------------------------------
    // Devices
    // -------------------------------------------------------------------------

    /**
     * Search devices using a QueryBuilder.
     *
     * @return DeviceResponse[]
     */
    public function searchDevices(QueryBuilder $query): array
    {
        $response = $this->request('GET', 'devices', [
            'query' => $query->toQueryParams(),
        ]);

        return array_map(fn (array $item) => new DeviceResponse($item), $response);
    }

    /**
     * Get full device data by GenieACS device ID.
     *
     * GenieACS does not expose GET /devices/<id> (it responds 405), so a
     * single device is fetched via a query on _id against the collection.
     */
    public function getDevice(string $deviceId, ?string $projection = null): DeviceResponse
    {
        $query = ['query' => json_encode(['_id' => $deviceId])];
        if ($projection !== null) {
            $query['projection'] = $projection;
        }

        $response = $this->request('GET', 'devices', ['query' => $query]);

        return new DeviceResponse($response[0] ?? []);
    }

    /**
     * Get specific parameter values from a device via a task.
     */
    public function getParameterValues(string $deviceId, array $parameterNames): TaskResponse
    {
        return $this->createTask($deviceId, [
            'name'           => 'getParameterValues',
            'parameterNames' => $parameterNames,
        ]);
    }

    /**
     * Set parameter values on a device via a task.
     *
     * @param  array  $parameterValues  [ ['path', 'value', 'type?'], ... ]
     */
    public function setParameterValues(string $deviceId, array $parameterValues): TaskResponse
    {
        return $this->createTask($deviceId, [
            'name'            => 'setParameterValues',
            'parameterValues' => $parameterValues,
        ]);
    }

    /**
     * Reboot the device.
     */
    public function reboot(string $deviceId): TaskResponse
    {
        return $this->createTask($deviceId, ['name' => 'reboot']);
    }

    /**
     * Factory reset the device.
     */
    public function factoryReset(string $deviceId): TaskResponse
    {
        return $this->createTask($deviceId, ['name' => 'factoryReset']);
    }

    /**
     * Refresh a specific object on the device.
     */
    public function refreshObject(string $deviceId, string $objectName): TaskResponse
    {
        return $this->createTask($deviceId, [
            'name'       => 'refreshObject',
            'objectName' => $objectName,
        ]);
    }

    /**
     * Download/upgrade firmware on the device.
     */
    public function download(string $deviceId, string $fileType, string $url, string $filename = ''): TaskResponse
    {
        return $this->createTask($deviceId, [
            'name'     => 'download',
            'fileType' => $fileType,
            'url'      => $url,
            'fileName' => $filename,
        ]);
    }

    // -------------------------------------------------------------------------
    // Tasks
    // -------------------------------------------------------------------------

    public function createTask(string $deviceId, array $taskData, bool $connection = true): TaskResponse
    {
        $path  = 'devices/' . urlencode($deviceId) . '/tasks';
        // GenieACS uses `connection_request` to trigger an immediate connection
        // request to the device; without it the task is merely queued.
        $query = $connection ? ['connection_request' => ''] : [];

        $response = $this->request('POST', $path, [
            'json'  => $taskData,
            'query' => $query,
        ]);

        return new TaskResponse($response);
    }

    /**
     * Execute a task immediately (no queue) by forcing a connection request,
     * waiting up to $timeoutMs for the device to run it.
     *
     * GenieACS replies 200 when the device executed the task synchronously
     * (values are then refreshed in the database) or 202 when it had to queue
     * it because the device could not be reached in time. On 202 the queued
     * task is deleted so it does not run unexpectedly later.
     *
     * @return bool  true when executed synchronously, false when not reachable.
     */
    public function executeTask(string $deviceId, array $taskData, int $timeoutMs = 30000): bool
    {
        $path = 'devices/' . urlencode($deviceId) . '/tasks';

        try {
            $response = $this->http->request('POST', $path, [
                'json'         => $taskData,
                'query'        => ['connection_request' => '', 'timeout' => $timeoutMs],
                'http_errors'  => false,
            ]);
        } catch (GuzzleException $e) {
            throw new GenieACSException(
                'GenieACS task request failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }

        if ($response->getStatusCode() === 200) {
            return true;
        }

        // Not executed (typically 202 = queued). Clean up the pending task.
        $data = json_decode((string) $response->getBody(), true);
        if (is_array($data) && !empty($data['_id'])) {
            try {
                $this->deleteTask($data['_id']);
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }

        return false;
    }

    /**
     * Executa VÁRIAS tasks em UMA única sessão (um só connection request com a
     * ONU): enfileira todas menos a última (sem connection request) e dispara a
     * última com connection request — o GenieACS processa TODAS as tasks
     * pendentes do device na mesma sessão.
     *
     * Útil para refrescar vários objetos de uma vez sem N contatos com o device.
     *
     * @param  array<int,array<string,mixed>> $tasksData
     * @return bool  true = a ONU executou a tempo; false = não conectou.
     */
    public function executeTasks(string $deviceId, array $tasksData, int $timeoutMs = 30000): bool
    {
        $tasksData = array_values($tasksData);

        if ($tasksData === []) {
            return false;
        }
        if (count($tasksData) === 1) {
            return $this->executeTask($deviceId, $tasksData[0], $timeoutMs);
        }

        // Enfileira todas menos a última, SEM connection request (ficam pendentes).
        $last      = array_pop($tasksData);
        $queuedIds = [];
        foreach ($tasksData as $task) {
            $id = $this->createTask($deviceId, $task, false)->raw()['_id'] ?? null;
            if ($id) {
                $queuedIds[] = $id;
            }
        }

        // A última dispara UMA conexão; o GenieACS roda todas as pendentes junto.
        $executed = $this->executeTask($deviceId, $last, $timeoutMs);

        // Device não conectou: limpa as que ficaram pendentes (best-effort).
        if (!$executed) {
            foreach ($queuedIds as $id) {
                try {
                    $this->deleteTask($id);
                } catch (\Throwable) {
                    // best-effort
                }
            }
        }

        return $executed;
    }

    /**
     * Verifica se o dispositivo está ONLINE (acessível pelo ACS agora), forçando
     * um connection request com uma task leve de leitura.
     *
     * @return bool  true = device respondeu/executou; false = não alcançado a tempo.
     */
    public function isReachable(
        string $deviceId,
        string $probeParam = 'InternetGatewayDevice.DeviceInfo.UpTime',
        int $timeoutMs = 15000
    ): bool {
        return $this->executeTask($deviceId, [
            'name'           => 'getParameterValues',
            'parameterNames' => [$probeParam],
        ], $timeoutMs);
    }

    public function getTask(string $taskId): TaskResponse
    {
        $response = $this->request('GET', 'tasks/' . urlencode($taskId));
        return new TaskResponse($response);
    }

    public function deleteTask(string $taskId): void
    {
        $this->request('DELETE', 'tasks/' . urlencode($taskId));
    }

    // -------------------------------------------------------------------------
    // HTTP core
    // -------------------------------------------------------------------------

    protected function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http->request($method, $path, $options);
            $body     = (string) $response->getBody();

            if (empty($body)) {
                return [];
            }

            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new GenieACSException('Invalid JSON response from GenieACS: ' . $body);
            }

            return $decoded;
        } catch (GuzzleException $e) {
            throw new GenieACSException(
                'GenieACS API request failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
