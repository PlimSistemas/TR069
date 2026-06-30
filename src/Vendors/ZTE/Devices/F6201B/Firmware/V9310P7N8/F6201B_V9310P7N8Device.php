<?php

namespace Plimsistemas\TR069\Vendors\ZTE\Devices\F6201B\Firmware\V9310P7N8;

use Plimsistemas\TR069\Vendors\ZTE\Devices\F6201B\F6201BDevice;

/**
 * ZTE F6201B — Firmware V9.3.10P7N8 (camada MODELO/FIRMWARE).
 *
 * Herda toda a resolução: AbstractDevice → ZTEDevice (marca) → F6201BDevice
 * (modelo) → esta classe (firmware). Sobrescreva aqui SOMENTE os parâmetros
 * que diferem NESTE firmware — a chave definida aqui vence as camadas acima.
 */
class F6201B_V9310P7N8Device extends F6201BDevice
{
    protected function parameterMap(): array
    {
        return array_merge(parent::parameterMap(), [
            // se este firmware expusesse o GPON SN em outro path:
            'easy_mesh.version' => 'InternetGatewayDevice.X_ZTE-COM_EasyMesh.MeshAgent.Version',
            'gpon_sn'           => 'InternetGatewayDevice.DeviceInfo.Description',
        ]);
    }

    public function firmwareVersion(): string
    {
        return 'V9.3.10P7N8';
    }
}
