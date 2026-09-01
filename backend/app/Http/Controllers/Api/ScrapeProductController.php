<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidProductUrlException;
use App\Exceptions\ScrapingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScrapeProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Scraping\ProductScraper;
use Illuminate\Http\JsonResponse;

class ScrapeProductController extends Controller
{
    public function store(
        ScrapeProductRequest $request,
        ProductScraper $scraper,
    ): ProductResource|JsonResponse {
        try {
            $data = $scraper->scrape($request->string('url')->toString());
        } catch (InvalidProductUrlException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (ScrapingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        $product = Product::query()->create([
            'title' => $data->title,
            'price' => $data->price,
            'image_url' => $data->imageUrl,
        ]);

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }
}
