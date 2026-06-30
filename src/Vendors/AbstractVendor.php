<?php

namespace Plimsistemas\TR069\Vendors;

use Plimsistemas\TR069\Contracts\VendorInterface;

abstract class AbstractVendor implements VendorInterface
{
    /**
     * Check whether this vendor handles the given manufacturer string
     * (case-insensitive, partial match allowed via override).
     */
    public function matches(string $manufacturer): bool
    {
        foreach ($this->manufacturerNames() as $name) {
            if (strcasecmp($manufacturer, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default: InternetGatewayDevice namespace.
     * Vendors that use the Device. namespace should override this.
     */
    public function rootNamespace(): string
    {
        return 'InternetGatewayDevice';
    }

    public function softwareVersionPath(): string
    {
        return $this->rootNamespace() . '.DeviceInfo.SoftwareVersion';
    }
}
