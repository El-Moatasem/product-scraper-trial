<?php

namespace App\Contracts;

use App\Data\ProxyLease;

interface ProxyManager
{
    public function lease(): ProxyLease;

    public function report(ProxyLease $lease, bool $success): void;
}
