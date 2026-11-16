"""HTTP smoke checks against loopback HTTPS; test TLS only. No secrets logged.
Run after integration.sh. .env is parsed by docker compose, never by this script.
Anonymous wp-admin requests must redirect to WordPress login; private handlers remain protected.
"""
import json
import os
import subprocess
from pathlib import Path

host = os.environ.get('CARGO_GO_HOST', 'go.cargo.test')
internal = os.environ.get('CARGO_UTM_HOST', 'utm.cargo.test')
port = os.environ.get('CARGO_HTTPS_PORT', '18443')
fixtures = json.loads(Path('.runtime/acceptance/http-fixtures.json').read_text())


def request(domain, path, method='HEAD'):
    proc = subprocess.run(['curl', '--silent', '--show-error', '--insecure', '--max-time', '10', '--noproxy', '*', '--resolve', f'{domain}:{port}:127.0.0.1', *(['--head'] if method == 'HEAD' else ['--request', method]), '--dump-header', '-', '--output', '/dev/null', f'https://{domain}:{port}{path}'], check=True, capture_output=True, text=True)
    headers = proc.stdout.splitlines()
    status = int(headers[0].split()[1])
    values = dict(line.split(': ', 1) for line in headers[1:] if ': ' in line)
    return status, {key.lower(): value for key, value in values.items()}


def counter(row):
    # Query using existing WP connection. SQL uses integer fixture IDs only.
    placement_id = int(row['id'])
    code = f'global $wpdb; echo (int)$wpdb->get_var("SELECT COALESCE(SUM(get_count),0) FROM {{$wpdb->prefix}}cargo_utm_daily_hits WHERE placement_id={placement_id}");'
    result = subprocess.run(['docker', 'compose', 'exec', '-T', 'wordpress', 'wp', '--allow-root', '--path=/var/www/html', 'eval', code], check=True, capture_output=True, text=True)
    return int(result.stdout.strip())


status, headers = request(internal, '/')
assert status in (302, 303) and '/wp-login.php' in headers.get('location', ''), 'Root must lead anonymous users to login'
assert 'noindex' in headers.get('x-robots-tag', ''), 'Root must carry noindex'
status, headers = request(internal, '/robots.txt', 'GET')
assert status == 200 and 'noindex' in headers.get('x-robots-tag', ''), 'Robots endpoint/noindex missing'
status, headers = request(internal, '/wp-admin/')
assert status in (302, 303) and '/wp-login.php' in headers.get('location', ''), 'Anonymous admin must redirect to WordPress login'
assert 'www-authenticate' not in headers, 'Basic Auth must be disabled'
status, headers = request(internal, '/wp-login.php')
assert status in (200, 302, 303) and 'www-authenticate' not in headers, 'WordPress login must be reachable without Basic Auth'
assert 'noindex' in headers.get('x-robots-tag', ''), 'Login must carry noindex'
status, headers = request(internal, '/wp-admin/admin-post.php?action=cargo_download')
assert status in (302, 303, 400, 401, 403), 'Anonymous download handler must not return a QR file'
assert 'image/' not in headers.get('content-type', ''), 'Anonymous QR download exposed'
status, headers = request(internal, '/wp-admin/admin-ajax.php?action=cargo_workspace', 'POST')
assert status in (400, 401, 403), 'Anonymous workspace API must be rejected'
for path in ('/wp-admin/', '/wp-login.php', '/wp-json/', '/', '/r/not-a-code'):
    assert request(host, path)[0] == 404, f'Public path exposed: {path}'
assert request(host, '/r/000000000000')[0] == 404
for key in ('active', 'archived'):
    row = fixtures[key]
    path = f"/r/{row['short_code']}"
    before = counter(row)
    status, headers = request(host, path + '?url=https://example.org&utm_source=evil')
    assert status == 302 and headers['location'] == row['target_url']
    assert 'no-store' in headers['cache-control']
    assert counter(row) == before, 'HEAD must not increment'
    assert request(host, path, 'GET')[0] == 302
    after = counter(row)
    assert after == before + 1, f'GET must increment once ({key}): before={before}, after={after}'
    assert request(host, path, 'GET')[0] == 302
    after = counter(row)
    assert after == before + 2, f'Repeated GET must update existing daily row ({key}): before={before}, after={after}'
    status, headers = request(host, path, 'POST')
    assert status == 405 and headers['allow'] == 'GET, HEAD'
    assert counter(row) == before + 2, 'POST must not increment'
row = fixtures['revoked']
before = counter(row)
assert request(host, f"/r/{row['short_code']}", 'GET')[0] == 410
assert counter(row) == before, 'Revoked must not increment'
print('PASS: WordPress login protection, no Basic Auth, private handlers, public isolation, redirects, methods, GET/HEAD counters')
