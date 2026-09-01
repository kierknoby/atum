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
echo 'PASS  interrupted-install recovery and committed uninstall lifecycle'
