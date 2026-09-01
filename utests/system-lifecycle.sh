#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu
[ "$(id -u)" -eq 0 ] || { echo 'system lifecycle test requires root' >&2; exit 1; }
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TEST_ROOT=$(mktemp -d /tmp/atum-system-test.XXXXXX)
export ATUM_PREFIX="$TEST_ROOT/application"
export ATUM_STATE_DIR="$TEST_ROOT/state"
export ATUM_CONFIG_DIR="$TEST_ROOT/configuration"
export ATUM_TRANSACTION_DIR="$TEST_ROOT/transaction"
export ATUM_ADMIN_USER=testadmin
export ATUM_ADMIN_PASSWORD_FILE="$TEST_ROOT/password"
cleanup() { if [ -f "$ATUM_CONFIG_DIR/install-ledger.json" ]; then php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies >/dev/null 2>&1 || true; fi; rm -rf "$TEST_ROOT"; }
trap cleanup EXIT HUP INT TERM
printf '%s\n' 'Lifecycle test password 123' > "$ATUM_ADMIN_PASSWORD_FILE"
install_id=0123456789abcdef0123456789abcdef
mkdir -m 0700 "$ATUM_TRANSACTION_DIR" "$ATUM_STATE_DIR"
printf '%s\n' "$install_id" > "$ATUM_TRANSACTION_DIR/install-id"
printf '%s\n' "$ATUM_PREFIX" > "$ATUM_TRANSACTION_DIR/application"
printf '%s\n' "$ATUM_STATE_DIR" > "$ATUM_TRANSACTION_DIR/state"
printf '%s\n' "$ATUM_CONFIG_DIR" > "$ATUM_TRANSACTION_DIR/configuration"
printf '%s\n' 1 > "$ATUM_TRANSACTION_DIR/intended-state"
printf '%s\n' "$install_id" > "$ATUM_STATE_DIR/.atum-provisional-install-id"
printf '%s\n' fake-atum-dependency > "$ATUM_TRANSACTION_DIR/packages-added"
"$ROOT/install" --development --allow-no-kamailio --no-deps --yes
[ -f "$ATUM_CONFIG_DIR/install-ledger.json" ] && [ ! -d "$ATUM_TRANSACTION_DIR" ]
grep -q 'fake-atum-dependency' "$ATUM_CONFIG_DIR/install-ledger.json"
php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --check >/dev/null
committed_id=$(sed -n '1p' "$ATUM_STATE_DIR/.atum-install-id")
printf '%s\n' ffffffffffffffffffffffffffffffff > "$ATUM_STATE_DIR/.atum-install-id"
if php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --check >/dev/null 2>&1; then echo 'mismatched install ID was accepted' >&2; exit 1; fi
printf '%s\n' "$committed_id" > "$ATUM_STATE_DIR/.atum-install-id"
php -r '$p=$argv[1]; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $i){$i->isDir()&&!$i->isLink()?rmdir($i->getPathname()):unlink($i->getPathname());} rmdir($p);' "$ATUM_PREFIX"
php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies >/dev/null
[ ! -e "$ATUM_PREFIX" ] && [ ! -e "$ATUM_STATE_DIR" ] && [ ! -e "$ATUM_CONFIG_DIR" ]

# Exercise the complete remote-development lifecycle against isolated host
# configuration directories and service stubs. No real service or firewall is touched.
export ATUM_PREFIX="$TEST_ROOT/remote/application"
export ATUM_STATE_DIR="$TEST_ROOT/remote/state"
export ATUM_CONFIG_DIR="$TEST_ROOT/remote/configuration"
export ATUM_TRANSACTION_DIR="$TEST_ROOT/remote/transaction"
export ATUM_WEB_SERVER=nginx
export ATUM_NGINX_CONFIG_DIR="$TEST_ROOT/host/nginx"
export ATUM_FPM_POOL_DIR="$TEST_ROOT/host/fpm"
export ATUM_FPM_SOCKET="$TEST_ROOT/host/run/atum-fpm.sock"
export ATUM_FPM_SERVICE=php-test-fpm
export ATUM_WEB_SERVICE=nginx-test
export ATUM_WEB_GROUP=atum
export ATUM_SERVICE_COMMAND="$TEST_ROOT/bin/systemctl"
export ATUM_FPM_BINARY="$TEST_ROOT/bin/php-fpm-test"
mkdir -p "$TEST_ROOT/remote" "$TEST_ROOT/host/nginx" "$TEST_ROOT/host/fpm" "$TEST_ROOT/host/run" "$TEST_ROOT/bin"
test_php_mm=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
printf '%s\n' '#!/bin/sh' "echo \"PHP $test_php_mm.0 (fpm-fcgi)\"" > "$ATUM_FPM_BINARY"
printf '%s\n' '#!/bin/sh' 'printf "%s\n" "$*" >> "'"$TEST_ROOT"'/service-actions"' > "$ATUM_SERVICE_COMMAND"
chmod 0755 "$ATUM_FPM_BINARY" "$ATUM_SERVICE_COMMAND"
printf '%s\n' 'pre-existing host configuration' > "$TEST_ROOT/host/nginx/operator.conf"
# Model interruption after a remote host file was created but before ledger commit.
remote_install_id=abcdefabcdefabcdefabcdefabcdefab
mkdir -m 0700 "$ATUM_TRANSACTION_DIR" "$ATUM_STATE_DIR"
printf '%s\n' "$remote_install_id" > "$ATUM_TRANSACTION_DIR/install-id"
printf '%s\n' "$ATUM_PREFIX" > "$ATUM_TRANSACTION_DIR/application"
printf '%s\n' "$ATUM_STATE_DIR" > "$ATUM_TRANSACTION_DIR/state"
printf '%s\n' "$ATUM_CONFIG_DIR" > "$ATUM_TRANSACTION_DIR/configuration"
printf '%s\n' 1 > "$ATUM_TRANSACTION_DIR/intended-state"
printf '%s\n' "$remote_install_id" > "$ATUM_STATE_DIR/.atum-provisional-install-id"
printf '%s\n' 'interrupted Atum FPM configuration' > "$ATUM_FPM_POOL_DIR/atum.conf"
interrupted_hash=$(sha256sum "$ATUM_FPM_POOL_DIR/atum.conf" | awk '{print $1}')
printf '%s\n%s\n%s\n' file "$ATUM_FPM_POOL_DIR/atum.conf" "$interrupted_hash" > "$ATUM_TRANSACTION_DIR/host-created-1"
printf '%s\n' php-test-fpm > "$ATUM_TRANSACTION_DIR/reload-service-1"
"$ROOT/install" --remote --allow-no-kamailio --no-deps --yes >"$TEST_ROOT/remote-no-development.out" 2>&1 && { echo 'remote mode accepted without --development' >&2; exit 1; }
"$ROOT/install" --development --remote --allow-no-kamailio --no-deps --yes > "$TEST_ROOT/remote-install.out"
grep -q 'Recovering interrupted Atum installation' "$TEST_ROOT/remote-install.out"
grep -q 'NOT SUITABLE FOR PRODUCTION' "$TEST_ROOT/remote-install.out"
grep -q 'No firewall rules were changed' "$TEST_ROOT/remote-install.out"
grep -q '"remote_development"' "$ATUM_CONFIG_DIR/install-ledger.json"
[ -f "$ATUM_NGINX_CONFIG_DIR/atum.conf" ] && [ -f "$ATUM_FPM_POOL_DIR/atum.conf" ]
[ "$(stat -c %a "$ATUM_CONFIG_DIR/tls/development.key")" = 600 ]
grep -q "$ATUM_PREFIX/public" "$ATUM_NGINX_CONFIG_DIR/atum.conf"
! grep -q "$ATUM_PREFIX/admin" "$ATUM_NGINX_CONFIG_DIR/atum.conf"
PATH="$TEST_ROOT/bin:$PATH" php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --check > "$TEST_ROOT/remote-uninstall-check.out"
grep -q "$ATUM_NGINX_CONFIG_DIR/atum.conf" "$TEST_ROOT/remote-uninstall-check.out"
PATH="$TEST_ROOT/bin:$PATH" php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies >/dev/null
[ ! -e "$ATUM_NGINX_CONFIG_DIR/atum.conf" ] && [ ! -e "$ATUM_FPM_POOL_DIR/atum.conf" ]
[ -f "$TEST_ROOT/host/nginx/operator.conf" ]
! grep -Eq '(^| )(ufw|firewall-cmd|iptables|nft)( |$)' "$TEST_ROOT/service-actions"
echo 'PASS  interrupted/local and remote HTTPS install/uninstall lifecycle'
