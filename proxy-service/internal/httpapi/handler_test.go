package httpapi

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/el-moatasem/product-scraper-trial/proxy-service/internal/proxy"
)

func TestProxyLifecycle(t *testing.T) {
	pool, err := proxy.New(nil, 2, time.Minute)
	if err != nil {
		t.Fatal(err)
	}
	handler := New(pool, "test-token")

	unauthorized := httptest.NewRequest(http.MethodGet, "/v1/proxies/next", nil)
	unauthorizedResult := httptest.NewRecorder()
	handler.ServeHTTP(unauthorizedResult, unauthorized)
	if unauthorizedResult.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401, got %d", unauthorizedResult.Code)
	}

	add := authorizedRequest(http.MethodPost, "/v1/proxies", []byte(`{"url":"http://user:pass@proxy.example:8080"}`))
	addResult := httptest.NewRecorder()
	handler.ServeHTTP(addResult, add)
	if addResult.Code != http.StatusCreated {
		t.Fatalf("expected 201, got %d: %s", addResult.Code, addResult.Body.String())
	}
	if bytes.Contains(addResult.Body.Bytes(), []byte("pass")) {
		t.Fatal("expected credentials to be redacted from management response")
	}

	next := authorizedRequest(http.MethodGet, "/v1/proxies/next", nil)
	nextResult := httptest.NewRecorder()
	handler.ServeHTTP(nextResult, next)
	if nextResult.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", nextResult.Code)
	}
	var lease proxy.Lease
	if err := json.NewDecoder(nextResult.Body).Decode(&lease); err != nil {
		t.Fatal(err)
	}
	if lease.Direct || lease.URL != "http://user:pass@proxy.example:8080" {
		t.Fatalf("unexpected lease: %#v", lease)
	}

	report := authorizedRequest(http.MethodPost, "/v1/proxies/"+lease.ID+"/report", []byte(`{"success":true}`))
	reportResult := httptest.NewRecorder()
	handler.ServeHTTP(reportResult, report)
	if reportResult.Code != http.StatusNoContent {
		t.Fatalf("expected 204, got %d", reportResult.Code)
	}
}

func authorizedRequest(method, target string, body []byte) *http.Request {
	request := httptest.NewRequest(method, target, bytes.NewReader(body))
	request.Header.Set("Authorization", "Bearer test-token")
	request.Header.Set("Content-Type", "application/json")
	return request
}
