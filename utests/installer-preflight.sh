#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/atum-preflight.XXXXXX")
cleanup() { rm -rf "$TEST_ROOT"; }
trap cleanup EXIT HUP INT TERM

REAL_OPENSSL=$(command -v openssl)
REAL_SYSTEMCTL=$(command -v systemctl)
REAL_FLOCK=$(command -v flock)
REAL_GROUPADD=$(command -v groupadd)
REAL_USERADD=$(command -v useradd)
PHP_MM=8.4

run_case() {
    name=$1
    servers=$2
    selection=$3
    expected=$4
    missing=${5:-}
    scope=${6:-remote}
    expected_server=${selection:-$servers}
    case_root="$TEST_ROOT/$name"
    mkdir -p "$case_root/bin" "$case_root/nginx" "$case_root/apache-available" "$case_root/apache-enabled" "$case_root/fpm"
    if [ "$missing" != php ]; then
        printf '%s\n' '#!/bin/sh' \
            'case "$2" in' \
            '*PHP_VERSION*) echo 8.4.0 ;;' \
            '*version_compare*) exit 0 ;;' \
            '*PHP_MAJOR_VERSION*) echo 8.4 ;;' \
            '*extension_loaded*) exit 0 ;;' \
            '*filter_var*) exit 0 ;;' \
            '*) exit 0 ;;' \
            'esac' > "$case_root/bin/php"
        chmod 0755 "$case_root/bin/php"
    fi
    ln -s "$REAL_OPENSSL" "$case_root/bin/openssl"
    ln -s "$REAL_SYSTEMCTL" "$case_root/bin/systemctl"
    for utility in dirname uname sed head getent tr; do
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
    remote_arg=
    [ "$scope" = remote ] && remote_arg=--remote
    set +e
    PATH="$case_root/bin" \
        ATUM_FPM_BINARY="$case_root/bin/php-fpm-test" \
        ATUM_FPM_POOL_DIR="$case_root/fpm" \
        ATUM_NGINX_CONFIG_DIR="$case_root/nginx" \
        ATUM_APACHE_CONFIG_DIR="$case_root/apache-available" \
        ATUM_APACHE_ENABLED_DIR="$case_root/apache-enabled" \
        "$ROOT/install" --check $remote_arg --allow-no-kamailio ${selection:+--web-server="$selection"} > "$case_root/output" 2>&1
    status=$?
    set -e
    case "$expected" in
        pass)
            [ "$status" -eq 0 ] || { cat "$case_root/output" >&2; exit 1; }
            grep -q '^Atum GUI installation pre-flight$' "$case_root/output"
            ! grep -q '^\[[1-6]/6\]' "$case_root/output"
            grep -q 'Remote HTTPS     :' "$case_root/output"
            grep -q "Web server       : $expected_server (detected; reused)" "$case_root/output"
            ;;
        ambiguous)
            [ "$status" -ne 0 ] || { echo "$name unexpectedly passed" >&2; exit 1; }
            grep -q 'Both Nginx and Apache were detected' "$case_root/output"
            ;;
        missing)
            [ "$status" -ne 0 ] || { echo "$name unexpectedly passed" >&2; exit 1; }
            grep -q '^Atum GUI installation pre-flight$' "$case_root/output"
            ! grep -q '^\[[1-6]/6\]' "$case_root/output"
            if [ "$missing" = php ]; then
                grep -q 'PHP              : missing' "$case_root/output"
                if [ "$scope" = remote ] && [ "$servers" = none ]; then
                    grep -q -- '- PHP 8.2 or newer CLI' "$case_root/output"
                    [ "$(grep -c -- '^- PHP 8.2 or newer CLI$' "$case_root/output")" -eq 1 ]
                    grep -q -- '- Nginx' "$case_root/output"
                    grep -q -- '- PHP-FPM cannot be evaluated until PHP is installed' "$case_root/output"
                    grep -q 'selected; not installed' "$case_root/output"
                    ! grep -q -- '^- 8.2$' "$case_root/output"
                fi
            else
                grep -q -- "- $missing" "$case_root/output"
            fi
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
run_case missing-php-remote nginx '' missing php
run_case missing-php-web-remote none '' missing php
run_case missing-flock-local none '' missing flock local
run_case missing-groupadd-local none '' missing groupadd local
run_case missing-useradd-local none '' missing useradd local
run_case missing-php-local none '' missing php local

echo 'PASS  baseline and remote pre-flight prerequisites and explicit web-server selection'
