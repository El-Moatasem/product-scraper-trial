<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_stored_products_newest_first(): void
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
            ->assertJsonPath('data.1.id', $older->id);
    }
}
