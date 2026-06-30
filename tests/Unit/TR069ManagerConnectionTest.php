<?php

namespace Plimsistemas\TR069\Tests\Unit;

use Plimsistemas\TR069\Device\DeviceRegistry;
use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\TR069Manager;
use Plimsistemas\TR069\Vendors\ZTE\ZTEVendor;
use PHPUnit\Framework\TestCase;

class TR069ManagerConnectionTest extends TestCase
{
    public function test_connection_returns_a_new_manager_reusing_registry_and_vendors(): void
    {
        $registry = new DeviceRegistry(['zte' => ['F6201B' => ['*' => 'X']]]);
        $base     = new TR069Manager(new Client(['base_url' => 'http://acs-a']), $registry);
        $base->registerVendor(new ZTEVendor());

        $other = $base->connection([
            'base_url' => 'http://acs-b',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        // Nova instância, mas registry e vendors são reaproveitados (independem da conexão).
        $this->assertNotSame($base, $other);
        $this->assertInstanceOf(TR069Manager::class, $other);
        $this->assertSame($base->registry(), $other->registry());
        $this->assertSame($base->vendors(), $other->vendors());

        // Cada manager tem seu próprio Client (conexão distinta).
        $this->assertNotSame($base->client(), $other->client());
    }
}
