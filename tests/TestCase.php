<?php

namespace Plimsistemas\TR069\Tests;

use Plimsistemas\TR069\TR069ServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [TR069ServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('tr069.base_url', 'http://localhost:7557/api');
        $app['config']->set('tr069.username', null);
        $app['config']->set('tr069.password', null);
    }
}
