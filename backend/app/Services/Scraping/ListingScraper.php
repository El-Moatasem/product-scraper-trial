<?php

namespace App\Services\Scraping;

use App\Data\ListingScrapeResult;
use App\Exceptions\ScrapingException;
use Throwable;

final class ListingScraper
{
    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly ListingParser $listingParser,
        private readonly ProductScraper $productScraper,
    ) {}

    public function scrape(string $listingUrl, int $limit, int $maxPages): ListingScrapeResult
    {
        $productUrls = [];
        $visitedPages = [];
        $currentUrl = $listingUrl;
        $pagesVisited = 0;

        while ($currentUrl !== null && $pagesVisited < $maxPages && count($productUrls) < $limit) {
            if (isset($visitedPages[$currentUrl])) {
                break;
            }

            $visitedPages[$currentUrl] = true;
            $html = $this->pageFetcher->fetch($currentUrl, 'listing page');
            $page = $this->listingParser->parse($html, $currentUrl);
            $pagesVisited++;

            foreach ($page->productUrls as $productUrl) {
                $productUrls[$productUrl] = true;

                if (count($productUrls) >= $limit) {
                    break;
                }
            }

            $currentUrl = $page->nextPageUrl;
        }

        $urls = array_slice(array_keys($productUrls), 0, $limit);

        if ($urls === []) {
            throw new ScrapingException('The listing page did not contain supported Amazon or Jumia product links.');
        }

        $items = [];
        $errors = [];

        foreach ($urls as $productUrl) {
            try {
                $items[] = [
                    'url' => $productUrl,
                    'product' => $this->productScraper->scrape($productUrl),
                ];
            } catch (Throwable $exception) {
                $errors[] = [
                    'url' => $productUrl,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($items === []) {
            throw new ScrapingException('Product links were discovered, but none could be scraped successfully.');
        }

        return new ListingScrapeResult(
            items: $items,
            discovered: count($urls),
            failed: count($errors),
            pagesVisited: $pagesVisited,
            errors: $errors,
        );
    }
}
