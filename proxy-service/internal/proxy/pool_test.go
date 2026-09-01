package proxy

import (
	"testing"
	"time"
)

func TestPoolRotatesAndCoolsDownFailingProxies(t *testing.T) {
	pool, err := New([]string{"http://user:secret@proxy-a:8080", "http://proxy-b:8080"}, 2, time.Minute)
	if err != nil {
		t.Fatal(err)
	}

	first := pool.Next()
	second := pool.Next()
	third := pool.Next()
	if first.ID == second.ID || first.ID != third.ID {
		t.Fatalf("expected round-robin leases, got %q, %q, %q", first.ID, second.ID, third.ID)
	}

	if err := pool.Report(first.ID, false); err != nil {
		t.Fatal(err)
	}
	if err := pool.Report(first.ID, false); err != nil {
		t.Fatal(err)
	}

	for range 3 {
		lease := pool.Next()
		if lease.ID == first.ID {
			t.Fatal("expected failed proxy to be skipped during cooldown")
		}
	}

	snapshots := pool.Snapshots()
	for _, snapshot := range snapshots {
		if snapshot.ID == first.ID {
			if snapshot.State != "cooldown" {
				t.Fatalf("expected cooldown state, got %q", snapshot.State)
			}
			if snapshot.URL != "http://proxy-a:8080" {
				t.Fatalf("expected credentials to be redacted, got %q", snapshot.URL)
			}
		}
	}
}

func TestEmptyPoolFallsBackToDirectConnection(t *testing.T) {
	pool, err := New(nil, 3, time.Minute)
	if err != nil {
		t.Fatal(err)
	}

	if lease := pool.Next(); !lease.Direct || lease.URL != "" {
		t.Fatalf("expected direct lease, got %#v", lease)
	}
}

func TestPoolAddsOnlyUniqueValidProxies(t *testing.T) {
	pool, err := New(nil, 3, time.Minute)
	if err != nil {
		t.Fatal(err)
	}

	first, created, err := pool.Add("HTTPS://Proxy.Example:8443")
	if err != nil || !created {
		t.Fatalf("expected proxy to be created: %v", err)
	}
	duplicate, created, err := pool.Add("https://proxy.example:8443")
	if err != nil || created || duplicate.ID != first.ID {
		t.Fatalf("expected duplicate proxy to be reused: %v", err)
	}
	if _, _, err := pool.Add("file:///tmp/proxy"); err == nil {
		t.Fatal("expected unsupported proxy URL to fail")
	}
}
