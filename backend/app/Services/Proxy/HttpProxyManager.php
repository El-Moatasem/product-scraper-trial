<?php

namespace App\Services\Proxy;

use App\Contracts\ProxyManager;
use App\Data\ProxyLease;
use App\Exceptions\ScrapingException;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

final class HttpProxyManager implements ProxyManager
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $baseUrl,
        private readonly string $token = '',
        private readonly bool $required = false,
    ) {}

    public function lease(): ProxyLease
    {
        try {
            $response = $this->client->request('GET', $this->baseUrl.'/v1/proxies/next', [
                'headers' => $this->headers(),
                'http_errors' => false,
                'timeout' => 1.5,
                'connect_timeout' => 0.75,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new ScrapingException('Proxy manager returned an unexpected status.');
            }

            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $url = isset($payload['url']) && is_string($payload['url']) && $payload['url'] !== ''
                ? $payload['url']
                : null;

            return new ProxyLease((string) ($payload['id'] ?? ''), $url);
        } catch (Throwable $exception) {
            if ($this->required) {
                throw new ScrapingException('Proxy manager is unavailable.', previous: $exception);
            }

            Log::warning('Proxy manager unavailable; using a direct connection.', [
                'exception' => $exception::class,
            ]);

            return ProxyLease::direct();
        }
    }

    public function report(ProxyLease $lease, bool $success): void
    {
        if ($lease->id === '') {
            return;
        }

        try {
            $this->client->request('POST', sprintf(
                '%s/v1/proxies/%s/report',
                $this->baseUrl,
                rawurlencode($lease->id),
            ), [
                'headers' => $this->headers(),
                'json' => ['success' => $success],
                'http_errors' => false,
                'timeout' => 1.5,
                'connect_timeout' => 0.75,
            ]);
        } catch (Throwable $exception) {
            Log::notice('Unable to report proxy health.', [
                'proxy_id' => $lease->id,
                'exception' => $exception::class,
            ]);
        }
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->token === ''
            ? ['Accept' => 'application/json']
            : ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token];
    }
}
