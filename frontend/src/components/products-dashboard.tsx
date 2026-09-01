"use client";

import { FormEvent, useCallback, useEffect, useRef, useState } from "react";
import styles from "./products-dashboard.module.css";

const REFRESH_INTERVAL_MS = 30_000;
const PRODUCTS_PER_PAGE = 9;
const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api").replace(
  /\/$/,
  "",
);

export type Product = {
  id: number;
  title: string;
  price: string;
  image_url: string;
  created_at: string | null;
};

type PaginationMeta = {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
};

type ProductsResponse = {
  data: Product[];
  meta: PaginationMeta;
};

type ListingScrapeResponse = {
  data: {
    pages_visited: number;
    discovered: number;
    scraped: number;
    created: number;
    existing: number;
    failed: number;
  };
};

type ScrapeMode = "product" | "listing";

export function ProductsDashboard() {
  const [products, setProducts] = useState<Product[]>([]);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState<PaginationMeta>({
    current_page: 1,
    from: null,
    last_page: 1,
    per_page: PRODUCTS_PER_PAGE,
    to: null,
    total: 0,
  });
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);
  const [scrapeUrl, setScrapeUrl] = useState("");
  const [scrapeMode, setScrapeMode] = useState<ScrapeMode>("product");
  const [listingLimit, setListingLimit] = useState(8);
  const [listingPages, setListingPages] = useState(1);
  const [scraping, setScraping] = useState(false);
  const [scrapeMessage, setScrapeMessage] = useState<string | null>(null);
  const [scrapeError, setScrapeError] = useState<string | null>(null);
  const controllers = useRef(new Set<AbortController>());

  const loadProducts = useCallback(async (background = false, targetPage = page) => {
    const controller = new AbortController();
    controllers.current.add(controller);

    if (background) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }

    try {
      const params = new URLSearchParams({
        page: String(targetPage),
        per_page: String(PRODUCTS_PER_PAGE),
      });
      const response = await fetch(`${API_URL}/products?${params.toString()}`, {
        cache: "no-store",
        headers: { Accept: "application/json" },
        signal: controller.signal,
      });

      if (!response.ok) {
        throw new Error(`Product API returned ${response.status}`);
      }

      const payload = (await response.json()) as ProductsResponse;
      if (!Array.isArray(payload.data) || !payload.meta) {
        throw new Error("Product API returned an invalid response");
      }

      setProducts(payload.data);
      setPagination(payload.meta);
      setError(null);
      setLastUpdated(new Date());

      if (payload.meta.current_page !== page) {
        setPage(payload.meta.current_page);
      }
    } catch (requestError) {
      if (requestError instanceof DOMException && requestError.name === "AbortError") {
        return;
      }
      setError("We could not reach the product service. Please try again.");
    } finally {
      controllers.current.delete(controller);
      setLoading(false);
      setRefreshing(false);
    }
  }, [page]);

  const submitScrape = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      const url = scrapeUrl.trim();

      if (!url) {
        setScrapeError("Paste an Amazon or Jumia URL first.");
        return;
      }

      setScraping(true);
      setScrapeError(null);
      setScrapeMessage(null);

      const endpoint = scrapeMode === "listing" ? "products/scrape-listing" : "products/scrape";
      const body =
        scrapeMode === "listing"
          ? { url, limit: listingLimit, max_pages: listingPages }
          : { url };

      try {
        const response = await fetch(`${API_URL}/${endpoint}`, {
          method: "POST",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
          },
          body: JSON.stringify(body),
        });

        const payload = (await response.json()) as
          | ListingScrapeResponse
          | { message?: string; data?: Product };

        if (!response.ok) {
          const message = "message" in payload && payload.message ? payload.message : `Scrape API returned ${response.status}`;
          throw new Error(message);
        }

        if (scrapeMode === "listing") {
          const listing = payload as ListingScrapeResponse;
          setScrapeMessage(
            `Listing complete: ${listing.data.scraped} scraped, ${listing.data.created} new, ${listing.data.existing} already stored, ${listing.data.failed} failed across ${listing.data.pages_visited} page${listing.data.pages_visited === 1 ? "" : "s"}.`,
          );
        } else {
          setScrapeMessage("Product scraped and stored successfully.");
        }

        if (page === 1) {
          await loadProducts(true, 1);
        } else {
          setPage(1);
        }
      } catch (requestError) {
        setScrapeError(
          requestError instanceof Error
            ? requestError.message
            : "The scrape request could not be completed.",
        );
      } finally {
        setScraping(false);
      }
    },
    [listingLimit, listingPages, loadProducts, page, scrapeMode, scrapeUrl],
  );

  useEffect(() => {
    const activeControllers = controllers.current;
    const initialLoad = window.setTimeout(() => void loadProducts(), 0);
    const timer = window.setInterval(() => void loadProducts(true), REFRESH_INTERVAL_MS);

    return () => {
      window.clearTimeout(initialLoad);
      window.clearInterval(timer);
      activeControllers.forEach((controller) => controller.abort());
      activeControllers.clear();
    };
  }, [loadProducts]);

  return (
    <main className={styles.shell}>
      <header className={styles.topbar}>
        <a className={styles.brand} href="/products" aria-label="Catalog Monitor home">
          <span className={styles.brandMark} aria-hidden="true">
            CM
          </span>
          <span>Catalog Monitor</span>
        </a>
        <span className={styles.pollingBadge}>
          <span className={styles.liveDot} aria-hidden="true" />
          Auto-refreshes every 30s
        </span>
      </header>

      <section className={styles.intro} aria-labelledby="products-heading">
        <div>
          <p className={styles.eyebrow}>Live inventory</p>
          <h1 id="products-heading">Recently scraped products</h1>
          <p className={styles.lede}>
            Product details collected by Laravel, routed through the Go proxy manager,
            and delivered here through a clean JSON API.
          </p>
        </div>
        <button
          className={styles.refreshButton}
          type="button"
          onClick={() => void loadProducts(true, page)}
          disabled={loading || refreshing}
        >
          <RefreshIcon />
          {refreshing ? "Refreshing…" : "Refresh now"}
        </button>
      </section>

      <section className={styles.scrapePanel} aria-labelledby="scrape-heading">
        <div className={styles.scrapeHeader}>
          <div>
            <p className={styles.eyebrow}>Scrape on demand</p>
            <h2 id="scrape-heading">Add products from Amazon or Jumia</h2>
            <p>
              Paste one product page, or switch to category/listing mode to discover and scrape
              multiple product pages automatically.
            </p>
          </div>
          <div className={styles.modeSwitch} aria-label="Scrape type">
            <button
              type="button"
              aria-pressed={scrapeMode === "product"}
              onClick={() => setScrapeMode("product")}
            >
              Single product
            </button>
            <button
              type="button"
              aria-pressed={scrapeMode === "listing"}
              onClick={() => setScrapeMode("listing")}
            >
              Category / listing
            </button>
          </div>
        </div>

        <form className={styles.scrapeForm} onSubmit={submitScrape}>
          <label className={styles.urlField} htmlFor="scrape-url">
            <span>Retailer URL</span>
            <input
              id="scrape-url"
              type="url"
              value={scrapeUrl}
              onChange={(event) => setScrapeUrl(event.target.value)}
              placeholder={
                scrapeMode === "listing"
                  ? "https://www.jumia.com.eg/laptops/?sort=lowest-price"
                  : "https://www.amazon.eg/dp/PRODUCT_ID"
              }
              required
            />
          </label>

          {scrapeMode === "listing" ? (
            <div className={styles.listingOptions}>
              <label htmlFor="listing-limit">
                <span>Product limit</span>
                <input
                  id="listing-limit"
                  type="number"
                  min={1}
                  max={20}
                  value={listingLimit}
                  onChange={(event) => setListingLimit(Number(event.target.value))}
                />
              </label>
              <label htmlFor="listing-pages">
                <span>Max pages</span>
                <input
                  id="listing-pages"
                  type="number"
                  min={1}
                  max={5}
                  value={listingPages}
                  onChange={(event) => setListingPages(Number(event.target.value))}
                />
              </label>
            </div>
          ) : null}

          <button className={styles.scrapeButton} type="submit" disabled={scraping}>
            {scraping
              ? "Scraping…"
              : scrapeMode === "listing"
                ? "Scrape listing"
                : "Scrape product"}
          </button>
        </form>

        {scrapeMessage ? (
          <p className={styles.scrapeSuccess} role="status">
            {scrapeMessage}
          </p>
        ) : null}
        {scrapeError ? (
          <p className={styles.scrapeFailure} role="alert">
            {scrapeError}
          </p>
        ) : null}
      </section>

      <div className={styles.statusRow} aria-live="polite">
        <strong>{pagination.total}</strong> {pagination.total === 1 ? "product" : "products"}
        <span aria-hidden="true">•</span>
        <span>
          {pagination.total > 0
            ? `Showing ${pagination.from ?? 0}–${pagination.to ?? 0}`
            : "No stored products"}
        </span>
        <span aria-hidden="true">•</span>
        <span>{lastUpdated ? `Updated ${formatTime(lastUpdated)}` : "Waiting for first update"}</span>
      </div>

      {error ? (
        <section className={styles.errorState} role="alert">
          <div>
            <strong>Connection interrupted</strong>
            <p>{error}</p>
          </div>
          <button type="button" onClick={() => void loadProducts()}>
            Try again
          </button>
        </section>
      ) : null}

      {loading ? <LoadingGrid /> : null}

      {!loading && !error && products.length === 0 ? (
        <section className={styles.emptyState}>
          <span aria-hidden="true">◎</span>
          <h2>No products yet</h2>
          <p>Paste a product or listing URL above and the stored results will appear here.</p>
        </section>
      ) : null}

      {!loading && products.length > 0 ? (
        <>
          <section className={styles.grid} aria-label="Product results">
            {products.map((product) => (
              <ProductCard key={product.id} product={product} />
            ))}
          </section>
          <ProductPagination
            currentPage={pagination.current_page}
            lastPage={pagination.last_page}
            disabled={loading || refreshing}
            onPageChange={setPage}
          />
        </>
      ) : null}

      <footer className={styles.footer}>
        <span>Laravel API</span>
        <span aria-hidden="true">→</span>
        <span>Go proxy manager</span>
        <span aria-hidden="true">→</span>
        <span>Next.js UI</span>
      </footer>
    </main>
  );
}

function ProductPagination({
  currentPage,
  lastPage,
  disabled,
  onPageChange,
}: {
  currentPage: number;
  lastPage: number;
  disabled: boolean;
  onPageChange: (page: number) => void;
}) {
  if (lastPage <= 1) {
    return null;
  }

  const pages = paginationWindow(currentPage, lastPage);

  return (
    <nav className={styles.pagination} aria-label="Product pagination">
      <button
        type="button"
        onClick={() => onPageChange(currentPage - 1)}
        disabled={disabled || currentPage <= 1}
      >
        Previous
      </button>

      <div className={styles.pageNumbers}>
        {pages.map((pageNumber) => (
          <button
            key={pageNumber}
            type="button"
            className={pageNumber === currentPage ? styles.activePage : undefined}
            aria-current={pageNumber === currentPage ? "page" : undefined}
            aria-label={`Page ${pageNumber}`}
            onClick={() => onPageChange(pageNumber)}
            disabled={disabled}
          >
            {pageNumber}
          </button>
        ))}
      </div>

      <span className={styles.pageSummary}>
        Page {currentPage} of {lastPage}
      </span>

      <button
        type="button"
        onClick={() => onPageChange(currentPage + 1)}
        disabled={disabled || currentPage >= lastPage}
      >
        Next
      </button>
    </nav>
  );
}

function paginationWindow(currentPage: number, lastPage: number): number[] {
  const windowSize = 5;
  let start = Math.max(1, currentPage - Math.floor(windowSize / 2));
  const end = Math.min(lastPage, start + windowSize - 1);
  start = Math.max(1, end - windowSize + 1);

  return Array.from({ length: end - start + 1 }, (_, index) => start + index);
}

function ProductCard({ product }: { product: Product }) {
  const [imageFailed, setImageFailed] = useState(false);

  return (
    <article className={styles.card}>
      <div className={styles.imageFrame}>
        {imageFailed ? (
          <div className={styles.imageFallback} role="img" aria-label="Product image unavailable">
            Image unavailable
          </div>
        ) : (
          // Retailer image hosts are dynamic, so a native image avoids an unsafe wildcard
          // in Next Image's remote host allowlist.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={product.image_url}
            alt=""
            loading="lazy"
            onError={() => setImageFailed(true)}
          />
        )}
        <span className={styles.idBadge}>#{product.id}</span>
      </div>
      <div className={styles.cardBody}>
        <h2>{product.title}</h2>
        <div className={styles.cardMeta}>
          <div>
            <span>Price</span>
            <strong>{formatPrice(product.price)}</strong>
          </div>
          <time dateTime={product.created_at ?? undefined}>
            {product.created_at ? formatDate(product.created_at) : "Just added"}
          </time>
        </div>
      </div>
    </article>
  );
}

function LoadingGrid() {
  return (
    <section className={styles.grid} aria-label="Loading products" aria-busy="true">
      {[0, 1, 2].map((item) => (
        <div className={styles.skeleton} key={item} aria-hidden="true">
          <div />
          <span />
          <span />
        </div>
      ))}
    </section>
  );
}

function RefreshIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18">
      <path
        d="M20 6v5h-5M4 18v-5h5m9.3-3a7 7 0 0 0-11.4-2.4L4 11m16 2-2.9 3.4A7 7 0 0 1 5.7 14"
        fill="none"
        stroke="currentColor"
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeWidth="2"
      />
    </svg>
  );
}

function formatPrice(value: string): string {
  const number = Number(value);
  return Number.isFinite(number)
    ? new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
        number,
      )
    : value;
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat("en", { month: "short", day: "numeric" }).format(new Date(value));
}

function formatTime(value: Date): string {
  return new Intl.DateTimeFormat("en", { hour: "numeric", minute: "2-digit" }).format(value);
}
