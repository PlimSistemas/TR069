<?php

namespace Plimsistemas\TR069\Vendors\FiberHome\Devices\HG6245D;

use Plimsistemas\TR069\Vendors\FiberHome\FiberHomeDevice;
use Plimsistemas\TR069\GenieACS\Responses\TaskResponse;

/**
 * FiberHome HG6245D — handler de modelo (wildcard de firmware).
 * Camada MODELO: herda os padrões FiberHome de {@see FiberHomeDevice}
 */
class HG6245DDevice extends FiberHomeDevice
{
    public function model(): string
    {
        return 'HG6245D';
    }

    public function firmwareVersion(): string
    {
        return $this->deviceInfo->softwareVersion ?? '*';
    }

    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // Informações extras (além das herdadas da marca)
        ]);
    }

    // -------------------------------------------------------------------------
    // Métodos de conveniência
    // -------------------------------------------------------------------------

    public function setWifi2gCredentials(string $ssid, string $password): TaskResponse
    {
        return $this->setMany([
            'wifi.2g.ssid'     => $ssid,
            'wifi.2g.password' => $password,
        ]);
    }

    public function setWifi5gCredentials(string $ssid, string $password): TaskResponse
    {
        return $this->setMany([
            'wifi.5g.ssid'     => $ssid,
            'wifi.5g.password' => $password,
        ]);
    }

    public function setPppoeCredentials(string $username, string $password): TaskResponse
    {
        return $this->setMany([
            'wan.pppoe.username' => $username,
            'wan.pppoe.password' => $password,
        ]);
    }

    public function getWanStatus(): ?string
    {
        return $this->get('wan.connection.status');
    }

    public function isWanConnected(): bool
    {
        return strtolower((string) $this->getWanStatus()) === 'connected';
    }
}
