# Laravel scraping API

The backend exposes three product endpoints:

- `GET /api/products` — list stored products.
- `POST /api/products/scrape` — scrape one allowed Amazon/Jumia product page.
- `POST /api/products/scrape-listing` — discover product links from a bounded Amazon/Jumia category/listing page crawl, scrape the discovered product pages, and persist successful results.

See the repository root [`README.md`](../README.md) for Docker/native setup and API examples, and [`docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md) for the detailed design.
