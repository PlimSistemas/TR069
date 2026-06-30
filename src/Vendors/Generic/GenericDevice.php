<?php

namespace Plimsistemas\TR069\Vendors\Generic;

use Plimsistemas\TR069\Device\AbstractDevice;

/**
 * Fallback device handler for unknown/unregistered models.
 * Exposes only raw path-based access (getPath / setPath).
 */
class GenericDevice extends AbstractDevice
{
    public function vendor(): string
    {
        return $this->deviceInfo->manufacturer;
    }

    public function model(): string
    {
        return $this->deviceInfo->productClass;
    }

    public function firmwareVersion(): string
    {
        return $this->deviceInfo->softwareVersion ?? '*';
    }

    protected function parameterMap(): array
    {
        return [];
    }
}
