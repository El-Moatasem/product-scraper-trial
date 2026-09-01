import { act, render, screen, waitFor } from "@testing-library/react";
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
      "http://localhost:8000/api/products",
      expect.objectContaining({ cache: "no-store" }),
    );
  });

  it("refreshes the product feed every 30 seconds", async () => {
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
  });

  it("shows a recoverable error state when the API is unavailable", async () => {
    fetchMock.mockRejectedValueOnce(new Error("offline"));
    render(<ProductsDashboard />);

    await waitFor(() => expect(screen.getByRole("alert")).toBeTruthy());
    expect(screen.getByText("Connection interrupted")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Try again" })).toBeTruthy();
  });
});
