<?php

namespace App\Services\Scraping;

use App\Data\ProductData;

final class ProductScraper
{
    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly ProductParser $parser,
    ) {}

    public function scrape(string $url): ProductData
    {
        $html = $this->pageFetcher->fetch($url, 'product page');

        return $this->parser->parse($html, $url);
    }
}
