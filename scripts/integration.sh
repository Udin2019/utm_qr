#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
mkdir -p .runtime/acceptance
docker compose cp tests/acceptance.php wordpress:/tmp/cargo-acceptance.php
docker compose exec -T wordpress wp --allow-root --path=/var/www/html eval 'require "/tmp/cargo-acceptance.php";'
docker compose cp go/Tracker.php wordpress:/tmp/cargo-tracking-Tracker.php
docker compose cp tests/tracking_db.php wordpress:/tmp/cargo-tracking-db.php
docker compose exec -T wordpress wp --allow-root --path=/var/www/html eval 'require "/tmp/cargo-tracking-db.php";'
docker compose cp wordpress:/tmp/cargo-acceptance/. .runtime/acceptance/
docker build -t cargo-utm-qr-qa -f tests/qa.Dockerfile .
docker run --rm -v "$PWD/.runtime/acceptance:/artifacts" cargo-utm-qr-qa
