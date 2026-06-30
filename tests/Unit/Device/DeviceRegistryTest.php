<?php

namespace Plimsistemas\TR069\Tests\Unit\Device;

use Plimsistemas\TR069\Device\DeviceRegistry;
use Plimsistemas\TR069\Exceptions\DeviceNotSupportedException;
use Plimsistemas\TR069\Vendors\Intelbras\Devices\W51200F\W51200FDevice;
use PHPUnit\Framework\TestCase;

class DeviceRegistryTest extends TestCase
{
    public function test_resolves_exact_firmware(): void
    {
        $registry = new DeviceRegistry();
        $registry->register('intelbras', 'W5-1200F', '1.0.0', W51200FDevice::class);

        $this->assertSame(W51200FDevice::class, $registry->resolve('intelbras', 'W5-1200F', '1.0.0'));
    }

    public function test_falls_back_to_wildcard_firmware(): void
    {
        $registry = new DeviceRegistry();
        $registry->register('intelbras', 'W5-1200F', '*', W51200FDevice::class);

        $this->assertSame(W51200FDevice::class, $registry->resolve('intelbras', 'W5-1200F', '99.9.9'));
    }

    public function test_vendor_key_is_case_insensitive(): void
    {
        $registry = new DeviceRegistry();
        $registry->register('Intelbras', 'W5-1200F', '*', W51200FDevice::class);

        $this->assertSame(W51200FDevice::class, $registry->resolve('INTELBRAS', 'W5-1200F', '1.0.0'));
    }

    public function test_throws_when_not_registered(): void
    {
        $registry = new DeviceRegistry();

        $this->expectException(DeviceNotSupportedException::class);
        $registry->resolve('unknown', 'MODEL-X', '1.0.0');
    }

    public function test_has_returns_false_for_unregistered(): void
    {
        $registry = new DeviceRegistry();
        $this->assertFalse($registry->has('unknown', 'MODEL-X', '1.0.0'));
    }
}
