#!/bin/sh
set -eu
# Update only the plugin in the persistent WordPress volume; preserve WP data.
mkdir -p /var/www/html/wp-content/plugins/cargo-utm
printf '%s\n' 'copying' > /var/www/html/wp-content/plugins/cargo-utm/.cargo-ready
cp -a /opt/cargo/plugin/. /var/www/html/wp-content/plugins/cargo-utm/
printf '%s\n' '0.2.0' > /var/www/html/wp-content/plugins/cargo-utm/.cargo-ready
exec docker-entrypoint.sh "$@"
