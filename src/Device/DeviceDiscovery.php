<?php

namespace Plimsistemas\TR069\Device;

use Plimsistemas\TR069\GenieACS\Client;
use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;
use ReflectionClass;

/**
 * Descoberta automática de handlers de dispositivo.
 *
 * Varre um diretório de handlers (por convenção, `src/Vendors`) e monta o mapa
 * `vendor => model => firmware => class` — a mesma forma de `config/tr069.php`,
 * evitando registrar cada modelo/firmware manualmente.
 *
 * Como cada handler já se autodescreve via `vendor()/model()/firmwareVersion()`,
 * a descoberta instancia a classe com um DeviceInfo "vazio" (softwareVersion
 * nulo => firmware '*') e lê essas chaves. Handlers que não conseguem se
 * autodescrever (vendor/model vazios, ex.: GenericDevice) e classes abstratas
 * (ex.: ZTEDevice/FiberHomeDevice) são ignorados.
 */
class DeviceDiscovery
{
    /**
     * @return array<string,array<string,array<string,class-string>>>
     */
    public static function discover(string $path, string $namespace): array
    {
        $devices = [];

        if (!is_dir($path)) {
            return $devices;
        }

        $dummyInfo   = new DeviceInfo('', '', '', '', '', null, new DeviceResponse([]));
        $dummyClient = new Client(['base_url' => 'http://localhost']);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // FQCN derivado do CAMINHO (convenção PSR-4) e o FQCN realmente
            // DECLARADO no arquivo. Se divergirem, o arquivo está na pasta errada
            // (PSR-4 quebrado): ignoramos com segurança — usar class_exists() com
            // o nome derivado do path re-incluiria o arquivo e causaria fatal de
            // "Cannot redeclare class".
            $expected = self::classFromFile($file->getPathname(), $path, $namespace);
            $declared = self::declaredClass($file->getPathname());

            if ($expected === null || $declared === null || $expected !== $declared) {
                continue;
            }

            if (!class_exists($declared)) {
                continue;
            }

            $class = $declared;
            $ref   = new ReflectionClass($class);
            if ($ref->isAbstract() || !$ref->isSubclassOf(AbstractDevice::class)) {
                continue;
            }

            try {
                /** @var AbstractDevice $handler */
                $handler  = new $class($dummyInfo, $dummyClient);
                $vendor   = $handler->vendor();
                $model    = $handler->model();
                $firmware = $handler->firmwareVersion();
            } catch (\Throwable) {
                continue; // handler que não se instancia com dummy é ignorado
            }

            // Só registra quem se autodescreve (exclui GenericDevice & afins).
            if ($vendor === '' || $model === '') {
                continue;
            }

            $devices[strtolower($vendor)][$model][$firmware] = $class;
        }

        return $devices;
    }

    /**
     * Deriva o FQCN a partir do caminho do arquivo + namespace base (PSR-4).
     */
    private static function classFromFile(string $file, string $basePath, string $namespace): ?string
    {
        $relative = substr($file, strlen($basePath));
        $relative = trim(str_replace(['/', '\\'], '\\', $relative), '\\');
        $relative = preg_replace('/\.php$/i', '', $relative);

        if ($relative === '' || $relative === null) {
            return null;
        }

        return rtrim($namespace, '\\') . '\\' . $relative;
    }

    /**
     * Extrai o FQCN realmente declarado no arquivo (namespace + primeira classe),
     * via tokens — sem incluir o arquivo (evita autoload/redeclaração).
     */
    private static function declaredClass(string $file): ?string
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return null;
        }

        $tokens = token_get_all($code);
        $namespace = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if ($t === ';' || $t === '{') {
                        break;
                    }
                    if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR], true)) {
                        $namespace .= $t[1];
                    } elseif (is_array($t) && defined('T_NAME_QUALIFIED') && $t[0] === T_NAME_QUALIFIED) {
                        $namespace .= $t[1];
                    }
                }
            }

            if ($token[0] === T_CLASS) {
                // ignora ::class
                $prev = $tokens[$i - 1] ?? null;
                if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        return $namespace !== '' ? $namespace . '\\' . $class : $class;
                    }
                }
            }
        }

        return null;
    }
}

