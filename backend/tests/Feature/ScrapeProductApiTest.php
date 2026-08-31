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

class ScrapeProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_scrapes_and_persists_a_product(): void
    {
        $html = file_get_contents(__DIR__.'/../Fixtures/amazon-product.html');
        self::assertIsString($html);

        $handler = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $html),
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
                // Health reporting is covered by the Go service tests.
            }
        });

        $response = $this->postJson('/api/products/scrape', [
            'url' => 'https://www.amazon.com/dp/example',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Noise-Cancelling Wireless Headphones')
            ->assertJsonPath('data.price', '1299.00');

        $this->assertDatabaseHas('products', [
            'title' => 'Noise-Cancelling Wireless Headphones',
            'price' => '1299.00',
        ]);
    }

    public function test_it_rejects_a_host_outside_the_allowlist(): void
    {
        $response = $this->postJson('/api/products/scrape', [
            'url' => 'http://127.0.0.1/internal',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This retailer is not in the configured allowlist.');
    }

    public function test_it_validates_the_url_shape(): void
    {
        $this->postJson('/api/products/scrape', ['url' => 'not-a-url'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }
}
