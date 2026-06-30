<?php

namespace Plimsistemas\TR069;

use Illuminate\Support\Collection;
use Plimsistemas\TR069\Contracts\VendorInterface;
use Plimsistemas\TR069\Device\AbstractDevice;
use Plimsistemas\TR069\Device\DeviceInfo;
use Plimsistemas\TR069\Device\DeviceRegistry;
use Plimsistemas\TR069\Exceptions\DeviceNotFoundException;
use Plimsistemas\TR069\Exceptions\DeviceNotSupportedException;
use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\GenieACS\QueryBuilder;
use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;

class TR069Manager
{
    /** @var VendorInterface[] */
    protected array $vendors = [];

    public function __construct(
        protected Client $client,
        protected DeviceRegistry $registry,
    ) {}

    // -------------------------------------------------------------------------
    // Conexão dinâmica
    // -------------------------------------------------------------------------

    /**
     * Cria um manager apontando para OUTRA conexão GenieACS, com a config
     * passada em runtime (base_url, username, password, timeout, verify_ssl) —
     * sem depender de `.env`/config. Reaproveita o mesmo registry de handlers e
     * os vendors já registrados (que independem da conexão).
     *
     *   TR069::connection([
     *       'base_url' => $tenant->acs_url,
     *       'username' => $tenant->acs_user,
     *       'password' => $tenant->acs_pass,
     *   ])->findByGponSn('...');
     *
     * @param array $config  ['base_url' => string, 'username'?, 'password'?, 'timeout'?, 'verify_ssl'?]
     */
    public function connection(array $config): static
    {
        $manager = new static(new Client($config), $this->registry);

        foreach ($this->vendors as $vendor) {
            $manager->registerVendor($vendor);
        }

        return $manager;
    }

    // -------------------------------------------------------------------------
    // Device lookup
    // -------------------------------------------------------------------------

    /**
     * Find a device by its serial number.
     * Returns a typed device handler resolved from the registry.
     *
     * @throws DeviceNotFoundException
     * @throws DeviceNotSupportedException
     */
    public function findBySerial(string $serial): AbstractDevice
    {
        $query = QueryBuilder::make()
            ->whereSerial($serial)
            ->projectDeviceId()
            ->projectSoftwareVersion();

        $results = $this->client->searchDevices($query);

        if (empty($results)) {
            throw DeviceNotFoundException::bySerial($serial);
        }

        return $this->buildDevice($results[0]);
    }

    /**
     * Find a device by its GPON Serial Number (virtual param GponSN no GenieACS).
     * Agnóstico de fabricante — use quando o GPON SN difere do serial TR-069.
     *
     * @throws DeviceNotFoundException
     * @throws DeviceNotSupportedException
     */
    public function findByGponSn(string $gponSn): AbstractDevice
    {
        $query = QueryBuilder::make()
            ->whereGponSn($gponSn)
            ->projectDeviceId()
            ->projectSoftwareVersion();

        $results = $this->client->searchDevices($query);

        if (empty($results)) {
            throw DeviceNotFoundException::byGponSn($gponSn);
        }

        return $this->buildDevice($results[0]);
    }

    /**
     * Get a device by its GenieACS device ID.
     *
     * @throws DeviceNotFoundException
     * @throws DeviceNotSupportedException
     */
    public function find(string $deviceId): AbstractDevice
    {
        $response = $this->client->getDevice(
            $deviceId,
            '_deviceId,InternetGatewayDevice.DeviceInfo.SoftwareVersion,Device.DeviceInfo.SoftwareVersion'
        );

        if ($response->getId() === null) {
            throw DeviceNotFoundException::byId($deviceId);
        }

        return $this->buildDevice($response);
    }

    /**
     * List devices with optional filtering.
     * Returns a collection of raw DeviceInfo objects (not typed handlers).
     */
    public function devices(QueryBuilder $query): Collection
    {
        $results = $this->client->searchDevices($query);

        return collect($results)->map(fn (DeviceResponse $r) => DeviceInfo::fromResponse($r));
    }

    /**
     * Verifica se o dispositivo está ONLINE (acessível pelo ACS agora) pelo
     * GenieACS device ID, sem precisar resolver um handler.
     */
    public function isOnline(string $deviceId, int $timeoutMs = 15000): bool
    {
        return $this->client->isReachable($deviceId, timeoutMs: $timeoutMs);
    }

    // -------------------------------------------------------------------------
    // Existência no banco do GenieACS (NÃO acorda o device — só consulta)
    // -------------------------------------------------------------------------

    /**
     * O device está cadastrado no GenieACS? (busca pelo serial TR-069).
     * Consulta leve no banco — diferente de isOnline(), não dispara
     * connection_request nem espera o aparelho responder.
     */
    public function existsBySerial(string $serial): bool
    {
        $query = QueryBuilder::make()->whereSerial($serial)->projectId();

        return !empty($this->client->searchDevices($query));
    }

    /**
     * O device está cadastrado no GenieACS? (busca pelo GPON SN).
     */
    public function existsByGponSn(string $gponSn): bool
    {
        $query = QueryBuilder::make()->whereGponSn($gponSn)->projectId();

        return !empty($this->client->searchDevices($query));
    }

    /**
     * O device está cadastrado no GenieACS? (busca pelo GenieACS device ID).
     */
    public function exists(string $deviceId): bool
    {
        $query = QueryBuilder::make()->where('_id', $deviceId)->projectId();

        return !empty($this->client->searchDevices($query));
    }

    /**
     * Raw access to GenieACS without registry resolution.
     */
    public function client(): Client
    {
        return $this->client;
    }

    public function registry(): DeviceRegistry
    {
        return $this->registry;
    }

    // -------------------------------------------------------------------------
    // Vendor registry
    // -------------------------------------------------------------------------

    public function registerVendor(VendorInterface $vendor): void
    {
        $this->vendors[$vendor->key()] = $vendor;
    }

    /** @return VendorInterface[] */
    public function vendors(): array
    {
        return $this->vendors;
    }

    /**
     * Resolve the vendor key from a manufacturer string.
     * Falls back to a normalized lowercase string if no vendor matches.
     */
    public function resolveVendorKey(string $manufacturer): string
    {
        foreach ($this->vendors as $vendor) {
            if ($vendor->matches($manufacturer)) {
                return $vendor->key();
            }
        }

        return strtolower(trim($manufacturer));
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    protected function buildDevice(DeviceResponse $response): AbstractDevice
    {
        $info      = DeviceInfo::fromResponse($response);
        $vendorKey = $this->resolveVendorKey($info->manufacturer);
        $firmware  = $info->softwareVersion ?? '*';

        $deviceClass = $this->registry->resolve($vendorKey, $info->productClass, $firmware);

        return new $deviceClass($info, $this->client);
    }
}
