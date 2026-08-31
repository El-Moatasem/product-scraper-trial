<?php

namespace App\Providers;

use App\Contracts\ProxyManager;
use App\Services\Proxy\HttpProxyManager;
use App\Services\Scraping\ProductParser;
use App\Services\Scraping\ProductScraper;
use App\Services\Scraping\UserAgentRotator;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClientInterface::class, fn (): ClientInterface => new Client);

        $this->app->singleton(UserAgentRotator::class, fn (): UserAgentRotator => new UserAgentRotator(
            config('scraping.user_agents'),
        ));

        $this->app->singleton(ProxyManager::class, fn (): ProxyManager => new HttpProxyManager(
            client: new Client,
            baseUrl: config('scraping.proxy_manager.url'),
            token: config('scraping.proxy_manager.token'),
            required: config('scraping.proxy_manager.required'),
        ));

        $this->app->bind(ProductScraper::class, fn (): ProductScraper => new ProductScraper(
            client: $this->app->make(ClientInterface::class),
            proxyManager: $this->app->make(ProxyManager::class),
            userAgentRotator: $this->app->make(UserAgentRotator::class),
            parser: $this->app->make(ProductParser::class),
            allowedHosts: config('scraping.allowed_hosts'),
            timeoutSeconds: config('scraping.timeout_seconds'),
            connectTimeoutSeconds: config('scraping.connect_timeout_seconds'),
            maxAttempts: config('scraping.max_attempts'),
            maxResponseBytes: config('scraping.max_response_bytes'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
