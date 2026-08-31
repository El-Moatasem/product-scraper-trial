<?php

namespace App\Data;

final readonly class ProxyLease
{
    public function __construct(
        public string $id,
        public ?string $url,
    ) {}

    public static function direct(): self
    {
        return new self('', null);
    }
}
