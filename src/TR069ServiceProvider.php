<?php

namespace Plimsistemas\TR069;

use Illuminate\Support\ServiceProvider;
use Plimsistemas\TR069\Device\DeviceDiscovery;
use Plimsistemas\TR069\Device\DeviceRegistry;
use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\Vendors\FiberHome\FiberHomeVendor;
use Plimsistemas\TR069\Vendors\Intelbras\IntelbrasVendor;
use Plimsistemas\TR069\Vendors\ZTE\ZTEVendor;

class TR069ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/tr069.php', 'tr069');

        $this->app->singleton(Client::class, function ($app) {
            return new Client($app['config']['tr069']);
        });

        $this->app->singleton(DeviceRegistry::class, function ($app) {
            $devices = $app['config']['tr069.devices'] ?? [];

            // Descoberta automática dos handlers em src/Vendors (opt-in).
            // O config tem precedência: o que estiver lá sobrescreve o descoberto.
            if ($app['config']['tr069.auto_discover'] ?? true) {
                $discovered = DeviceDiscovery::discover(
                    __DIR__ . '/Vendors',
                    __NAMESPACE__ . '\\Vendors'
                );
                $devices = array_replace_recursive($discovered, $devices);
            }

            return new DeviceRegistry($devices);
        });

        $this->app->singleton(TR069Manager::class, function ($app) {
            $manager = new TR069Manager(
                $app->make(Client::class),
                $app->make(DeviceRegistry::class),
            );

            $manager->registerVendor(new ZTEVendor());
            $manager->registerVendor(new FiberHomeVendor());
            $manager->registerVendor(new IntelbrasVendor());

            return $manager;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/tr069.php' => config_path('tr069.php'),
            ], 'tr069-config');
        }
    }
}
