<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_stored_products_newest_first_with_pagination_metadata(): void
    {
        $older = Product::query()->create([
            'title' => 'Older product',
            'price' => '10.50',
            'image_url' => 'https://images.example.com/older.jpg',
        ]);
        $newer = Product::query()->create([
            'title' => 'Newer product',
            'price' => '20.75',
            'image_url' => 'https://images.example.com/newer.jpg',
        ]);

        $response = $this->getJson('/api/products');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.price', '20.75')
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 9)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_it_can_request_a_specific_products_page_and_page_size(): void
    {
        foreach (range(1, 5) as $index) {
            Product::query()->create([
                'title' => "Product {$index}",
                'price' => (string) (10 + $index),
                'image_url' => "https://images.example.com/product-{$index}.jpg",
            ]);
        }

        $response = $this->getJson('/api/products?page=2&per_page=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Product 3')
            ->assertJsonPath('data.1.title', 'Product 2')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonPath('meta.to', 4)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_it_caps_the_page_size_to_prevent_unbounded_reads(): void
    {
        Product::query()->create([
            'title' => 'Only product',
            'price' => '15.00',
            'image_url' => 'https://images.example.com/only.jpg',
        ]);

        $this->getJson('/api/products?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 48);
    }
}
