#!/usr/bin/env python3
"""Authorized host setup: disable Xray, issue TLS, expose the protected WP UI.
Run only from a root SSH terminal. Does not remove Xray files or database data.
"""
import datetime
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOMAIN = 'utmqr.kit-rb.com'
SITE = Path('/etc/nginx/sites-available/cargo-utmqr.conf')
LINK = Path('/etc/nginx/sites-enabled/cargo-utmqr.conf')
TRUST = Path('/etc/nginx/cargo-origin-ca.crt')
HOOK = Path('/etc/letsencrypt/renewal-hooks/deploy/cargo-utmqr-nginx')
WEBROOT = Path('/var/www/cargo-utmqr-acme')
MARKER = '# Managed by cargo_utm_qr'


def run(args, capture=False, check=True):
    result = subprocess.run(args, cwd=ROOT, capture_output=capture, text=True)
    if check and result.returncode:
        raise RuntimeError(f'{args[0]} failed (exit {result.returncode}); inspect terminal output')
    return result


def atomic(path, content, mode=0o644):
    if path.is_symlink():
        raise RuntimeError(f'Refusing to overwrite a symlink: {path}')
    fd, temp = tempfile.mkstemp(prefix='.cargo-', dir=path.parent)
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, 'w') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temp, path)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def managed(path):
    if path.is_symlink():
        raise RuntimeError(f'Unexpected managed-file symlink: {path}')
    if path.exists() and not path.read_text().startswith((MARKER, '#!/bin/sh\n' + MARKER)):
        raise RuntimeError(f'Existing file is not managed by this project: {path}')


def env_values():
    values = {}
    for line in (ROOT / '.env').read_text().splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            key, value = line.split('=', 1)
            values[key.strip()] = value.strip().strip('"\x27')
    return values


def patch_env(changes):
    path = ROOT / '.env'
    lines = path.read_text().splitlines()
    for key, value in changes.items():
        matches = [i for i, line in enumerate(lines) if line.startswith(key + '=')]
        if len(matches) > 1:
            raise RuntimeError('Duplicate configuration field: ' + key)
        if matches:
            lines[matches[0]] = key + '=' + value
        else:
            lines.append(key + '=' + value)
    atomic(path, '\n'.join(lines) + '\n', 0o600)


def origin_status(host, port, cert=None, attempts=1):
    args = ['curl', '--silent', '--show-error', '--noproxy', '*', '--connect-timeout', '3', '--max-time', '5', '--resolve', f'{host}:{port}:127.0.0.1', '--output', '/dev/null', '--write-out', '%{http_code}']
    args += ['--cacert', str(cert)] if cert else ['--insecure']
    args.append(f'https://{host}:{port}/wp-admin/')
    last = None
    for attempt in range(attempts):
        last = run(args, capture=True, check=False)
        status = last.stdout.strip()
        if last.returncode == 0 and status in ('200', '302', '303', '401'):
            return status
        # A recreated container can reset/refuse TLS before Nginx starts.
        # Certificate verification errors (e.g. curl60) are never bypassed.
        retryable = last.returncode in (7, 35, 52, 56) or (last.returncode == 0 and status in ('000', '502', '503'))
        if not retryable or attempt + 1 == attempts:
            break
        time.sleep(1)
    detail = last.stderr.strip()[:1000]
    raise RuntimeError(f'Origin check failed: curl={last.returncode}, HTTP={last.stdout.strip()}; {detail}')


def main():
    if os.geteuid() != 0:
        raise RuntimeError('Run as root in the server SSH terminal')
    for executable in ['docker', 'nginx', 'certbot', 'openssl', 'systemctl', 'ss', 'curl']:
        if not shutil.which(executable):
            raise RuntimeError('Missing existing prerequisite: ' + executable)
    if (ROOT / '.env').is_symlink():
        raise RuntimeError('Unexpected .env symlink')
    for path in [SITE, HOOK]:
        managed(path)
    if LINK.is_symlink():
        if LINK.resolve() != SITE:
            raise RuntimeError('Existing enabled-site symlink points elsewhere')
    elif LINK.exists():
        raise RuntimeError('Existing enabled-site entry is not a symlink')
    values = env_values()
    host = values.get('CARGO_UTM_HOST', 'utm.cargo.test')
    go_host = values.get('CARGO_GO_HOST', 'go.cargo.test')
    for name in [host, go_host]:
        if len(name) > 253 or not re.fullmatch(r'[a-z0-9][a-z0-9.-]*[a-z0-9]', name):
            raise RuntimeError('Invalid origin host in configuration')
    port = int(values.get('CARGO_HTTPS_PORT', '18443'))
    if not 1024 <= port <= 65535 or values.get('CARGO_BIND', '127.0.0.1') != '127.0.0.1':
        raise RuntimeError('Expected a loopback-only origin on an unprivileged port')
    addresses = {row[4][0] for row in socket.getaddrinfo(DOMAIN, 80, socket.AF_INET)}
    if '38.180.121.98' not in addresses:
        raise RuntimeError('DNS A does not contain the confirmed server IP')
    run(['docker', 'compose', 'config', '--quiet'])
    if origin_status(host, port) not in ('200', '302', '303', '401'):
        raise RuntimeError('Origin does not return the generator or WordPress login redirect')
    listeners = run(['ss', '-H', '-ltnp', '( sport = :80 or sport = :443 )'], capture=True).stdout
    for line in listeners.splitlines():
        if '"xray"' not in line and '"nginx"' not in line:
            raise RuntimeError('Port 80/443 is owned by another process; stop and inspect manually')
    nginx_was_active = run(['systemctl', 'is-active', '--quiet', 'nginx'], capture=True, check=False).returncode == 0
    xray_was_active = run(['systemctl', 'is-active', '--quiet', 'xray'], capture=True, check=False).returncode == 0
    xray_was_enabled = run(['systemctl', 'is-enabled', '--quiet', 'xray'], capture=True, check=False).returncode == 0
    if '"nginx"' in listeners and not nginx_was_active:
        raise RuntimeError('Nginx listeners exist outside the expected system service')
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = ROOT / '.runtime' / ('public-https-' + stamp)
    backup.mkdir(mode=0o700, parents=True, exist_ok=False)
    for source, name in [(ROOT / '.env', 'env'), (SITE, 'nginx-site'), (TRUST, 'origin-ca.crt'), (HOOK, 'renew-hook'), (Path('/usr/local/etc/xray/config.json'), 'xray-config.json')]:
        if source.is_file():
            shutil.copyfile(source, backup / name)
            (backup / name).chmod(0o600)
    (backup / 'service-state.json').write_text(json.dumps({'xray_active': xray_was_active, 'xray_enabled': xray_was_enabled, 'nginx_active': nginx_was_active, 'site_existed': SITE.exists(), 'link_existed': LINK.is_symlink()}))
    (backup / 'service-state.json').chmod(0o600)
    print('Private backups saved:', backup, flush=True)
    with (backup / 'database.sql').open('wb') as stream:
        result = subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'sh', '-c', 'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; exec mariadb-dump --user=root --single-transaction "$MARIADB_DATABASE"'], cwd=ROOT, stdout=stream)
    (backup / 'database.sql').chmod(0o600)
    if result.returncode or not (backup / 'database.sql').stat().st_size:
        raise RuntimeError('Database backup failed; no service changes made')

    run(['docker', 'compose', 'build', 'wordpress', 'setup'])

    # Prepare and test an HTTP-only ACME vhost while Xray still owns 443.
    SITE.parent.mkdir(parents=True, exist_ok=True)
    if WEBROOT.is_symlink():
        raise RuntimeError('Unexpected ACME webroot symlink')
    WEBROOT.mkdir(mode=0o755, parents=True, exist_ok=True)
    final_ready = False
    changed_env = False
    disabled_xray = False
    origin_changed = False
    try:
        # A repeated successful install keeps its live HTTPS vhost during certbot.
        if not SITE.exists() or 'listen 443 ssl;' not in SITE.read_text():
            atomic(SITE, (ROOT / 'infra/nginx/public/utmqr-bootstrap.conf').read_text())
        if not LINK.is_symlink():
            LINK.symlink_to(SITE)
        run(['nginx', '-t'])
        run(['systemctl', 'reload' if nginx_was_active else 'start', 'nginx'])
        run(['certbot', 'certonly', '--webroot', '--webroot-path', str(WEBROOT), '--cert-name', DOMAIN, '-d', DOMAIN, '--keep-until-expiring'])
        cert = Path('/etc/letsencrypt/live') / DOMAIN / 'fullchain.pem'
        run(['openssl', 'x509', '-noout', '-checkhost', DOMAIN, '-in', str(cert)])
        run(['openssl', 'x509', '-noout', '-checkend', '86400', '-in', str(cert)])

        # Use a separate long-lived self-signed origin identity, trusted only by
        # this loopback proxy. Public browsers get the Let's Encrypt certificate.
        origin_dir = ROOT / 'secrets' / 'origin-tls'
        origin_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
        key = origin_dir / 'tls.key'
        origin_cert = origin_dir / 'tls.crt'
        if key.exists() != origin_cert.exists() or key.is_symlink() or origin_cert.is_symlink():
            raise RuntimeError('Unexpected partial/symlink origin TLS files')
        if not key.exists():
            with tempfile.TemporaryDirectory(dir=origin_dir) as temporary:
                temp = Path(temporary)
                command = ['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '3650', '-subj', '/CN=' + host, '-addext', 'subjectAltName=DNS:' + host + ',DNS:' + go_host, '-keyout', str(temp / 'key'), '-out', str(temp / 'cert')]
                run(command, capture=True)
                os.chmod(temp / 'key', 0o600)
                os.chmod(temp / 'cert', 0o644)
                os.link(temp / 'key', key)
                os.link(temp / 'cert', origin_cert)
        run(['openssl', 'x509', '-noout', '-checkhost', host, '-in', str(origin_cert)])
        run(['openssl', 'x509', '-noout', '-checkend', '2592000', '-in', str(origin_cert)])
        if TRUST.is_symlink():
            raise RuntimeError('Unexpected origin trust-file symlink')
        atomic(TRUST, origin_cert.read_text())
        patch_env({'CARGO_PUBLIC_UTM_URL': 'https://' + DOMAIN, 'CARGO_TLS_CERT': './secrets/origin-tls/tls.crt', 'CARGO_TLS_KEY': './secrets/origin-tls/tls.key'})
        changed_env = True
        origin_changed = True
        run(['docker', 'compose', 'up', '-d', '--no-deps', '--force-recreate', 'wordpress'])
        run(['docker', 'compose', 'up', '-d', '--no-deps', '--force-recreate', 'nginx'])
        if origin_status(host, port, origin_cert, attempts=15) not in ('200', '302', '303'):
            raise RuntimeError('Updated origin failed verified TLS/WordPress entry check')
        # The public URL is supplied in Compose configuration, not baked into images.
        expected = run(['docker', 'compose', 'exec', '-T', 'wordpress', 'wp', '--allow-root', '--path=/var/www/html', 'eval', 'echo home_url();'], capture=True).stdout.strip()
        if expected != 'https://' + DOMAIN:
            raise RuntimeError('WordPress canonical URL does not match the public domain')
        template = (ROOT / 'infra/nginx/public/utmqr-https.conf.template').read_text()
        final = template.replace('@@ORIGIN_HOST@@', host).replace('@@ORIGIN_PORT@@', str(port))
        atomic(SITE, final)
        run(['nginx', '-t'])
        # User explicitly authorized disabling Xray; preserve its installation.
        disabled_xray = True
        run(['systemctl', 'disable', '--now', 'xray.service'])
        run(['systemctl', 'reload', 'nginx'])
        for attempt in range(10):
            probe = run(['curl', '--silent', '--show-error', '--noproxy', '*', '--max-time', '15', '--resolve', DOMAIN + ':443:127.0.0.1', '--output', '/dev/null', '--write-out', '%{http_code}', 'https://' + DOMAIN + '/wp-admin/'], capture=True, check=False)
            status = probe.stdout.strip()
            if probe.returncode == 0 and status in ('302', '303'):
                break
            time.sleep(1)
        if status not in ('302', '303'):
            raise RuntimeError('Public HTTPS does not return expected WordPress login redirect')
        final_ready = True
        hook = '# Managed by cargo_utm_qr renewal hook.\n#!/bin/sh\nset -eu\n[ "${RENEWED_LINEAGE:-}" = "/etc/letsencrypt/live/' + DOMAIN + '" ] || exit 0\n/usr/sbin/nginx -t\n/bin/systemctl reload nginx\n'
        # Shebang must be the first line for Certbot execution.
        hook = '#!/bin/sh\n' + hook.replace('#!/bin/sh\n', '', 1)
        if HOOK.exists() and not HOOK.read_text().startswith(('# Managed by cargo_utm_qr', '#!/bin/sh\n# Managed by cargo_utm_qr')):
            raise RuntimeError('Unexpected existing renewal hook')
        HOOK.parent.mkdir(parents=True, exist_ok=True)
        atomic(HOOK, hook, 0o755)
        run(['systemctl', 'enable', 'nginx'])
        run(['systemctl', 'enable', '--now', 'certbot.timer'])
        print('PASS: trusted HTTPS and WordPress login protection:', 'https://' + DOMAIN + '/wp-admin/')
        print('Xray stopped and disabled; its files preserved. Public certificate renewal timer enabled.')
        print('Backups:', backup)
        print('Next check: certbot renew --cert-name ' + DOMAIN + ' --dry-run')
    except (Exception, KeyboardInterrupt):
        if final_ready:
            print('HTTPS is working; auxiliary renewal/startup step failed. Do not remove the live certificate.', file=sys.stderr)
            raise
        print('Installation incomplete. Restoring prior site/environment; backups:', backup, file=sys.stderr)
        if changed_env:
            atomic(ROOT / '.env', (backup / 'env').read_text(), 0o600)
            if origin_changed:
                run(['docker', 'compose', 'up', '-d', '--no-deps', '--force-recreate', 'wordpress'], check=False)
                run(['docker', 'compose', 'up', '-d', '--no-deps', '--force-recreate', 'nginx'], check=False)
        if (backup / 'origin-ca.crt').exists():
            atomic(TRUST, (backup / 'origin-ca.crt').read_text())
        if (backup / 'nginx-site').exists():
            atomic(SITE, (backup / 'nginx-site').read_text())
        else:
            # Leave a harmless HTTP-only vhost for retrying ACME; no 443 conflict.
            atomic(SITE, (ROOT / 'infra/nginx/public/utmqr-bootstrap.conf').read_text())
        run(['nginx', '-t'], check=False)
        run(['systemctl', 'reload', 'nginx'], check=False)
        if disabled_xray:
            if xray_was_enabled:
                run(['systemctl', 'enable', 'xray.service'], check=False)
            if xray_was_active:
                run(['systemctl', 'start', 'xray.service'], check=False)
        raise


if __name__ == '__main__':
    try:
        main()
    except (Exception, KeyboardInterrupt) as error:
        print('STOP:', error, file=sys.stderr)
        sys.exit(1)
