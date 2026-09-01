<?php

namespace App\Services\Scraping;

use App\Contracts\ProxyManager;
use App\Exceptions\ScrapingException;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

final class PageFetcher
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly ProxyManager $proxyManager,
        private readonly UserAgentRotator $userAgentRotator,
        private readonly RetailerUrlGuard $urlGuard,
        private readonly float $timeoutSeconds,
        private readonly float $connectTimeoutSeconds,
        private readonly int $maxAttempts,
        private readonly int $maxResponseBytes,
    ) {}

    public function fetch(string $url, string $pageLabel = 'page'): string
    {
        $this->urlGuard->assertAllowed($url);
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
                            $this->urlGuard->assertAllowed((string) $uri);
                        },
                    ],
                ];

                if ($lease->url !== null) {
                    $options['proxy'] = $lease->url;
                }

                $response = $this->client->request('GET', $url, $options);
                $status = $response->getStatusCode();

                if ($status < 200 || $status >= 300) {
                    throw new ScrapingException("The {$pageLabel} returned HTTP {$status}.");
                }

                $html = (string) $response->getBody();
                if (strlen($html) > $this->maxResponseBytes) {
                    throw new ScrapingException("The {$pageLabel} exceeded the maximum response size.");
                }

                $this->proxyManager->report($lease, true);

                return $html;
            } catch (Throwable $exception) {
                $this->proxyManager->report($lease, false);
                $lastException = $exception;
            }
        }

        if ($lastException instanceof ScrapingException) {
            throw $lastException;
        }

        throw new ScrapingException("Unable to fetch the {$pageLabel}.", previous: $lastException);
    }
}
