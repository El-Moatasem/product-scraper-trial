"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import styles from "./products-dashboard.module.css";

const REFRESH_INTERVAL_MS = 30_000;
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

type ProductsResponse = {
  data: Product[];
};

export function ProductsDashboard() {
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);
  const controllers = useRef(new Set<AbortController>());

  const loadProducts = useCallback(async (background = false) => {
    const controller = new AbortController();
    controllers.current.add(controller);

    if (background) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }

    try {
      const response = await fetch(`${API_URL}/products`, {
        cache: "no-store",
        headers: { Accept: "application/json" },
        signal: controller.signal,
      });

      if (!response.ok) {
        throw new Error(`Product API returned ${response.status}`);
      }

      const payload = (await response.json()) as ProductsResponse;
      if (!Array.isArray(payload.data)) {
        throw new Error("Product API returned an invalid response");
      }

      setProducts(payload.data);
      setError(null);
      setLastUpdated(new Date());
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
  }, []);

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
          onClick={() => void loadProducts(true)}
          disabled={loading || refreshing}
        >
          <RefreshIcon />
          {refreshing ? "Refreshing…" : "Refresh now"}
        </button>
      </section>

      <div className={styles.statusRow} aria-live="polite">
        <strong>{products.length}</strong> {products.length === 1 ? "product" : "products"}
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
          <p>Run a scrape request against the Laravel API, and the result will appear here.</p>
        </section>
      ) : null}

      {!loading && products.length > 0 ? (
        <section className={styles.grid} aria-label="Product results">
          {products.map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </section>
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
