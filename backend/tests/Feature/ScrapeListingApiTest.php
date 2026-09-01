<?php

namespace Tests\Feature;

use App\Contracts\ProxyManager;
use App\Data\ProxyLease;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScrapeListingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_discovers_scrapes_and_persists_products_from_a_listing(): void
    {
        $listing = file_get_contents(__DIR__.'/../Fixtures/jumia-listing.html');
        $product = file_get_contents(__DIR__.'/../Fixtures/jumia-product.html');
        self::assertIsString($listing);
        self::assertIsString($product);

        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $listing),
            new Response(200, ['Content-Type' => 'text/html'], $product),
        ]));

        $this->app->instance(ClientInterface::class, new Client(['handler' => $handler]));
        $this->app->instance(ProxyManager::class, new class implements ProxyManager
        {
            public function lease(): ProxyLease
            {
                return ProxyLease::direct();
            }

            public function report(ProxyLease $lease, bool $success): void
            {
                // Proxy health behavior is covered by the Go service tests.
            }
        });

        $response = $this->postJson('/api/products/scrape-listing', [
            'url' => 'https://www.jumia.com.eg/laptops/?sort=lowest-price&price=7999-345299',
            'limit' => 1,
            'max_pages' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.pages_visited', 1)
            ->assertJsonPath('data.discovered', 1)
            ->assertJsonPath('data.scraped', 1)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.products.0.title', 'Android Smartphone 256 GB')
            ->assertJsonPath('data.products.0.price', '89999.00');

        $this->assertDatabaseHas('products', [
            'title' => 'Android Smartphone 256 GB',
            'price' => '89999.00',
        ]);
    }

    public function test_it_validates_listing_limits(): void
    {
        $this->postJson('/api/products/scrape-listing', [
            'url' => 'https://www.jumia.com.eg/laptops/',
            'limit' => 999,
            'max_pages' => 99,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit', 'max_pages']);
    }
}
