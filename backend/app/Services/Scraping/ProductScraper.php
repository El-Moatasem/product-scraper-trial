<?php

namespace App\Services\Scraping;

use App\Contracts\ProxyManager;
use App\Data\ProductData;
use App\Exceptions\InvalidProductUrlException;
use App\Exceptions\ScrapingException;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

final class ProductScraper
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly ProxyManager $proxyManager,
        private readonly UserAgentRotator $userAgentRotator,
        private readonly ProductParser $parser,
        private readonly array $allowedHosts,
        private readonly float $timeoutSeconds,
        private readonly float $connectTimeoutSeconds,
        private readonly int $maxAttempts,
        private readonly int $maxResponseBytes,
    ) {}

    public function scrape(string $url): ProductData
    {
        $this->assertAllowedUrl($url);
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $lease = $this->proxyManager->lease();

            try {
                $options = [
                    'headers' => [
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.9',
                        'Cache-Control' => 'no-cache',
                        'User-Agent' => $this->userAgentRotator->next(),
                    ],
                    'http_errors' => false,
                    'timeout' => $this->timeoutSeconds,
                    'connect_timeout' => $this->connectTimeoutSeconds,
                    'allow_redirects' => [
                        'max' => 3,
                        'strict' => true,
                        'referer' => true,
                        'on_redirect' => function (
                            RequestInterface $request,
                            ResponseInterface $response,
                            UriInterface $uri,
                        ): void {
                            $this->assertAllowedUrl((string) $uri);
                        },
                    ],
                ];

                if ($lease->url !== null) {
                    $options['proxy'] = $lease->url;
                }

                $response = $this->client->request('GET', $url, $options);
                $status = $response->getStatusCode();

                if ($status < 200 || $status >= 300) {
                    throw new ScrapingException("The product page returned HTTP {$status}.");
                }

                $html = (string) $response->getBody();
                if (strlen($html) > $this->maxResponseBytes) {
                    throw new ScrapingException('The product page exceeded the maximum response size.');
                }

                $product = $this->parser->parse($html, $url);
                $this->proxyManager->report($lease, true);

                return $product;
            } catch (Throwable $exception) {
                $this->proxyManager->report($lease, false);
                $lastException = $exception;
            }
        }

        if ($lastException instanceof ScrapingException) {
            throw $lastException;
        }

        throw new ScrapingException('Unable to fetch the product page.', previous: $lastException);
    }

    private function assertAllowedUrl(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidProductUrlException('The product URL is invalid.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = $parts['port'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidProductUrlException('Only HTTP and HTTPS product URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidProductUrlException('Product URLs cannot contain credentials.');
        }

        if ($port !== null && ! in_array((int) $port, [80, 443], true)) {
            throw new InvalidProductUrlException('The product URL uses an unsupported port.');
        }

        foreach ($this->allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return;
            }
        }

        throw new InvalidProductUrlException('This retailer is not in the configured allowlist.');
    }
}
