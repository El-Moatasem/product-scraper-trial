#!/usr/bin/env sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

cd "$project_dir/backend"
composer test
vendor/bin/pint --test

cd "$project_dir/frontend"
npm test
npm run lint
npm run typecheck
npm run build

cd "$project_dir/proxy-service"
test -z "$(gofmt -l .)"
go test ./...
go vet ./...
