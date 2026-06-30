<?php

namespace Plimsistemas\TR069\Contracts;

interface VendorInterface
{
    /**
     * Returns the list of manufacturer strings (as reported by GenieACS)
     * that this vendor handles. Case-insensitive matching is applied.
     *
     * @return string[]
     */
    public function manufacturerNames(): array;

    /**
     * Returns the normalized vendor key used in the device registry.
     */
    public function key(): string;

    /**
     * Returns the display name of this vendor.
     */
    public function name(): string;

    /**
     * Returns the TR-069 root parameter namespace used by this vendor's devices.
     * Typically 'InternetGatewayDevice' or 'Device'.
     */
    public function rootNamespace(): string;

    /**
     * Returns the TR-069 parameter path for the device software/firmware version.
     */
    public function softwareVersionPath(): string;
}
