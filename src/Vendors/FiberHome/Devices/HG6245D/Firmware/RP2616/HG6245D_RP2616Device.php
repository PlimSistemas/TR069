<?php

namespace Plimsistemas\TR069\Vendors\FiberHome\Devices\HG6245D\Firmware\RP2616;

use Plimsistemas\TR069\Vendors\FiberHome\Devices\HG6245D\HG6245DDevice;

/**
 * FiberHome HG6245D — Firmware RP2616 (camada MODELO/FIRMWARE).
 *
 * Herda toda a resolução: AbstractDevice → FiberHomeDevice (marca) → HG6245DDevice
 * (modelo) → esta classe (firmware). Sobrescreva aqui SOMENTE os parâmetros
 * que diferem NESTE firmware — a chave definida aqui vence as camadas acima.
 */
class HG6245D_RP2616Device extends HG6245DDevice
{
    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // sem diferenças de parâmetro conhecidas neste firmware
        ]);
    }

    public function firmwareVersion(): string
    {
        return 'RP2616';
    }
}
