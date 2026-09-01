<?php

namespace App\Data;

final readonly class ListingPageData
{
    /** @param list<string> $productUrls */
    public function __construct(
        public array $productUrls,
        public ?string $nextPageUrl,
    ) {}
}
