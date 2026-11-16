#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
# Run on a workstation/server with Docker and network; touches only this project.
docker build -t cargo-utm-composer -f infra/php/dependencies.Dockerfile .
composer_run() {
    docker run --rm --user "$(id -u):$(id -g)" \
        -e COMPOSER_HOME=/tmp/composer -e COMPOSER_ROOT_VERSION=0.2.0 \
        -v "$PWD/plugin/cargo-utm:/app" cargo-utm-composer "$@"
}
if [ -f plugin/cargo-utm/composer.lock ]; then
    # Refresh metadata after compatible manifest changes without upgrading packages.
    composer_run update --lock --no-dev --prefer-dist --no-interaction
else
    composer_run update --no-dev --prefer-dist --no-interaction
fi
composer_run validate --strict
composer_run check-platform-reqs --no-dev
composer_run audit
