.PHONY: up down logs test test-backend test-frontend test-proxy lint scrape scrape-listing

LIMIT ?= 8
PAGES ?= 1

up:
	docker compose up --build

down:
	docker compose down

logs:
	docker compose logs --follow

test: test-backend test-frontend test-proxy

test-backend:
	cd backend && composer test

test-frontend:
	cd frontend && npm test

test-proxy:
	cd proxy-service && go test ./...

lint:
	cd backend && vendor/bin/pint --test
	cd frontend && npm run lint && npm run typecheck
	cd proxy-service && test -z "$$(gofmt -l .)" && go vet ./...

scrape:
	curl --fail-with-body --request POST http://localhost:8000/api/products/scrape \
		--header "Accept: application/json" \
		--header "Content-Type: application/json" \
		--data '{"url":"$(URL)"}'

scrape-listing:
	curl --fail-with-body --request POST http://localhost:8000/api/products/scrape-listing \
		--header "Accept: application/json" \
		--header "Content-Type: application/json" \
		--data '{"url":"$(URL)","limit":$(LIMIT),"max_pages":$(PAGES)}'
