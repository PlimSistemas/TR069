<?php

namespace Plimsistemas\TR069\Device;

use Plimsistemas\TR069\GenieACS\Responses\DeviceResponse;

class DeviceInfo
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $manufacturer,
        public readonly string  $oui,
        public readonly string  $productClass,
        public readonly string  $serialNumber,
        public readonly ?string $softwareVersion,
        protected DeviceResponse $response,
    ) {}

    public static function fromResponse(DeviceResponse $response): static
    {
        return new static(
            id:              $response->getId() ?? '',
            manufacturer:    $response->getManufacturer() ?? '',
            oui:             $response->getOui() ?? '',
            productClass:    $response->getProductClass() ?? '',
            serialNumber:    $response->getSerialNumber() ?? '',
            softwareVersion: $response->getSoftwareVersion(),
            response:        $response,
        );
    }

    public function getParameter(string $path): mixed
    {
        return $this->response->getParameter($path);
    }

    public function raw(): array
    {
        return $this->response->raw();
    }
}
