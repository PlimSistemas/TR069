<?php

namespace Plimsistemas\TR069\Vendors\ZTE;

use Plimsistemas\TR069\Vendors\AbstractVendor;

class ZTEVendor extends AbstractVendor
{
    public function key(): string
    {
        return 'zte';
    }

    public function name(): string
    {
        return 'ZTE';
    }

    public function manufacturerNames(): array
    {
        return ['ZTE', 'ZTE Corporation'];
    }
}
