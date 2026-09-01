#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
. "$ROOT/installer/presentation.sh"

expect_url() {
    expected=$1
    shift
    actual=$(atum_https_url "$@")
    [ "$actual" = "$expected" ] || {
        printf 'expected URL %s, got %s\n' "$expected" "$actual" >&2
        exit 1
    }
}

expect_url 'https://192.0.2.10:8443/' \
    192.0.2.10 8443 '' ''
expect_url 'https://10.20.30.40:8443/' \
    0.0.0.0 8443 '127.0.0.1 10.20.30.40 169.254.1.2' ''
expect_url 'https://[2001:db8::10]:9443/' \
    2001:db8::10 9443 '' ''
expect_url 'https://[2001:db8::20]:9443/' \
    :: 9443 '::1 fe80::1 2001:db8::20' ''
expect_url 'https://203.0.113.50:8443/' \
    0.0.0.0 8443 '10.0.0.10 192.168.1.20' '198.51.100.2 50123 203.0.113.50 22'
expect_url 'https://<server-address>:8443/' \
    0.0.0.0 8443 '10.0.0.10 192.168.1.20' ''
expect_url 'https://<server-address>:8443/' \
    0.0.0.0 8443 '127.0.0.1 169.254.1.2' ''
expect_url 'https://<server-address>:9443/' \
    :: 9443 '10.0.0.10 2001:db8::10 2001:db8::20' ''

echo 'PASS  installer HTTPS URL rendering and safe address fallback'
