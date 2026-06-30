<?php

namespace Plimsistemas\TR069\Vendors\Intelbras;

use Plimsistemas\TR069\Vendors\AbstractVendor;

class IntelbrasVendor extends AbstractVendor
{
    public function key(): string
    {
        return 'intelbras';
    }

    public function name(): string
    {
        return 'Intelbras';
    }

    public function manufacturerNames(): array
    {
        return ['Intelbras', 'INTELBRAS'];
    }
}
