package proxy

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/url"
	"sort"
	"strings"
	"sync"
	"time"
)

var ErrNotFound = errors.New("proxy not found")

type Lease struct {
	ID     string `json:"id"`
	URL    string `json:"url"`
	Direct bool   `json:"direct"`
}

type Snapshot struct {
	ID            string     `json:"id"`
	URL           string     `json:"url"`
	Failures      int        `json:"failures"`
	Leases        uint64     `json:"leases"`
	CooldownUntil *time.Time `json:"cooldown_until,omitempty"`
	State         string     `json:"state"`
}

type entry struct {
	id            string
	url           string
	failures      int
	leases        uint64
	cooldownUntil time.Time
}

type Pool struct {
	mu               sync.Mutex
	entries          []*entry
	cursor           int
	failureThreshold int
	cooldown         time.Duration
	now              func() time.Time
}

func New(urls []string, failureThreshold int, cooldown time.Duration) (*Pool, error) {
	if failureThreshold < 1 {
		return nil, errors.New("failure threshold must be at least one")
	}
	if cooldown <= 0 {
		return nil, errors.New("cooldown must be positive")
	}

	pool := &Pool{
		failureThreshold: failureThreshold,
		cooldown:         cooldown,
		now:              time.Now,
	}

	for _, rawURL := range urls {
		if strings.TrimSpace(rawURL) == "" {
			continue
		}
		if _, _, err := pool.Add(rawURL); err != nil {
			return nil, err
		}
	}

	return pool, nil
}

func (p *Pool) Add(rawURL string) (Lease, bool, error) {
	normalized, err := normalizeURL(rawURL)
	if err != nil {
		return Lease{}, false, err
	}

	p.mu.Lock()
	defer p.mu.Unlock()

	for _, current := range p.entries {
		if current.url == normalized {
			return Lease{ID: current.id, URL: current.url}, false, nil
		}
	}

	item := &entry{id: proxyID(normalized), url: normalized}
	p.entries = append(p.entries, item)

	return Lease{ID: item.id, URL: item.url}, true, nil
}

func (p *Pool) Remove(id string) error {
	p.mu.Lock()
	defer p.mu.Unlock()

	for index, item := range p.entries {
		if item.id != id {
			continue
		}

		p.entries = append(p.entries[:index], p.entries[index+1:]...)
		if len(p.entries) == 0 {
			p.cursor = 0
		} else {
			p.cursor %= len(p.entries)
		}
		return nil
	}

	return ErrNotFound
}

func (p *Pool) Next() Lease {
	p.mu.Lock()
	defer p.mu.Unlock()

	if len(p.entries) == 0 {
		return Lease{Direct: true}
	}

	now := p.now()
	for checked := 0; checked < len(p.entries); checked++ {
		index := (p.cursor + checked) % len(p.entries)
		item := p.entries[index]
		if item.cooldownUntil.After(now) {
			continue
		}

		item.leases++
		p.cursor = (index + 1) % len(p.entries)
		return Lease{ID: item.id, URL: item.url}
	}

	return Lease{Direct: true}
}

func (p *Pool) Report(id string, success bool) error {
	p.mu.Lock()
	defer p.mu.Unlock()

	for _, item := range p.entries {
		if item.id != id {
			continue
		}

		if success {
			item.failures = 0
			item.cooldownUntil = time.Time{}
			return nil
		}

		item.failures++
		if item.failures >= p.failureThreshold {
			item.failures = 0
			item.cooldownUntil = p.now().Add(p.cooldown)
		}
		return nil
	}

	return ErrNotFound
}

func (p *Pool) Snapshots() []Snapshot {
	p.mu.Lock()
	defer p.mu.Unlock()

	now := p.now()
	snapshots := make([]Snapshot, 0, len(p.entries))
	for _, item := range p.entries {
		state := "ready"
		var cooldownUntil *time.Time
		if item.cooldownUntil.After(now) {
			state = "cooldown"
			value := item.cooldownUntil
			cooldownUntil = &value
		}

		snapshots = append(snapshots, Snapshot{
			ID:            item.id,
			URL:           redactURL(item.url),
			Failures:      item.failures,
			Leases:        item.leases,
			CooldownUntil: cooldownUntil,
			State:         state,
		})
	}

	sort.Slice(snapshots, func(i, j int) bool { return snapshots[i].ID < snapshots[j].ID })
	return snapshots
}

func normalizeURL(rawURL string) (string, error) {
	parsed, err := url.Parse(strings.TrimSpace(rawURL))
	if err != nil || parsed.Host == "" {
		return "", errors.New("proxy URL must include a valid host")
	}

	switch strings.ToLower(parsed.Scheme) {
	case "http", "https", "socks5", "socks5h":
	default:
		return "", errors.New("proxy URL must use http, https, socks5, or socks5h")
	}

	if parsed.Path != "" && parsed.Path != "/" || parsed.RawQuery != "" || parsed.Fragment != "" {
		return "", errors.New("proxy URL cannot include a path, query, or fragment")
	}

	parsed.Scheme = strings.ToLower(parsed.Scheme)
	parsed.Host = strings.ToLower(parsed.Host)
	parsed.Path = ""

	return parsed.String(), nil
}

func proxyID(rawURL string) string {
	digest := sha256.Sum256([]byte(rawURL))
	return hex.EncodeToString(digest[:])[:12]
}

func redactURL(rawURL string) string {
	parsed, err := url.Parse(rawURL)
	if err != nil {
		return "redacted"
	}
	parsed.User = nil
	return parsed.String()
}
