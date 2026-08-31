<?php

namespace App\Services\Scraping;

use App\Data\ProductData;
use App\Exceptions\ScrapingException;
use DOMDocument;
use DOMNode;
use DOMXPath;

final class ProductParser
{
    public function parse(string $html, string $sourceUrl): ProductData
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new ScrapingException('The product page returned invalid HTML.');
        }

        $xpath = new DOMXPath($document);
        $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));

        $title = $this->first($xpath, $this->titleSelectors($host));
        $rawPrice = $this->first($xpath, $this->priceSelectors($host));
        $image = $this->first($xpath, $this->imageSelectors($host));

        if ($title === '' || $rawPrice === '' || $image === '') {
            throw new ScrapingException('The page did not contain a complete product title, price, and image.');
        }

        return new ProductData(
            title: $this->cleanText($title),
            price: $this->normalizePrice($rawPrice),
            imageUrl: $this->absoluteUrl($image, $sourceUrl),
        );
    }

    /** @return list<string> */
    private function titleSelectors(string $host): array
    {
        $specific = str_contains($host, 'amazon.')
            ? ["//*[@id='productTitle']"]
            : ["//h1[contains(concat(' ', normalize-space(@class), ' '), ' -fs20 ')]", '//main//h1'];

        return [...$specific, "//meta[@property='og:title']/@content", '//title'];
    }

    /** @return list<string> */
    private function priceSelectors(string $host): array
    {
        $specific = str_contains($host, 'amazon.')
            ? [
                "(//*[contains(concat(' ', normalize-space(@class), ' '), ' a-price ')]//*[contains(concat(' ', normalize-space(@class), ' '), ' a-offscreen ')])[1]",
                "//*[@id='priceblock_ourprice']",
                "//*[@id='priceblock_dealprice']",
            ]
            : [
                "(//span[contains(concat(' ', normalize-space(@class), ' '), ' -fs24 ')])[1]",
                '(//*[@data-price])[1]/@data-price',
            ];

        return [
            ...$specific,
            "//meta[@property='product:price:amount']/@content",
            "//meta[@itemprop='price']/@content",
        ];
    }

    /** @return list<string> */
    private function imageSelectors(string $host): array
    {
        $specific = str_contains($host, 'amazon.')
            ? ["//*[@id='landingImage']/@data-old-hires", "//*[@id='landingImage']/@src"]
            : [
                "(//main//img[contains(concat(' ', normalize-space(@class), ' '), ' -fw ')])/@data-src",
                "(//main//img[contains(concat(' ', normalize-space(@class), ' '), ' -fw ')])/@src",
            ];

        return [...$specific, "//meta[@property='og:image']/@content"];
    }

    /** @param list<string> $selectors */
    private function first(DOMXPath $xpath, array $selectors): string
    {
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            $node = $nodes instanceof \DOMNodeList ? $nodes->item(0) : null;

            if ($node instanceof DOMNode) {
                $value = trim($node->nodeValue ?? '');

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function cleanText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));
    }

    private function normalizePrice(string $rawPrice): string
    {
        $value = (string) preg_replace('/[^0-9,.]/u', '', $rawPrice);

        if ($value === '') {
            throw new ScrapingException('The product price could not be parsed.');
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $value = str_replace($thousandsSeparator, '', $value);
            $value = str_replace($decimalSeparator, '.', $value);
        } elseif ($lastComma !== false) {
            $value = $this->normalizeSingleSeparator($value, ',');
        } elseif ($lastDot !== false) {
            $value = $this->normalizeSingleSeparator($value, '.');
        }

        if (! is_numeric($value)) {
            throw new ScrapingException('The product price could not be parsed.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeSingleSeparator(string $value, string $separator): string
    {
        $occurrences = substr_count($value, $separator);
        $digitsAfter = strlen($value) - (int) strrpos($value, $separator) - 1;

        if ($occurrences === 1 && in_array($digitsAfter, [1, 2], true)) {
            return str_replace($separator, '.', $value);
        }

        return str_replace($separator, '', $value);
    }

    private function absoluteUrl(string $imageUrl, string $sourceUrl): string
    {
        $imageUrl = trim(html_entity_decode($imageUrl, ENT_QUOTES | ENT_HTML5));
        $scheme = (string) parse_url($sourceUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($sourceUrl, PHP_URL_HOST);

        if (str_starts_with($imageUrl, '//')) {
            $imageUrl = $scheme.':'.$imageUrl;
        } elseif (str_starts_with($imageUrl, '/')) {
            $imageUrl = $scheme.'://'.$host.$imageUrl;
        }

        $imageScheme = strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME));
        if (! filter_var($imageUrl, FILTER_VALIDATE_URL) || ! in_array($imageScheme, ['http', 'https'], true)) {
            throw new ScrapingException('The product image URL is invalid.');
        }

        return $imageUrl;
    }
}
