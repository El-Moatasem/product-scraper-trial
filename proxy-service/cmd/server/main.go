package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/el-moatasem/product-scraper-trial/proxy-service/internal/httpapi"
	"github.com/el-moatasem/product-scraper-trial/proxy-service/internal/proxy"
)

func main() {
	if len(os.Args) > 1 && os.Args[1] == "--healthcheck" {
		runHealthcheck()
		return
	}

	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	pool, err := proxy.New(
		proxyURLs(os.Getenv("PROXY_URLS")),
		envInt("PROXY_FAILURE_THRESHOLD", 3),
		envDuration("PROXY_COOLDOWN", 60*time.Second),
	)
	if err != nil {
		logger.Error("invalid proxy configuration", "error", err)
		os.Exit(1)
	}

	server := &http.Server{
		Addr:              envString("HTTP_ADDR", ":8081"),
		Handler:           httpapi.New(pool, os.Getenv("PROXY_MANAGER_TOKEN")),
		ReadHeaderTimeout: 3 * time.Second,
		ReadTimeout:       5 * time.Second,
		WriteTimeout:      5 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	go func() {
		logger.Info("proxy manager listening", "address", server.Addr, "configured_proxies", len(pool.Snapshots()))
		if listenErr := server.ListenAndServe(); listenErr != nil && !errors.Is(listenErr, http.ErrServerClosed) {
			logger.Error("proxy manager stopped unexpectedly", "error", listenErr)
			os.Exit(1)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := server.Shutdown(ctx); err != nil {
		logger.Error("graceful shutdown failed", "error", err)
		os.Exit(1)
	}
	logger.Info("proxy manager stopped")
}

func runHealthcheck() {
	client := &http.Client{Timeout: 2 * time.Second}
	response, err := client.Get("http://127.0.0.1:8081/health")
	if err != nil || response.StatusCode != http.StatusOK {
		os.Exit(1)
	}
	_ = response.Body.Close()
}

func proxyURLs(value string) []string {
	return strings.FieldsFunc(value, func(character rune) bool {
		return character == ',' || character == ';' || character == '\n'
	})
}

func envString(key, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(key)); value != "" {
		return value
	}
	return fallback
}

func envInt(key string, fallback int) int {
	value, err := strconv.Atoi(strings.TrimSpace(os.Getenv(key)))
	if err != nil || value < 1 {
		return fallback
	}
	return value
}

func envDuration(key string, fallback time.Duration) time.Duration {
	value, err := time.ParseDuration(strings.TrimSpace(os.Getenv(key)))
	if err != nil || value <= 0 {
		return fallback
	}
	return value
}
