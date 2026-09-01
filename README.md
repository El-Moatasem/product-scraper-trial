# Product Scraper Trial

A full-stack product scraping service built for the Full-Stack Developer Trial Task. The repository combines:

- **Laravel 13 / PHP** for scraping, validation, persistence, and the JSON API.
- **Next.js 16 / React** for the responsive `/products` interface.
- **Go 1.27** for proxy pool management and health tracking.
- **MySQL 8.4** for product storage.
- **Docker Compose** for running the complete stack locally.
- Automated tests and CI validation for the three application services.

---

## Table of contents

- [Architecture](#architecture)
- [Quick start with Docker](#quick-start-with-docker)
- [Run without Docker](#run-without-docker)
- [API](#api)
- [Proxy management API](#proxy-management-api)
- [Configuration](#configuration)
- [Validation and tests](#validation-and-tests)
- [Troubleshooting](#troubleshooting)
- [Key design decisions](#key-design-decisions)
- [Production extensions](#production-extensions)

---

# Architecture

## Service overview

| Service | Responsibility | Port |
| --- | --- | ---: |
| Next.js | Responsive `/products` UI, loading/error/empty states, and 30-second polling | `3000` |
| Laravel | URL validation, Guzzle requests, user-agent rotation, parsing, persistence, and JSON API | `8000` |
| Go proxy manager | Round-robin proxy leasing, health reports, cooldown, and runtime add/remove API | `8081` |
| MySQL | Durable product storage | `3306` |

## Request flow

```mermaid
flowchart LR
    UI["Next.js /products"] -->|"GET /api/products"| API["Laravel API"]
    UI -->|"POST single/listing scrape"| API
    API --> DB[(MySQL)]
    API -->|"Lease + report"| GO["Go proxy manager"]
    API -->|"Guzzle via proxy or direct"| SHOP["Amazon / Jumia"]
    API -->|"Discover product links"| LIST["Category / listing page"]
    LIST --> SHOP
```

The Go service returns a direct-connection lease when no proxies are configured, so the application can run locally without paid proxy credentials. Laravel still rotates browser-style user-agent headers for scraping attempts.

For the detailed architecture, component boundaries, failure behavior, security decisions, data model, and scaling path, see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Runtime flow

1. A user can paste either a single Amazon/Jumia product URL or a category/listing URL into the Next.js `/products` page. The same operations are also available directly through the API.
2. `POST /api/products/scrape` fetches one product page. `POST /api/products/scrape-listing` fetches a category/listing page, discovers product links, optionally follows bounded pagination, then reuses the single-product scraper for each discovered product.
3. Laravel validates every requested URL and redirect against the configured retailer allowlist before making an external request.
4. Laravel leases the next healthy proxy from the Go proxy manager. If the pool is empty, Go returns a direct connection lease.
5. Guzzle requests each page using the leased proxy, when available, and a rotated browser user-agent.
6. The product parser uses retailer-specific selectors and then falls back to Open Graph/product metadata selectors. The listing parser contains Amazon/Jumia link and pagination selectors.
7. Laravel reports proxy success/failure to Go and persists complete products to MySQL. Listing scrapes avoid duplicate rows when the exact title, price, and image already exist.
8. Next.js reads the paginated `GET /api/products` feed immediately, refreshes the current page every 30 seconds, and refreshes again after a successful scrape action.

## Component boundaries

| Boundary | Owns | Does not own |
| --- | --- | --- |
| Laravel | Input policy, retries, HTTP requests, parsing, persistence, API representation | Proxy-pool health or frontend state |
| Go | Round-robin leases, dynamic add/remove, failure threshold, cooldown | Retailer rules, HTML parsing, persistence |
| Next.js | Product presentation, polling, loading/error/empty states | Scraping or direct database access |
| MySQL | Durable product records | Scheduling or proxy state |

## Failure behavior

```mermaid
flowchart TD
    A["Scrape request"] --> B{"URL allowed?"}
    B -->|No| C["422 response"]
    B -->|Yes| D["Lease proxy"]
    D --> E["Fetch and parse"]
    E -->|Success| F["Report healthy + store"]
    E -->|Failure| G["Report failure + retry"]
    G --> H{"Attempts remain?"}
    H -->|Yes| D
    H -->|No| I["502 response"]
```

- `SCRAPER_MAX_ATTEMPTS` controls bounded retries.
- Each retry can use a new user agent and a new proxy lease.
- A proxy enters cooldown after `PROXY_FAILURE_THRESHOLD` consecutive failures.
- When no configured proxy is healthy, the Go service can return a direct connection lease.
- Set `PROXY_MANAGER_REQUIRED=true` if direct fallback must not be allowed.
- The frontend keeps previously loaded data if a background refresh fails and presents a recoverable error state.

## Security decisions

- Only `http` and `https` product URLs on configured retailer domains are accepted.
- URL credentials and non-standard ports are rejected.
- Redirect destinations are revalidated.
- Guzzle timeouts, maximum redirects, response-size limits, and API throttling bound resource use.
- Proxy credentials are not returned by list/add management responses.
- The complete credential-bearing proxy URL is exposed only by the internal lease operation used by Laravel.
- The Go management bearer token is optional for local development and should be enabled outside development.
- Runtime containers use non-root users where applicable.

> The retailer allowlist reduces the SSRF surface, but a high-security production environment should also use networking-level egress controls and DNS/IP validation.

## Data model

The schema intentionally follows the task requirements:

```text
products(
    id,
    title,
    price,
    image_url,
    created_at
)
```

`price` is stored as `DECIMAL(12,2)` to avoid binary floating-point storage errors.

Currency is not guessed because the requested task model does not include a currency field.

---

# Quick start with Docker

Docker is the easiest way to run the complete application because PHP, Composer, Go, Node.js, and MySQL are supplied by the project containers.

## Prerequisites

Install Docker Desktop or Docker Engine with Docker Compose.

On macOS, make sure Docker Desktop is running before using Compose.

Verify:

```bash
docker --version
docker compose version
docker info
```

If `docker info` reports:

```text
Cannot connect to the Docker daemon
```

start Docker Desktop on macOS:

```bash
open -a Docker
```

Wait until Docker finishes starting, then run:

```bash
docker info
```

## 1. Create the root environment file

From the repository root:

```bash
cp .env.example .env
```

The default environment runs without paid proxies because `PROXY_URLS` is empty.

## 2. Build and start the complete application

```bash
docker compose up --build -d
```

The first build can take several minutes because Docker may need to:

- download PHP, Composer, Go, Node.js, MySQL, and runtime images;
- compile PHP extensions;
- install Composer packages;
- compile the Go microservice;
- install frontend dependencies; and
- create the Next.js production build.

A screen such as:

```text
Building ... (25/29)
[backend stage-1 ...] RUN apk add ...
```

means Docker is **still building**. Wait for the command to finish and return to the shell prompt.

You can also build first and start separately:

```bash
docker compose build
docker compose up -d
```

## 3. Check service status

```bash
docker compose ps
```

Expected services:

```text
mysql
proxy-manager
backend
frontend
```

The services include health checks, so it can take a short time for all of them to become healthy after the initial startup.

## 4. Open the application

Frontend:

```text
http://localhost:3000/products
```

Laravel API:

```text
http://localhost:8000/api/products
```

Go proxy-manager health endpoint:

```text
http://localhost:8081/health
```

## 5. Verify the running services

```bash
curl http://localhost:8000/up
curl http://localhost:8000/api/products
curl http://localhost:8081/health
```

Check Laravel routes:

```bash
docker compose exec backend php artisan route:list
```

Check Laravel migration state:

```bash
docker compose exec backend php artisan migrate:status
```

## 6. View logs

All services:

```bash
docker compose logs -f
```

Backend only:

```bash
docker compose logs -f backend
```

MySQL only:

```bash
docker compose logs -f mysql
```

Proxy manager only:

```bash
docker compose logs -f proxy-manager
```

Frontend only:

```bash
docker compose logs -f frontend
```

Recent logs without following:

```bash
docker compose logs --tail=100 backend mysql proxy-manager frontend
```

## 7. Stop the application

```bash
docker compose down
```

To stop the application **and delete the MySQL volume/data**:

```bash
docker compose down -v
```

Use `-v` only when you intentionally want a fresh database.

## Docker shortcut with Make

The repository also includes a Makefile:

```bash
make up
```

Stop services:

```bash
make down
```

Follow logs:

```bash
make logs
```

---

# Run without Docker

You can run all services natively on macOS/Linux. In this mode you must install and start each required runtime yourself.

## Native requirements

| Dependency | Project requirement |
| --- | --- |
| PHP | `8.3+` |
| Composer | `2.x` |
| MySQL | `8.x` |
| Go | `1.27+` |
| Node.js | `>=24.15.0 <25` |
| npm | Version supplied with the Node.js 24 installation |

Laravel also needs the PHP DOM, cURL, mbstring, and PDO MySQL extensions.

## macOS installation with Homebrew

If you use Homebrew, a typical setup is:

```bash
brew install php composer mysql@8.4 go node@24
```

If a versioned formula is installed but not linked into your shell PATH, add it for the current terminal session:

```bash
export PATH="$(brew --prefix mysql@8.4)/bin:$PATH"
export PATH="$(brew --prefix node@24)/bin:$PATH"
```

Verify your environment:

```bash
php -v
composer --version
mysql --version
go version
node --version
npm --version
```

If your package manager provides newer compatible versions, those are also acceptable as long as they satisfy the project requirements.

---

## 1. Start and configure MySQL

Start MySQL with Homebrew:

```bash
brew services start mysql@8.4
```

Create the local application database and user:

```bash
mysql -u root <<'SQL'
CREATE DATABASE IF NOT EXISTS product_scraper
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'scraper'@'localhost'
    IDENTIFIED BY 'scraper_secret';

CREATE USER IF NOT EXISTS 'scraper'@'127.0.0.1'
    IDENTIFIED BY 'scraper_secret';

GRANT ALL PRIVILEGES ON product_scraper.* TO 'scraper'@'localhost';
GRANT ALL PRIVILEGES ON product_scraper.* TO 'scraper'@'127.0.0.1';

FLUSH PRIVILEGES;
SQL
```

If your MySQL root account has a password, use:

```bash
mysql -u root -p
```

and execute the SQL statements manually.

The backend `.env.example` is already configured for:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=product_scraper
DB_USERNAME=scraper
DB_PASSWORD=scraper_secret
```

---

## 2. Run the Go proxy manager — Terminal 1

From the repository root:

```bash
cd proxy-service
cp .env.example .env
```

The Go service intentionally has no third-party environment-file dependency, so export the `.env` values into the shell:

```bash
set -a
. ./.env
set +a
```

Then run:

```bash
go run ./cmd/server
```

Verify from another terminal:

```bash
curl http://localhost:8081/health
```

The default configuration contains no real proxies, so the service returns direct leases when Laravel requests a connection.

---

## 3. Run Laravel — Terminal 2

From the repository root:

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Laravel should now be available at:

```text
http://localhost:8000
```

Verify:

```bash
curl http://localhost:8000/up
curl http://localhost:8000/api/products
```

Useful Laravel commands:

```bash
php artisan route:list
php artisan migrate:status
php artisan test
```

If `php` or `composer` returns `command not found`, install them or use the Docker workflow instead.

---

## 4. Run Next.js — Terminal 3

From the repository root:

```bash
cd frontend
cp .env.example .env.local
npm ci
npm run dev
```

Open:

```text
http://localhost:3000/products
```

The frontend reads:

```dotenv
NEXT_PUBLIC_API_URL=http://localhost:8000/api
```

and refreshes the current product page every 30 seconds. Stored products are paginated server-side (9 per page in the UI) with Previous/Next and numbered page controls. The `/products` page also contains an on-demand scraper form. Choose **Single product** for a product detail URL or **Category / listing** for an Amazon/Jumia category page.

---

# API

## List products

```http
GET /api/products?page=1&per_page=9
```

Stored products are returned newest-first with Laravel server-side pagination. `page` defaults to `1`; `per_page` defaults to `9` and is capped at `48` to prevent unbounded reads.

Example:

```bash
curl --header "Accept: application/json" \
  "http://localhost:8000/api/products?page=1&per_page=9"
```

Example response:

```json
{
  "data": [
    {
      "id": 21,
      "title": "Noise-Cancelling Wireless Headphones",
      "price": "1299.00",
      "image_url": "https://images.example.com/headphones.jpg",
      "created_at": "2026-08-31T12:00:00+00:00"
    }
  ],
  "links": {
    "first": "http://localhost:8000/api/products?page=1",
    "last": "http://localhost:8000/api/products?page=3",
    "prev": null,
    "next": "http://localhost:8000/api/products?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "per_page": 9,
    "to": 9,
    "total": 21
  }
}
```

The Next.js `/products` page consumes this metadata to render Previous/Next controls, numbered pages, the total product count, and the current result range. Auto-refresh keeps the user on the currently selected page.

## Scrape and store a product

```http
POST /api/products/scrape
```

Example:

```bash
curl --request POST http://localhost:8000/api/products/scrape \
  --header "Accept: application/json" \
  --header "Content-Type: application/json" \
  --data '{"url":"https://www.amazon.com/dp/PRODUCT_ID"}'
```

Or use the Makefile:

```bash
make scrape URL=https://www.amazon.com/dp/PRODUCT_ID
```

Typical status codes:

| Status | Meaning |
| ---: | --- |
| `201` | Product scraped and stored successfully |
| `422` | Invalid or disallowed URL |
| `429` | Scrape rate limit exceeded |
| `502` | Retailer/proxy response could not produce a complete product |

> Scrape only pages you are authorized to access and respect each retailer's terms, robots policy, and rate limits. Large retailers may return CAPTCHA or other anti-bot responses. Fixture-based tests are included so parser validation does not depend on live retailer availability.

## Scrape a category/listing and store multiple products

```http
POST /api/products/scrape-listing
```

The listing endpoint supports Amazon and Jumia category/search/listing pages. It discovers product-detail links, follows a bounded number of pagination pages, and reuses the existing single-product scraper for each discovered URL.

Jumia example:

```bash
curl --request POST http://localhost:8000/api/products/scrape-listing \
  --header "Accept: application/json" \
  --header "Content-Type: application/json" \
  --data '{
    "url":"https://www.jumia.com.eg/laptops/?sort=lowest-price&price=7999-345299",
    "limit":8,
    "max_pages":1
  }'
```

Amazon example:

```bash
curl --request POST http://localhost:8000/api/products/scrape-listing \
  --header "Accept: application/json" \
  --header "Content-Type: application/json" \
  --data '{
    "url":"https://www.amazon.eg/-/en/b?ie=UTF8&node=21833002031",
    "limit":8,
    "max_pages":1
  }'
```

Makefile shortcut:

```bash
make scrape-listing \
  URL='https://www.jumia.com.eg/laptops/?sort=lowest-price&price=7999-345299' \
  LIMIT=8 \
  PAGES=1
```

Request fields:

| Field | Required | Default | Allowed | Purpose |
| --- | --- | ---: | ---: | --- |
| `url` | Yes | — | Allowed Amazon/Jumia URL | Listing/category/search page |
| `limit` | No | `8` | `1..20` | Maximum unique product pages to scrape |
| `max_pages` | No | `1` | `1..5` | Maximum listing pages to visit |

Example response summary:

```json
{
  "data": {
    "source_url": "https://www.jumia.com.eg/laptops/?sort=lowest-price&price=7999-345299",
    "pages_visited": 1,
    "discovered": 8,
    "scraped": 7,
    "created": 6,
    "existing": 1,
    "failed": 1,
    "products": [],
    "errors": []
  }
}
```

The endpoint is intentionally bounded because it performs synchronous HTTP scraping. For hundreds of products, the production path is a Laravel queue with per-retailer concurrency/rate limits rather than one long HTTP request.

Typical status codes:

| Status | Meaning |
| ---: | --- |
| `201` | At least one listing product was scraped and persisted/resolved |
| `422` | Invalid URL, disallowed retailer, or invalid `limit` / `max_pages` |
| `429` | Listing scrape rate limit exceeded |
| `502` | Listing could not be fetched, no supported product links were found, or no product page could be scraped successfully |

---

# Proxy management API

The Go service listens on port `8081` by default.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/health` | Liveness check |
| `GET` | `/v1/proxies` | List redacted proxy health snapshots |
| `GET` | `/v1/proxies/next` | Lease the next healthy proxy |
| `POST` | `/v1/proxies` | Add a proxy at runtime |
| `POST` | `/v1/proxies/{id}/report` | Report success/failure |
| `DELETE` | `/v1/proxies/{id}` | Remove a proxy |

To protect management operations, set the same bearer token for both Laravel and Go:

```dotenv
PROXY_MANAGER_TOKEN=replace-with-a-secret-token
```

Proxy credentials are redacted from inventory and add responses. The full credential-bearing proxy URL is exposed only to Laravel through the internal lease flow.

---

# Configuration

## Root Docker environment

Create:

```bash
cp .env.example .env
```

Default values:

```dotenv
MYSQL_DATABASE=product_scraper
MYSQL_USER=scraper
MYSQL_PASSWORD=scraper_secret
MYSQL_ROOT_PASSWORD=root_secret

PROXY_URLS=
PROXY_MANAGER_TOKEN=
PROXY_FAILURE_THRESHOLD=3
PROXY_COOLDOWN=60s

NEXT_PUBLIC_API_URL=http://localhost:8000/api
```

## Real proxies

Provide a comma-separated list:

```dotenv
PROXY_URLS=http://user:password@proxy-one.example:8080,http://proxy-two.example:8080
PROXY_FAILURE_THRESHOLD=3
PROXY_COOLDOWN=60s
```

## Supported retailer hosts

Laravel uses `SCRAPER_ALLOWED_HOSTS` to restrict scraping targets.

Default configuration:

```dotenv
SCRAPER_ALLOWED_HOSTS=amazon.com,amazon.co.uk,amazon.eg,jumia.com,jumia.com.eg,jumia.co.ke,jumia.com.ng
```

The product parser includes retailer-specific handling for Amazon and Jumia followed by Open Graph/product metadata fallbacks. The listing parser separately recognizes Amazon product-detail links (`/dp/...`) and Jumia product links (`*.html`) plus each retailer's pagination controls.

## Scraper controls

The backend `.env.example` includes:

```dotenv
SCRAPER_TIMEOUT_SECONDS=15
SCRAPER_CONNECT_TIMEOUT_SECONDS=5
SCRAPER_MAX_ATTEMPTS=2
SCRAPER_MAX_RESPONSE_BYTES=2097152
SCRAPER_LISTING_DEFAULT_LIMIT=8
SCRAPER_LISTING_MAX_LIMIT=20
SCRAPER_LISTING_DEFAULT_MAX_PAGES=1
SCRAPER_LISTING_MAX_PAGES=5
PROXY_MANAGER_REQUIRED=false
```

---

# Validation and tests

The repository has tests for Laravel, Next.js, and Go.

> The normal Docker runtime images are optimized for running the application. Development/test dependencies are intentionally not guaranteed inside the final runtime containers, so run the complete validation suite from a development environment or CI.

## Laravel

```bash
cd backend
composer install
composer test
vendor/bin/pint --test
```

Equivalent test command:

```bash
php artisan test
```

## Next.js

```bash
cd frontend
npm ci
npm test
npm run lint
npm run typecheck
npm run build
```

## Go

```bash
cd proxy-service
go test ./...
test -z "$(gofmt -l .)"
go vet ./...
```

## Run repository validation script

After local dependencies are installed:

```bash
./scripts/validate.sh
```

## Makefile shortcuts

```bash
make test
make lint
make scrape URL=https://www.amazon.eg/dp/PRODUCT_ID
make scrape-listing URL='https://www.jumia.com.eg/laptops/' LIMIT=8 PAGES=1
```

CI runs the Laravel, frontend, and Go validations on pull requests.

---

# Troubleshooting

## `composer: command not found`

Composer is not installed or is not on your PATH.

Either install it:

```bash
brew install composer
```

or run the project using Docker.

## `php: command not found`

PHP is not installed or is not on your PATH.

Install it on macOS with Homebrew:

```bash
brew install php
```

Then verify:

```bash
php -v
```

## Docker daemon is not running

Error:

```text
Cannot connect to the Docker daemon at unix:///.../.docker/run/docker.sock
```

On macOS:

```bash
open -a Docker
```

Wait for Docker Desktop to start, then:

```bash
docker info
```

If needed, inspect the active context:

```bash
docker context ls
```

Docker Desktop normally uses the `desktop-linux` context. If it exists but is not selected:

```bash
docker context use desktop-linux
```

## Docker build looks stuck

The first backend build can be slow while Alpine installs packages and PHP compiles extensions.

If the output is still changing, allow it to finish.

For detailed build output:

```bash
docker compose build --progress=plain backend
```

If you interrupt a build and rerun it, Docker normally reuses completed cached layers.

## Inspect container status

```bash
docker compose ps
```

## Backend is unhealthy

```bash
docker compose logs --tail=200 backend
```

Also inspect MySQL:

```bash
docker compose logs --tail=200 mysql
```

## Frontend is unhealthy

```bash
docker compose logs --tail=200 frontend
```

Confirm the backend first:

```bash
curl http://localhost:8000/up
curl http://localhost:8000/api/products
```

## Port already in use

Check ports:

```bash
lsof -i :3000
lsof -i :8000
lsof -i :8081
lsof -i :3306
```

Stop the conflicting local process or change the host-side port mapping in `docker-compose.yml`.

## Reset Docker database

To remove the current MySQL volume and recreate the database from scratch:

```bash
docker compose down -v
docker compose up --build -d
```

This permanently deletes the local Docker MySQL data for this project.

---

# Key design decisions

- The task's required product fields are preserved exactly: `id`, `title`, `price`, `image_url`, and `created_at`.
- Currency is not guessed because the requested data model does not include a currency field.
- The scrape endpoint is separate from `GET /api/products`, keeping reads safe and repeatable.
- Retailer domains are allowlisted before outbound requests.
- Redirect destinations are validated again before following them.
- Credentials and non-standard URL ports are rejected.
- Request duration, response size, redirect count, retries, and API request rate are bounded.
- Proxy rotation and user-agent rotation are separate concerns: Go owns proxy health while Laravel owns scraping behavior and parsing.
- A failed proxy cools down after a configurable number of consecutive failures.
- When no proxy is ready, the service can fall back to a direct connection for local evaluation.
- The frontend uses a small abortable `fetch` polling loop instead of adding a client-side data-fetching dependency for one resource.
- The frontend requests a server-paginated product page immediately, keeps page navigation in the UI, and refreshes the selected page every 30 seconds.

---

# Production extensions

Potential production improvements include:

- queued/asynchronous scraping;
- per-retailer concurrency and rate limits;
- persistent proxy-health state in Redis or another shared store;
- authenticated scrape requests and an authenticated proxy control plane;
- selector success-rate monitoring and alerting;
- metrics, tracing, and structured observability;
- `currency`, `source_url`, `retailer`, and `updated_at` fields;
- a canonical `source_url` uniqueness key and upserts;
- DNS/IP pinning and networking-level egress controls; and
- Playwright/browser-based scraping only for JavaScript-rendered pages that cannot be handled with normal HTTP HTML parsing.

---

# Repository structure

```text
product-scraper-trial/
├── backend/                # Laravel scraping/API service
├── frontend/               # Next.js products UI
├── proxy-service/          # Go proxy-management service
├── docs/
│   └── ARCHITECTURE.md     # Detailed architecture and trade-offs
├── scripts/
│   └── validate.sh         # Cross-service validation
├── .github/
│   └── workflows/          # CI workflow
├── .env.example            # Docker Compose environment template
├── docker-compose.yml      # Full local stack
├── Makefile                # Common development shortcuts
└── README.md
```

---

# Repository

Recommended GitHub repository name:

```text
product-scraper-trial
```

Detailed technical architecture:

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
