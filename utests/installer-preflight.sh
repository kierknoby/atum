#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/atum-preflight.XXXXXX")
cleanup() { rm -rf "$TEST_ROOT"; }
trap cleanup EXIT HUP INT TERM

REAL_PHP=$(command -v php)
REAL_OPENSSL=$(command -v openssl)
REAL_SYSTEMCTL=$(command -v systemctl)
REAL_FLOCK=$(command -v flock)
REAL_GROUPADD=$(command -v groupadd)
REAL_USERADD=$(command -v useradd)
PHP_MM=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')

run_case() {
    name=$1
    servers=$2
    selection=$3
    expected=$4
    missing=${5:-}
    case_root="$TEST_ROOT/$name"
    mkdir -p "$case_root/bin" "$case_root/nginx" "$case_root/apache-available" "$case_root/apache-enabled" "$case_root/fpm"
    ln -s "$REAL_PHP" "$case_root/bin/php"
    ln -s "$REAL_OPENSSL" "$case_root/bin/openssl"
    ln -s "$REAL_SYSTEMCTL" "$case_root/bin/systemctl"
    for utility in dirname uname sed head getent; do
        ln -s "$(command -v "$utility")" "$case_root/bin/$utility"
    done
    [ "$missing" = flock ] || ln -s "$REAL_FLOCK" "$case_root/bin/flock"
    [ "$missing" = groupadd ] || ln -s "$REAL_GROUPADD" "$case_root/bin/groupadd"
    [ "$missing" = useradd ] || ln -s "$REAL_USERADD" "$case_root/bin/useradd"
    printf '%s\n' '#!/bin/sh' "echo \"PHP $PHP_MM.0 (fpm-fcgi)\"" > "$case_root/bin/php-fpm-test"
    chmod 0755 "$case_root/bin/php-fpm-test"
    case "$servers" in
        nginx|both) printf '%s\n' '#!/bin/sh' 'exit 0' > "$case_root/bin/nginx"; chmod 0755 "$case_root/bin/nginx" ;;
    esac
    case "$servers" in
        apache|both) printf '%s\n' '#!/bin/sh' 'exit 0' > "$case_root/bin/apache2"; chmod 0755 "$case_root/bin/apache2" ;;
    esac
    set +e
    PATH="$case_root/bin" \
        ATUM_FPM_BINARY="$case_root/bin/php-fpm-test" \
        ATUM_FPM_POOL_DIR="$case_root/fpm" \
        ATUM_NGINX_CONFIG_DIR="$case_root/nginx" \
        ATUM_APACHE_CONFIG_DIR="$case_root/apache-available" \
        ATUM_APACHE_ENABLED_DIR="$case_root/apache-enabled" \
        "$ROOT/install" --check --remote --allow-no-kamailio ${selection:+--web-server="$selection"} > "$case_root/output" 2>&1
    status=$?
    set -e
    case "$expected" in
        pass)
            [ "$status" -eq 0 ] || { cat "$case_root/output" >&2; exit 1; }
            grep -q 'remote development configuration requested' "$case_root/output"
            ;;
        ambiguous)
            [ "$status" -ne 0 ] || { echo "$name unexpectedly passed" >&2; exit 1; }
            grep -q 'Both Nginx and Apache were detected' "$case_root/output"
            ;;
        missing)
            [ "$status" -ne 0 ] || { echo "$name unexpectedly passed" >&2; exit 1; }
            grep -q "requires $missing" "$case_root/output"
            ;;
    esac
}

run_case nginx-only nginx '' pass
run_case apache-only apache '' pass
run_case both-ambiguous both '' ambiguous
run_case both-nginx both nginx pass
run_case both-apache both apache pass
run_case missing-flock nginx '' missing flock
run_case missing-groupadd nginx '' missing groupadd
run_case missing-useradd nginx '' missing useradd

echo 'PASS  remote pre-flight prerequisites and explicit web-server selection'
