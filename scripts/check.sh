#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
find plugin go scripts tests -type d -name vendor -prune -o -name '*.php' -exec php -l {} \;
php tests/domain.php
php tests/workspace_access.php
php tests/workspace_urls.php
php tests/site_policy.php
php tests/public_generator.php
php tests/partner_site.php
php tests/qr_center.php
node --check plugin/cargo-utm/assets/admin.js
node --check plugin/cargo-utm/assets/ui/app.js
node tests/draft.js
node tests/online_preset.js
node tests/short_links.js
python3 -m py_compile tests/qr_decode.py tests/http.py tests/ui_contract.py scripts/install-public-https.py tests/public_https.py
python3 tests/ui_contract.py
python3 tests/public_https.py
python3 -c 'import json; json.load(open("plugin/cargo-utm/composer.json")); print("Composer JSON: PASS")'
# No daemon required. Placeholder values validate configuration only.
CARGO_DB_PASSWORD=validation-only CARGO_DB_ROOT_PASSWORD=validation-only CARGO_GO_DB_PASSWORD=validation-only CARGO_WP_ADMIN_PASSWORD=validation-only docker compose config --quiet
printf 'Compose config: PASS\n'
