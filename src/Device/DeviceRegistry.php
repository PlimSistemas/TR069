<?php

namespace Plimsistemas\TR069\Device;

use Plimsistemas\TR069\Exceptions\DeviceNotSupportedException;

class DeviceRegistry
{
    /**
     * In-memory registry: vendor -> model -> firmware -> class.
     * Loaded from config on boot and can be extended at runtime.
     */
    protected array $registry = [];

    public function __construct(array $configDevices = [])
    {
        foreach ($configDevices as $vendor => $models) {
            foreach ($models as $model => $firmwares) {
                foreach ($firmwares as $firmware => $class) {
                    $this->register($vendor, $model, $firmware, $class);
                }
            }
        }
    }

    /**
     * Register a device handler class for a specific vendor + model + firmware.
     * Use '*' as firmware to match any unregistered firmware version.
     */
    public function register(string $vendor, string $model, string $firmware, string $deviceClass): void
    {
        $this->registry[strtolower($vendor)][$model][$firmware] = $deviceClass;
    }

    /**
     * Resolve the device class for a given vendor + model + firmware.
     * Falls back to '*' (wildcard) if the exact firmware is not registered.
     *
     * @throws DeviceNotSupportedException
     */
    public function resolve(string $vendor, string $model, string $firmware): string
    {
        $vendorKey = strtolower($vendor);

        $byFirmware = $this->registry[$vendorKey][$model] ?? null;

        if ($byFirmware === null) {
            throw DeviceNotSupportedException::forModel($vendor, $model, $firmware);
        }

        // Exact firmware match
        if (isset($byFirmware[$firmware])) {
            return $byFirmware[$firmware];
        }

        // Wildcard fallback
        if (isset($byFirmware['*'])) {
            return $byFirmware['*'];
        }

        throw DeviceNotSupportedException::forModel($vendor, $model, $firmware);
    }

    /**
     * Check whether a device handler exists (without throwing).
     */
    public function has(string $vendor, string $model, string $firmware): bool
    {
        try {
            $this->resolve($vendor, $model, $firmware);
            return true;
        } catch (DeviceNotSupportedException) {
            return false;
        }
    }

    public function all(): array
    {
        return $this->registry;
    }
}
