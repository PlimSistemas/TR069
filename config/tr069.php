<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GenieACS API Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('TR069_BASE_URL', 'https://tr069.plim.net.br/api'),

    /*
    |--------------------------------------------------------------------------
    | Basic Auth
    |--------------------------------------------------------------------------
    */
    'username' => env('TR069_USERNAME'),
    'password' => env('TR069_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Options
    |--------------------------------------------------------------------------
    */
    'timeout'    => (int) env('TR069_TIMEOUT', 30),
    'verify_ssl' => (bool) env('TR069_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Auto-descoberta de handlers
    |--------------------------------------------------------------------------
    | Quando true, varre src/Vendors e registra automaticamente todo handler
    | concreto que se autodescreve (vendor()/model()/firmwareVersion()), sem
    | precisar listá-los em 'devices' abaixo. O array 'devices' continua válido
    | e tem PRECEDÊNCIA sobre o que for descoberto (útil para forçar overrides).
    */
    'auto_discover' => (bool) env('TR069_AUTO_DISCOVER', true),

    /*
    |--------------------------------------------------------------------------
    | Device Registry
    |--------------------------------------------------------------------------
    | Map: vendor -> model -> firmware -> handler class
    |
    | Use '*' como firmware wildcard para capturar versões não registradas.
    | A resolução prioriza firmware exato e cai no wildcard como fallback.
    |
    | O "vendor" key deve corresponder ao VendorInterface::key() do fabricante.
    */
    'devices' => [

        // ----------------------------------------------------------------
        // ZTE
        // ----------------------------------------------------------------
        'zte' => [
            'F670L'  => ['*'  => \Plimsistemas\TR069\Vendors\ZTE\Devices\F670L\F670LDevice::class],
            //'F6600'  => ['*'  => \Plimsistemas\TR069\Vendors\ZTE\Devices\F670L\F66600Device::class],
            //'F6600P' => ['*'  => \Plimsistemas\TR069\Vendors\ZTE\Devices\F670L\F66600PDevice::class],
            'F6201B' => ['*' => \Plimsistemas\TR069\Vendors\ZTE\Devices\F6201B\F6201BDevice::class],
        ],

        // ----------------------------------------------------------------
        // FiberHome — adicione modelos aqui conforme homologação
        // ----------------------------------------------------------------
        'fiberhome' => [
            'HG6143D' => ['*' => \Plimsistemas\TR069\Vendors\FiberHome\Devices\HG6143D\HG6143DDevice::class],
            'HG6243D' => ['*' => \Plimsistemas\TR069\Vendors\FiberHome\Devices\HG6245D\HG6245DDevice::class]
            ],

        // ----------------------------------------------------------------
        // Intelbras
        // ----------------------------------------------------------------
        'intelbras' => [
            'W5-1200F' => [
                '*' => \Plimsistemas\TR069\Vendors\Intelbras\Devices\W51200F\W51200FDevice::class,
            ],
        ],

    ],

];
