<?php

namespace Tests\Unit;

use App\Services\Scraping\ListingParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListingParserTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<string>, string}> */
    public static function listingPages(): iterable
    {
        yield 'Jumia category listing' => [
            'jumia-listing.html',
            'https://www.jumia.com.eg/laptops/?sort=lowest-price&price=7999-345299#catalog-listing',
            [
                'https://www.jumia.com.eg/hp-renewed-elitebook-840-g3-134005884.html',
                'https://www.jumia.com.eg/lenovo-ideapad-d330-123456789.html',
            ],
            'https://www.jumia.com.eg/laptops/?page=2&sort=lowest-price&price=7999-345299',
        ];

        yield 'Amazon category listing' => [
            'amazon-listing.html',
            'https://www.amazon.eg/-/en/b?ie=UTF8&node=21833002031',
            [
                'https://www.amazon.eg/dp/B0ABC12345',
                'https://www.amazon.eg/dp/B0XYZ67890',
            ],
            'https://www.amazon.eg/s?k=laptops&page=2',
        ];
    }

    #[DataProvider('listingPages')]
    public function test_it_extracts_product_links_and_pagination(
        string $fixture,
        string $url,
        array $expectedProductUrls,
        string $expectedNextPage,
    ): void {
        $html = file_get_contents(__DIR__.'/../Fixtures/'.$fixture);
        self::assertIsString($html);

        $page = (new ListingParser)->parse($html, $url);

        self::assertSame($expectedProductUrls, $page->productUrls);
        self::assertSame($expectedNextPage, $page->nextPageUrl);
    }
}
