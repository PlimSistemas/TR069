<?php

namespace Plimsistemas\TR069\Exceptions;

use RuntimeException;

class DeviceNotSupportedException extends RuntimeException
{
    public static function forModel(string $vendor, string $model, string $firmware): static
    {
        return new static(
            "No device handler registered for vendor '{$vendor}', model '{$model}', firmware '{$firmware}'. " .
            "Register a handler in config/tr069.php or via DeviceRegistry::register()."
        );
    }
}
