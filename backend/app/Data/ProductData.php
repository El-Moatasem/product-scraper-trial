<?php

namespace App\Data;

final readonly class ProductData
{
    public function __construct(
        public string $title,
        public string $price,
        public string $imageUrl,
    ) {}
}
