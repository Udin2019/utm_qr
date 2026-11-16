"""HTTP smoke checks against loopback HTTPS; test TLS only. No secrets logged.
Run after integration.sh. .env is parsed by docker compose, never by this script.
Anonymous wp-admin requests must redirect to WordPress login; private handlers remain protected.
"""
import json
import os
import re
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


def public_body(path, fields=None):
    args = ['curl', '--silent', '--show-error', '--insecure', '--max-time', '10', '--noproxy', '*', '--resolve', f'{internal}:{port}:127.0.0.1', '--write-out', '\n%{http_code}']
    if fields is not None:
        for key, value in fields.items():
            args += ['--data-urlencode', f'{key}={value}']
    result = subprocess.run([*args, f'https://{internal}:{port}{path}'], check=True, capture_output=True, text=True)
    body, status = result.stdout.rsplit('\n', 1)
    return int(status), body


def counter(row):
    # Query using existing WP connection. SQL uses integer fixture IDs only.
    placement_id = int(row['id'])
    code = f'global $wpdb; echo (int)$wpdb->get_var("SELECT COALESCE(SUM(get_count),0) FROM {{$wpdb->prefix}}cargo_utm_daily_hits WHERE placement_id={placement_id}");'
    result = subprocess.run(['docker', 'compose', 'exec', '-T', 'wordpress', 'wp', '--allow-root', '--path=/var/www/html', 'eval', code], check=True, capture_output=True, text=True)
    return int(result.stdout.strip())


status, headers = request(internal, '/')
assert status == 200 and 'text/html' in headers.get('content-type', ''), 'Root must serve the public generator'
assert 'noindex' in headers.get('x-robots-tag', ''), 'Root must carry noindex'
status, body = public_body('/')
assert status == 200 and 'class="cargo-standalone"' in body, 'Standalone shell missing'
match = re.search(r'<script type="application/json" id="cargo-config">(.*?)</script>', body, re.S)
assert match, 'Generator configuration missing'
config = json.loads(match[1])
assert config['isGuest'] and not config['canCreate'], 'Anonymous permissions incorrect'
assert config['endpoint'].endswith('/wp-admin/admin-ajax.php?action=cargo_public_generator')
public_path = '/wp-admin/admin-ajax.php?action=cargo_public_generator'
fields = {'nonce': config['nonce'], 'op': 'bootstrap', 'payload': '{}'}
status, body = public_body(public_path, fields)
assert status == 200, 'Public bootstrap unavailable'
catalog = json.loads(body)['data']
assert catalog['campaigns'] == [] and not any(key in catalog for key in ('placements', 'audit', 'authors', 'downloads', 'hits')), 'Public catalog leaks private data'
for op in ('issue', 'registry', 'record', 'decode', 'campaign', 'change'):
    assert public_body(public_path, dict(fields, op=op))[0] == 403, f'Guest operation exposed: {op}'
assert request(internal, public_path)[0] == 405, 'Public API must require POST'
assert public_body(public_path, dict(fields, nonce='invalid'))[0] == 403, 'Public API nonce missing'

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
print('PASS: public generator isolation, WordPress login protection, no Basic Auth, private handlers, public isolation, redirects, methods, GET/HEAD counters')
