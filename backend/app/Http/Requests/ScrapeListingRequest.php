<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScrapeListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('scraping.listing.max_limit')],
            'max_pages' => ['sometimes', 'integer', 'min:1', 'max:'.config('scraping.listing.max_pages')],
        ];
    }
}
