<?php

namespace Plimsistemas\TR069\Exceptions;

use RuntimeException;

class DeviceNotFoundException extends RuntimeException
{
    public static function bySerial(string $serial): static
    {
        return new static("Device with serial '{$serial}' not found.");
    }

    public static function byId(string $deviceId): static
    {
        return new static("Device with ID '{$deviceId}' not found.");
    }

    public static function byGponSn(string $gponSn): static
    {
        return new static("Device with GPON SN '{$gponSn}' not found.");
    }
}
