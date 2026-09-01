<?php

namespace App\Data;

final readonly class ListingScrapeResult
{
    /**
     * @param  list<array{url: string, product: ProductData}>  $items
     * @param  list<array{url: string, message: string}>  $errors
     */
    public function __construct(
        public array $items,
        public int $discovered,
        public int $failed,
        public int $pagesVisited,
        public array $errors,
    ) {}
}
