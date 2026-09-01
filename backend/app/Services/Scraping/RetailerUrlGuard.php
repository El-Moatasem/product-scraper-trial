<?php

namespace App\Services\Scraping;

use App\Exceptions\InvalidProductUrlException;

final class RetailerUrlGuard
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly array $allowedHosts,
    ) {}

    public function assertAllowed(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidProductUrlException('The retailer URL is invalid.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = $parts['port'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidProductUrlException('Only HTTP and HTTPS retailer URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidProductUrlException('Retailer URLs cannot contain credentials.');
        }

        if ($port !== null && ! in_array((int) $port, [80, 443], true)) {
            throw new InvalidProductUrlException('The retailer URL uses an unsupported port.');
        }

        foreach ($this->allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return;
            }
        }

        throw new InvalidProductUrlException('This retailer is not in the configured allowlist.');
    }
}
