<?php

namespace Tests\Unit;

use App\Services\Scraping\ProductParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductParserTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function productPages(): iterable
    {
        yield 'Amazon selectors and US price' => [
            'amazon-product.html',
            'https://www.amazon.com/dp/example',
            'Noise-Cancelling Wireless Headphones',
            '1299.00',
            'https://images.example.com/headphones.jpg',
        ];

        yield 'Jumia selectors, thousands separator, and relative image' => [
            'jumia-product.html',
            'https://www.jumia.com.eg/example.html',
            'Android Smartphone 256 GB',
            '89999.00',
            'https://www.jumia.com.eg/images/phone.jpg',
        ];
    }

    #[DataProvider('productPages')]
    public function test_it_parses_supported_product_pages(
        string $fixture,
        string $url,
        string $expectedTitle,
        string $expectedPrice,
        string $expectedImage,
    ): void {
        $html = file_get_contents(__DIR__.'/../Fixtures/'.$fixture);

        self::assertIsString($html);

        $product = (new ProductParser)->parse($html, $url);

        self::assertSame($expectedTitle, $product->title);
        self::assertSame($expectedPrice, $product->price);
        self::assertSame($expectedImage, $product->imageUrl);
    }
}
