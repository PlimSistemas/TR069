<?php

namespace Plimsistemas\TR069\Vendors\FiberHome;

use Plimsistemas\TR069\Vendors\AbstractVendor;

class FiberHomeVendor extends AbstractVendor
{
    public function key(): string
    {
        return 'fiberhome';
    }

    public function name(): string
    {
        return 'FiberHome';
    }

    public function manufacturerNames(): array
    {
        return ['FiberHome', 'Fiberhome', 'FIBERHOME', 'FiberHome Technologies'];
    }
}
