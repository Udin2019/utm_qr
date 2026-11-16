#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
umask 077
mkdir -p secrets
if [ ! -f .env ]; then cp .env.example .env; fi
if [ ! -f secrets/tls.key ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 30 \
        -subj '/CN=utm.cargo.test' -addext 'subjectAltName=DNS:utm.cargo.test,DNS:go.cargo.test' \
        -keyout secrets/tls.key -out secrets/tls.crt >/dev/null 2>&1
fi
printf 'Fill .env in your editor. Do not paste passwords into chat.\n'
