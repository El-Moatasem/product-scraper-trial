<?php

namespace App\Services\Scraping;

use App\Data\ListingPageData;
use App\Exceptions\ScrapingException;
use DOMDocument;
use DOMNode;
use DOMXPath;

final class ListingParser
{
    public function parse(string $html, string $sourceUrl): ListingPageData
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new ScrapingException('The listing page returned invalid HTML.');
        }

        $xpath = new DOMXPath($document);
        $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
        $urls = [];

        foreach ($this->productLinkSelectors($host) as $selector) {
            $nodes = $xpath->query($selector);
            if (! $nodes instanceof \DOMNodeList) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMNode) {
                    continue;
                }

                $href = trim($node->nodeValue ?? '');
                $url = $this->normalizeProductUrl($href, $sourceUrl, $host);

                if ($url !== null) {
                    $urls[$url] = true;
                }
            }
        }

        $nextPageUrl = $this->firstUrl($xpath, $this->nextPageSelectors($host), $sourceUrl);

        return new ListingPageData(
            productUrls: array_keys($urls),
            nextPageUrl: $nextPageUrl,
        );
    }

    /** @return list<string> */
    private function productLinkSelectors(string $host): array
    {
        if (str_contains($host, 'amazon.')) {
            return [
                "//*[@data-component-type='s-search-result']//h2//a[@href]/@href",
                "//*[@data-asin and string-length(normalize-space(@data-asin)) > 0]//a[contains(@href, '/dp/')]/@href",
                "//main//a[contains(@href, '/dp/')]/@href",
                "//a[contains(@href, '/gp/product/')]/@href",
            ];
        }

        if (str_contains($host, 'jumia.')) {
            return [
                "//article[contains(concat(' ', normalize-space(@class), ' '), ' prd ')]//a[contains(concat(' ', normalize-space(@class), ' '), ' core ') and @href]/@href",
                "//article[@data-id]//a[@href][1]/@href",
                "//main//a[contains(@href, '.html')]/@href",
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function nextPageSelectors(string $host): array
    {
        $generic = ["//link[@rel='next']/@href"];

        if (str_contains($host, 'amazon.')) {
            return [
                "//a[contains(concat(' ', normalize-space(@class), ' '), ' s-pagination-next ') and not(contains(concat(' ', normalize-space(@class), ' '), ' s-pagination-disabled '))]/@href",
                "//li[contains(concat(' ', normalize-space(@class), ' '), ' a-last ')]//a[@href]/@href",
                ...$generic,
            ];
        }

        if (str_contains($host, 'jumia.')) {
            return [
                "//a[@aria-label='Next Page' and @href]/@href",
                "//a[contains(concat(' ', normalize-space(@class), ' '), ' pg ') and contains(@href, 'page=') and contains(translate(@aria-label, 'NEXT', 'next'), 'next')]/@href",
                ...$generic,
            ];
        }

        return $generic;
    }

    /** @param list<string> $selectors */
    private function firstUrl(DOMXPath $xpath, array $selectors, string $sourceUrl): ?string
    {
        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            $node = $nodes instanceof \DOMNodeList ? $nodes->item(0) : null;

            if (! $node instanceof DOMNode) {
                continue;
            }

            $href = trim($node->nodeValue ?? '');
            $url = $this->absoluteUrl($href, $sourceUrl);

            if ($url !== null && $this->sameSite($url, $sourceUrl)) {
                return $url;
            }
        }

        return null;
    }

    private function normalizeProductUrl(string $href, string $sourceUrl, string $sourceHost): ?string
    {
        $url = $this->absoluteUrl($href, $sourceUrl);
        if ($url === null || ! $this->sameSite($url, $sourceUrl)) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (str_contains($sourceHost, 'amazon.')) {
            if (! preg_match('~/(?:dp|gp/product)/([A-Z0-9]{10})(?:[/?]|$)~i', $path.'/', $matches)) {
                return null;
            }

            return $scheme.'://'.$host.'/dp/'.strtoupper($matches[1]);
        }

        if (str_contains($sourceHost, 'jumia.')) {
            if (! str_ends_with(strtolower($path), '.html')) {
                return null;
            }

            return $scheme.'://'.$host.$path;
        }

        return null;
    }

    private function absoluteUrl(string $href, string $sourceUrl): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
            return null;
        }

        if (filter_var($href, FILTER_VALIDATE_URL)) {
            return $this->stripFragment($href);
        }

        $source = parse_url($sourceUrl);
        $scheme = (string) ($source['scheme'] ?? '');
        $host = (string) ($source['host'] ?? '');
        $port = isset($source['port']) ? ':'.$source['port'] : '';
        $path = (string) ($source['path'] ?? '/');

        if ($scheme === '' || $host === '') {
            return null;
        }

        if (str_starts_with($href, '//')) {
            return $this->stripFragment($scheme.':'.$href);
        }

        $origin = $scheme.'://'.$host.$port;

        if (str_starts_with($href, '/')) {
            return $this->stripFragment($origin.$href);
        }

        if (str_starts_with($href, '?')) {
            return $this->stripFragment($origin.$path.$href);
        }

        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $directory = $directory === '.' ? '' : $directory;

        return $this->stripFragment($origin.$directory.'/'.$href);
    }

    private function sameSite(string $candidateUrl, string $sourceUrl): bool
    {
        $candidateHost = strtolower(rtrim((string) parse_url($candidateUrl, PHP_URL_HOST), '.'));
        $sourceHost = strtolower(rtrim((string) parse_url($sourceUrl, PHP_URL_HOST), '.'));

        if ($candidateHost === '' || $sourceHost === '') {
            return false;
        }

        return $candidateHost === $sourceHost
            || str_ends_with($candidateHost, '.'.$sourceHost)
            || str_ends_with($sourceHost, '.'.$candidateHost);
    }

    private function stripFragment(string $url): string
    {
        $position = strpos($url, '#');

        return $position === false ? $url : substr($url, 0, $position);
    }
}
