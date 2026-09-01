import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ProductsDashboard } from "@/components/products-dashboard";

const productResponse = {
  data: [
    {
      id: 7,
      title: "Noise-Cancelling Wireless Headphones",
      price: "1299.00",
      image_url: "https://images.example.com/headphones.jpg",
      created_at: "2026-08-31T12:00:00Z",
    },
  ],
  meta: {
    current_page: 1,
    from: 1,
    last_page: 1,
    per_page: 9,
    to: 1,
    total: 1,
  },
};

const paginatedResponse = {
  ...productResponse,
  meta: {
    ...productResponse.meta,
    last_page: 3,
    total: 21,
  },
};

describe("ProductsDashboard", () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    fetchMock.mockReset();
    fetchMock.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => productResponse,
    });
    vi.stubGlobal("fetch", fetchMock);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("renders products returned by Laravel", async () => {
    render(<ProductsDashboard />);

    expect(await screen.findByText("Noise-Cancelling Wireless Headphones")).toBeTruthy();
    expect(screen.getByText("1,299.00")).toBeTruthy();
    expect(fetchMock).toHaveBeenCalledWith(
      "http://localhost:8000/api/products?page=1&per_page=9",
      expect.objectContaining({ cache: "no-store" }),
    );
  });

  it("refreshes the current product page every 30 seconds", async () => {
    vi.useFakeTimers();
    render(<ProductsDashboard />);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(30_000);
    });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenLastCalledWith(
      "http://localhost:8000/api/products?page=1&per_page=9",
      expect.objectContaining({ cache: "no-store" }),
    );
  });

  it("navigates between server-side product pages", async () => {
    fetchMock.mockImplementation(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes("page=2")) {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: [
              {
                id: 6,
                title: "Second page product",
                price: "799.00",
                image_url: "https://images.example.com/page-2.jpg",
                created_at: "2026-08-31T11:00:00Z",
              },
            ],
            meta: {
              current_page: 2,
              from: 10,
              last_page: 3,
              per_page: 9,
              to: 18,
              total: 21,
            },
          }),
        } as Response;
      }

      return {
        ok: true,
        status: 200,
        json: async () => paginatedResponse,
      } as Response;
    });

    render(<ProductsDashboard />);
    await screen.findByText("Noise-Cancelling Wireless Headphones");

    fireEvent.click(screen.getByRole("button", { name: "Page 2" }));

    expect(await screen.findByText("Second page product")).toBeTruthy();
    expect(fetchMock).toHaveBeenCalledWith(
      "http://localhost:8000/api/products?page=2&per_page=9",
      expect.objectContaining({ cache: "no-store" }),
    );
    expect(screen.getByText("Page 2 of 3")).toBeTruthy();
    expect(screen.getByText("Showing 10–18")).toBeTruthy();
  });

  it("shows a recoverable error state when the API is unavailable", async () => {
    fetchMock.mockRejectedValueOnce(new Error("offline"));
    render(<ProductsDashboard />);

    await waitFor(() => expect(screen.getByRole("alert")).toBeTruthy());
    expect(screen.getByText("Connection interrupted")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Try again" })).toBeTruthy();
  });

  it("can trigger a category listing scrape from the UI", async () => {
    fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input).endsWith("/products/scrape-listing") && init?.method === "POST") {
        return {
          ok: true,
          status: 201,
          json: async () => ({
            data: {
              pages_visited: 1,
              discovered: 3,
              scraped: 3,
              created: 2,
              existing: 1,
              failed: 0,
            },
          }),
        } as Response;
      }

      return {
        ok: true,
        status: 200,
        json: async () => productResponse,
      } as Response;
    });

    render(<ProductsDashboard />);
    await screen.findByText("Noise-Cancelling Wireless Headphones");

    fireEvent.click(screen.getByRole("button", { name: "Category / listing" }));
    fireEvent.change(screen.getByLabelText("Retailer URL"), {
      target: { value: "https://www.jumia.com.eg/laptops/?sort=lowest-price" },
    });
    fireEvent.change(screen.getByLabelText("Product limit"), { target: { value: "3" } });
    fireEvent.click(screen.getByRole("button", { name: "Scrape listing" }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        "http://localhost:8000/api/products/scrape-listing",
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({
            url: "https://www.jumia.com.eg/laptops/?sort=lowest-price",
            limit: 3,
            max_pages: 1,
          }),
        }),
      );
    });

    expect(await screen.findByText(/Listing complete: 3 scraped/)).toBeTruthy();
  });
});
