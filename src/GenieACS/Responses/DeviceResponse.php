<?php

namespace Plimsistemas\TR069\GenieACS\Responses;

class DeviceResponse
{
    public function __construct(
        protected array $data
    ) {}

    public function getId(): ?string
    {
        return $this->data['_id'] ?? null;
    }

    public function getManufacturer(): ?string
    {
        return $this->data['_deviceId']['_Manufacturer'] ?? null;
    }

    public function getOui(): ?string
    {
        return $this->data['_deviceId']['_OUI'] ?? null;
    }

    public function getProductClass(): ?string
    {
        return $this->data['_deviceId']['_ProductClass'] ?? null;
    }

    public function getSerialNumber(): ?string
    {
        return $this->data['_deviceId']['_SerialNumber'] ?? null;
    }

    /**
     * Extracts the software version from either TR-069 namespace.
     */
    public function getSoftwareVersion(): ?string
    {
        $igdPath = $this->data['InternetGatewayDevice']['DeviceInfo']['SoftwareVersion']['_value'] ?? null;
        $devPath = $this->data['Device']['DeviceInfo']['SoftwareVersion']['_value'] ?? null;

        return $igdPath ?? $devPath;
    }

    /**
     * Navigate the raw parameter tree using dot notation.
     * Returns the '_value' leaf if present, otherwise the raw node.
     */
    public function getParameter(string $path): mixed
    {
        $parts = explode('.', $path);
        $node  = $this->data;

        foreach ($parts as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        if (is_array($node) && array_key_exists('_value', $node)) {
            return $node['_value'];
        }

        return $node;
    }

    public function raw(): array
    {
        return $this->data;
    }
}
