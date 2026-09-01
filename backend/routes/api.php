<?php

use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ScrapeListingController;
use App\Http\Controllers\Api\ScrapeProductController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);
Route::post('/products/scrape', [ScrapeProductController::class, 'store'])
    ->middleware('throttle:10,1');
Route::post('/products/scrape-listing', [ScrapeListingController::class, 'store'])
    ->middleware('throttle:5,1');
