#!/usr/bin/env bash
# The store tests (migration, record, scheduled rows, drain, a missing table in
# an outer transaction) against PostgreSQL 16, in unprivileged containers.
# Usage: bin/pg-smoke.sh   (from the package directory; needs docker)
set -euo pipefail
cd "$(dirname "$0")/.."
NET=trident-pg-smoke-$$
docker network create "$NET" >/dev/null
trap 'docker rm -f "$NET-db" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1' EXIT
docker run -d --name "$NET-db" --network "$NET" -e POSTGRES_PASSWORD=smoke -e POSTGRES_DB=smoke postgres:16-alpine >/dev/null
for _ in $(seq 1 30); do docker exec "$NET-db" pg_isready -U postgres >/dev/null 2>&1 && break; sleep 1; done
ROOT="$(cd ../../.. && pwd)"
docker run --rm --network "$NET" -e TRIDENT_TEST_DSN="pgsql://postgres:smoke@$NET-db:5432/smoke" \
  -v "$ROOT:/app/integrations" -w /app/integrations/frameworks/symfony/trident-symfony php:8.3-cli sh -c '
    apt-get update -qq >/dev/null && apt-get install -y -qq libpq-dev >/dev/null && docker-php-ext-install pdo_pgsql >/dev/null 2>&1 &&
    php vendor/bin/phpunit --filter StoresTest'
