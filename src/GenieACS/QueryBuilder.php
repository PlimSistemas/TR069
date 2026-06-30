<?php

namespace Plimsistemas\TR069\GenieACS;

class QueryBuilder
{
    protected array $query = [];
    protected array $projection = [];

    public static function make(): static
    {
        return new static();
    }

    public function whereSerial(string $serial): static
    {
        $this->query['_deviceId._SerialNumber'] = [
            '$regex'   => '^' . $serial . '$',
            '$options' => 'i',
        ];
        return $this;
    }

    /**
     * Filtra pelo GPON Serial Number, exposto no GenieACS via o virtual
     * parameter `VirtualParameters.GponSN` (independente de fabricante).
     */
    public function whereGponSn(string $gponSn): static
    {
        $this->query['VirtualParameters.GponSN'] = [
            '$regex'   => '^' . $gponSn . '$',
            '$options' => 'i',
        ];
        return $this;
    }

    public function whereManufacturer(string $manufacturer): static
    {
        $this->query['_deviceId._Manufacturer'] = [
            '$regex'   => '^' . $manufacturer . '$',
            '$options' => 'i',
        ];
        return $this;
    }

    public function whereProductClass(string $productClass): static
    {
        $this->query['_deviceId._ProductClass'] = $productClass;
        return $this;
    }

    public function where(string $field, mixed $value): static
    {
        $this->query[$field] = $value;
        return $this;
    }

    public function project(string ...$fields): static
    {
        foreach ($fields as $field) {
            $this->projection[] = $field;
        }
        return $this;
    }

    public function projectDeviceId(): static
    {
        return $this->project('_deviceId');
    }

    /**
     * Projeção mínima (apenas o `_id`) — útil para checagens de existência,
     * evitando trafegar o documento inteiro do device.
     */
    public function projectId(): static
    {
        return $this->project('_id');
    }

    public function projectSoftwareVersion(): static
    {
        return $this->project(
            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
            'Device.DeviceInfo.SoftwareVersion'
        );
    }

    public function projectGponSn(): static
    {
        return $this->project('VirtualParameters.GponSN');
    }

    public function toQueryParams(): array
    {
        $params = [];

        if (!empty($this->query)) {
            $params['query'] = json_encode($this->query);
        }

        if (!empty($this->projection)) {
            $params['projection'] = implode(',', array_unique($this->projection));
        }

        return $params;
    }
}
