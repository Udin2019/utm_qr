#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
umask 077
release_dir=".runtime/ui-upgrade-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir "$release_dir"
# Preserve the database and a placement snapshot before any new plugin boot.
docker compose exec -T db sh -c 'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb-dump --user=root --single-transaction --routines --triggers "$MARIADB_DATABASE"' > "$release_dir/database.sql"
test -s "$release_dir/database.sql"
snapshot_code='global $wpdb; echo wp_json_encode($wpdb->get_results("SELECT id,short_code,original_url,target_url,utm_source,utm_medium,utm_campaign,utm_content,utm_term,utm_id,template_id,brand_id,rules_version,qr_hash,SHA2(qr_svg,256) actual_qr_hash,status,author_id,idempotency_key,request_hash FROM {$wpdb->prefix}cargo_utm_placements ORDER BY id", ARRAY_A));'
docker compose exec -T wordpress wp --allow-root --path=/var/www/html --skip-plugins=cargo-utm eval "$snapshot_code" > "$release_dir/before.json"
python3 -c 'import json,sys; assert isinstance(json.load(open(sys.argv[1])),list)' "$release_dir/before.json"
printf 'Backup and placement snapshot saved in %s\n' "$release_dir"
docker compose build wordpress setup
docker compose up -d --no-deps wordpress
# setup waits for the plugin copy marker written by the new WordPress entrypoint.
docker compose run --rm --no-deps setup
docker compose exec -T wordpress wp --allow-root --path=/var/www/html --skip-plugins=cargo-utm eval "$snapshot_code" > "$release_dir/after.json"
python3 - "$release_dir" <<'PY'
import json
import sys
from pathlib import Path
root = Path(sys.argv[1])
before = json.loads((root / 'before.json').read_text())
after = json.loads((root / 'after.json').read_text())
assert before == after, 'STOP: existing placement snapshot changed; inspect privately before continuing'
print(f'PASS: {len(before)} existing placements preserved (codes, URLs, UTM, versions, QR, status, idempotency)')
PY
# Nginx must resolve the recreated FPM container's current address.
docker compose up -d --no-deps --force-recreate nginx
docker compose exec -T nginx nginx -t
printf 'UI upgrade complete. Run ./scripts/integration.sh and python3 tests/http.py\n'
