<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidProductUrlException;
use App\Exceptions\ScrapingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScrapeListingRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Scraping\ListingScraper;
use Illuminate\Http\JsonResponse;

class ScrapeListingController extends Controller
{
    public function store(
        ScrapeListingRequest $request,
        ListingScraper $scraper,
    ): JsonResponse {
        $limit = (int) $request->input('limit', config('scraping.listing.default_limit'));
        $maxPages = (int) $request->input('max_pages', config('scraping.listing.default_max_pages'));

        try {
            $result = $scraper->scrape(
                listingUrl: $request->string('url')->toString(),
                limit: $limit,
                maxPages: $maxPages,
            );
        } catch (InvalidProductUrlException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (ScrapingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        $products = [];
        $created = 0;

        foreach ($result->items as $item) {
            $data = $item['product'];
            $product = Product::query()->firstOrCreate([
                'title' => $data->title,
                'price' => $data->price,
                'image_url' => $data->imageUrl,
            ]);

            if ($product->wasRecentlyCreated) {
                $created++;
            }

            $products[] = (new ProductResource($product))->resolve($request);
        }

        return response()->json([
            'data' => [
                'source_url' => $request->string('url')->toString(),
                'pages_visited' => $result->pagesVisited,
                'discovered' => $result->discovered,
                'scraped' => count($products),
                'created' => $created,
                'existing' => count($products) - $created,
                'failed' => $result->failed,
                'products' => $products,
                'errors' => $result->errors,
            ],
        ], 201);
    }
}
