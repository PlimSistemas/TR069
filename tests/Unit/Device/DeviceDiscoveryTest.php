<?php

namespace Plimsistemas\TR069\Tests\Unit\Device;

use Plimsistemas\TR069\Device\DeviceDiscovery;
use Plimsistemas\TR069\Vendors\FiberHome\Devices\HG6143D\HG6143DDevice;
use Plimsistemas\TR069\Vendors\Generic\GenericDevice;
use Plimsistemas\TR069\Vendors\Intelbras\Devices\W51200F\W51200FDevice;
use Plimsistemas\TR069\Vendors\ZTE\Devices\F6201B\F6201BDevice;
use Plimsistemas\TR069\Vendors\ZTE\Devices\F670L\F670LDevice;
use PHPUnit\Framework\TestCase;

class DeviceDiscoveryTest extends TestCase
{
    private function discover(): array
    {
        return DeviceDiscovery::discover(
            dirname(__DIR__, 3) . '/src/Vendors',
            'Plimsistemas\\TR069\\Vendors'
        );
    }

    public function test_discovers_concrete_self_describing_handlers(): void
    {
        $d = $this->discover();

        $this->assertSame(F6201BDevice::class, $d['zte']['F6201B']['*']);
        $this->assertSame(F670LDevice::class, $d['zte']['F670L']['*']);
        $this->assertSame(HG6143DDevice::class, $d['fiberhome']['HG6143D']['*']);
        $this->assertSame(W51200FDevice::class, $d['intelbras']['W5-1200F']['*']);
    }

    public function test_vendor_keys_are_lowercased(): void
    {
        $d = $this->discover();

        foreach (array_keys($d) as $vendor) {
            $this->assertSame(strtolower($vendor), $vendor);
        }
    }

    public function test_skips_generic_fallback_and_abstract_bases(): void
    {
        $d = $this->discover();

        // Achata todas as classes registradas.
        $classes = [];
        foreach ($d as $models) {
            foreach ($models as $firmwares) {
                foreach ($firmwares as $class) {
                    $classes[] = $class;
                }
            }
        }

        // GenericDevice não se autodescreve (vendor/model vazios) -> não registrado.
        $this->assertNotContains(GenericDevice::class, $classes);
        // Nenhuma chave de vendor vazia (efeito colateral do Generic).
        $this->assertArrayNotHasKey('', $d);
    }

    public function test_returns_empty_for_missing_path(): void
    {
        $this->assertSame([], DeviceDiscovery::discover('/path/inexistente', 'X'));
    }

    public function test_declared_class_is_extracted_from_file_contents(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'disc') . '.php';
        file_put_contents($tmp, "<?php\nnamespace Foo\\Bar;\nclass Baz extends \\stdClass {}\n");

        $m = new \ReflectionMethod(DeviceDiscovery::class, 'declaredClass');
        $m->setAccessible(true);

        $this->assertSame('Foo\\Bar\\Baz', $m->invoke(null, $tmp));

        @unlink($tmp);
    }

    /**
     * Antes do hardening, um arquivo com namespace ≠ pasta fazia discover()
     * re-incluir o arquivo a cada chamada e estourar "Cannot redeclare class".
     * Repetir discover() deve ser seguro e idempotente.
     */
    public function test_discover_is_repeatable_and_idempotent(): void
    {
        $a = $this->discover();
        $b = $this->discover();

        $this->assertSame($a, $b);
    }
}
