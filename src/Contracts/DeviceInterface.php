<?php

namespace Plimsistemas\TR069\Contracts;

use Plimsistemas\TR069\Device\DeviceInfo;
use Plimsistemas\TR069\GenieACS\Responses\TaskResponse;

interface DeviceInterface
{
    public function vendor(): string;

    public function model(): string;

    public function firmwareVersion(): string;

    public function info(): DeviceInfo;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value): TaskResponse;

    public function getMany(array $keys): array;

    public function setMany(array $params): TaskResponse;

    public function reboot(): TaskResponse;

    public function factoryReset(): TaskResponse;

    public function getPath(string $path): mixed;

    public function setPath(string $path, mixed $value): TaskResponse;
}
