"""Local installer/configuration checks; no services, network or /etc writes."""
import importlib.util
import os
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('public_install', ROOT / 'scripts/install-public-https.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)


class PublicHTTPS(unittest.TestCase):
    def test_atomic_private_file_and_symlink_guard(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / 'config'
            installer.atomic(path, 'first', 0o600)
            installer.atomic(path, 'second', 0o600)
            self.assertEqual(path.read_text(), 'second')
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            link = Path(d) / 'link'
            link.symlink_to(path)
            with self.assertRaises(RuntimeError):
                installer.atomic(link, 'bad')
            self.assertEqual(path.read_text(), 'second')

    def test_env_patch_preserves_unrelated_values(self):
        with tempfile.TemporaryDirectory() as d:
            previous = installer.ROOT
            installer.ROOT = Path(d)
            try:
                path = installer.ROOT / '.env'
                path.write_text('CARGO_DB_PASSWORD=example-only\nCARGO_UTM_HOST=utm.cargo.test\n')
                installer.patch_env({'CARGO_PUBLIC_UTM_URL': 'https://utmqr.kit-rb.com'})
                self.assertIn('CARGO_DB_PASSWORD=example-only', path.read_text())
                self.assertEqual(installer.env_values()['CARGO_UTM_HOST'], 'utm.cargo.test')
                self.assertEqual(installer.env_values()['CARGO_PUBLIC_UTM_URL'], 'https://utmqr.kit-rb.com')
                self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            finally:
                installer.ROOT = previous

    def test_foreign_config_rejected_and_own_hook_accepted(self):
        with tempfile.TemporaryDirectory() as d:
            path = Path(d) / 'conf'
            path.write_text('foreign configuration')
            with self.assertRaises(RuntimeError):
                installer.managed(path)
            path.write_text('#!/bin/sh\n# Managed by cargo_utm_qr renewal hook.\n')
            installer.managed(path)

    def test_canonical_url_and_origin_are_independent(self):
        conf = yaml.safe_load((ROOT / 'compose.yaml').read_text())
        wordpress = conf['services']['wordpress']['environment']['WORDPRESS_CONFIG_EXTRA']
        self.assertEqual(wordpress, conf['services']['setup']['environment']['WORDPRESS_CONFIG_EXTRA'])
        for public, expected in [('', 'https://utm.cargo.test'), ('https://utmqr.kit-rb.com', 'https://utmqr.kit-rb.com')]:
            env = dict(os.environ, CARGO_UTM_HOST='utm.cargo.test', CARGO_PUBLIC_UTM_URL=public)
            result = subprocess.run(['php', '-r', wordpress + 'echo WP_HOME . "|" . WP_SITEURL;'], env=env, check=True, capture_output=True, text=True)
            self.assertEqual(result.stdout, expected + '|' + expected)

    def test_proxy_verifies_origin_and_forwards_authorization(self):
        text = (ROOT / 'infra/nginx/public/utmqr-https.conf.template').read_text()
        self.assertIn('proxy_ssl_verify on;', text)
        self.assertIn('proxy_set_header Authorization $http_authorization;', text)
        self.assertIn('proxy_ssl_server_name on;', text)
        self.assertIn('ssl_protocols TLSv1.2 TLSv1.3;', text)
        self.assertIn('location ^~ /.well-known/acme-challenge/', text)
        self.assertNotIn('proxy_ssl_verify off', text)
        self.assertNotIn('auth_basic off', text)

    def test_origin_waits_for_tls_startup(self):
        results = [
            subprocess.CompletedProcess([], 35, '000', 'TLS connection reset'),
            subprocess.CompletedProcess([], 7, '000', 'Connection refused'),
            subprocess.CompletedProcess([], 0, '401', ''),
        ]
        with patch.object(installer, 'run', side_effect=results) as run, patch.object(installer.time, 'sleep'):
            self.assertEqual(installer.origin_status('utm.cargo.test', 18443, Path('/test/ca.crt'), attempts=3), '401')
            self.assertEqual(run.call_count, 3)
            self.assertIn('--cacert', run.call_args.args[0])
            self.assertNotIn('--insecure', run.call_args.args[0])

    def test_origin_certificate_errors_reported_without_retry(self):
        result = subprocess.CompletedProcess([], 60, '000', 'SSL certificate verification failed')
        with patch.object(installer, 'run', return_value=result) as run, patch.object(installer.time, 'sleep'):
            with self.assertRaisesRegex(RuntimeError, 'curl=60.*SSL certificate verification failed'):
                installer.origin_status('utm.cargo.test', 18443, Path('/test/ca.crt'), attempts=3)
            self.assertEqual(run.call_count, 1)

    def test_origin_accepts_public_generator(self):
        result = subprocess.CompletedProcess([], 0, '200', '')
        with patch.object(installer, 'run', return_value=result):
            self.assertEqual(installer.origin_status('utm.cargo.test', 18443, Path('/test/ca.crt')), '200')

    def test_origin_accepts_wordpress_login_redirect(self):
        result = subprocess.CompletedProcess([], 0, '302', '')
        with patch.object(installer, 'run', return_value=result):
            self.assertEqual(installer.origin_status('utm.cargo.test', 18443, Path('/test/ca.crt')), '302')


if __name__ == '__main__':
    unittest.main()
