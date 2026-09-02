#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
. "$ROOT/installer/presentation.sh"
TEST_ROOT=$(mktemp -d /tmp/atum-presentation.XXXXXX)

cleanup() {
    trap - EXIT HUP INT TERM
    case "$TEST_ROOT" in
        /tmp/atum-presentation.*) rm -rf -- "$TEST_ROOT" ;;
        *) printf 'Refusing to clean unexpected test path: %s\n' "$TEST_ROOT" >&2 ;;
    esac
}
trap cleanup EXIT HUP INT TERM

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

# Package-manager presentation is handled by direct process output in install.
# The old quiet wrapper and synthetic heartbeat must not return.
! grep -q 'Working\.\.\.' "$ROOT/installer/presentation.sh"
! grep -q 'run-quiet' "$ROOT/installer/presentation.sh"

# Non-TTY completion output is deterministic plain text with a prominent URL,
# one Kamailio statement and an unmistakable closing delimiter.
atum_print_completion '/usr/share/atum' 'testadmin' 1 Nginx 0.0.0.0 8443 \
    'https://203.0.113.50:8443/' > "$TEST_ROOT/completion.out"
grep -q '^                 ATUM INSTALLATION COMPLETE$' "$TEST_ROOT/completion.out"
grep -q '^OPEN ATUM:$' "$TEST_ROOT/completion.out"
grep -q '^  https://203\.0\.113\.50:8443/$' "$TEST_ROOT/completion.out"
grep -q '^Administrator: testadmin$' "$TEST_ROOT/completion.out"
[ "$(grep -c '^Kamailio:      unchanged$' "$TEST_ROOT/completion.out")" -eq 1 ]
grep -q '^  atum-uninstall --check$' "$TEST_ROOT/completion.out"
grep -q '^The development certificate is self-signed\.$' "$TEST_ROOT/completion.out"
grep -q '^No firewall rules were changed\.' "$TEST_ROOT/completion.out"
grep -q '^       DEVELOPMENT PREVIEW - NOT FOR PRODUCTION$' "$TEST_ROOT/completion.out"
! grep -q 'PUBLIC_IP' "$TEST_ROOT/completion.out"
[ "$(tail -n 1 "$TEST_ROOT/completion.out")" = '============================================================' ]
echo 'PASS  installer native-output presentation, completion rendering and safe URL fallback'
