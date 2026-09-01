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
export ATUM_LIFECYCLE_LOCK_PATH="$TEST_ROOT/lifecycle-lock"
export ATUM_ADMIN_USER=testadmin
export ATUM_ADMIN_PASSWORD_FILE="$TEST_ROOT/password"
cleanup() { if [ -f "$ATUM_CONFIG_DIR/install-ledger.json" ]; then php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies >/dev/null 2>&1 || true; fi; [ ! -L /usr/local/sbin/atum ] || [ "$(readlink /usr/local/sbin/atum)" != "$ATUM_PREFIX/bin/atum" ] || unlink /usr/local/sbin/atum; [ ! -L /usr/local/sbin/atum-uninstall ] || [ "$(readlink /usr/local/sbin/atum-uninstall)" != "$ATUM_PREFIX/uninstall.php" ] || unlink /usr/local/sbin/atum-uninstall; rm -rf "$TEST_ROOT"; }
trap cleanup EXIT HUP INT TERM
printf '%s\n' 'Lifecycle test password 123' > "$ATUM_ADMIN_PASSWORD_FILE"
install_id=0123456789abcdef0123456789abcdef

# Recovery must never delete an atum identity it cannot prove it created: an
# intended-* record alone describes a crash window, not proven ownership.
ambiguity_dir="$TEST_ROOT/ambiguity-transaction"

mkdir -m 0700 "$ambiguity_dir"
printf '%s\n' "$install_id" > "$ambiguity_dir/install-id"
printf '%s\n' 1 > "$ambiguity_dir/intended-user"
groupadd atum
useradd -r -g atum -s /usr/sbin/nologin -M atum
if ATUM_TRANSACTION_DIR="$ambiguity_dir" "$ROOT/install" --development --allow-no-kamailio --no-deps --yes > "$TEST_ROOT/ambiguous-user.out" 2>&1; then
    echo 'recovery removed an atum user it could not prove it created' >&2
    exit 1
fi
grep -q 'cannot prove that it created the account' "$TEST_ROOT/ambiguous-user.out"
id atum >/dev/null 2>&1
userdel atum
! getent group atum >/dev/null || groupdel atum
rm -rf "$ambiguity_dir"

mkdir -m 0700 "$ambiguity_dir"
printf '%s\n' "$install_id" > "$ambiguity_dir/install-id"
printf '%s\n' 1 > "$ambiguity_dir/intended-group"
groupadd atum
if ATUM_TRANSACTION_DIR="$ambiguity_dir" "$ROOT/install" --development --allow-no-kamailio --no-deps --yes > "$TEST_ROOT/ambiguous-group.out" 2>&1; then
    echo 'recovery removed an atum group it could not prove it created' >&2
    exit 1
fi
grep -q 'cannot prove that it created the group' "$TEST_ROOT/ambiguous-group.out"
getent group atum >/dev/null
groupdel atum
rm -rf "$ambiguity_dir" "$ATUM_LIFECYCLE_LOCK_PATH"

mkdir -m 0700 "$ATUM_TRANSACTION_DIR" "$ATUM_STATE_DIR"
printf '%s\n' "$install_id" > "$ATUM_TRANSACTION_DIR/install-id"
printf '%s\n' "$ATUM_PREFIX" > "$ATUM_TRANSACTION_DIR/application"
printf '%s\n' "$ATUM_STATE_DIR" > "$ATUM_TRANSACTION_DIR/state"
printf '%s\n' "$ATUM_CONFIG_DIR" > "$ATUM_TRANSACTION_DIR/configuration"
printf '%s\n' 1 > "$ATUM_TRANSACTION_DIR/intended-state"
printf '%s\n' "$install_id" > "$ATUM_STATE_DIR/.atum-provisional-install-id"
printf '%s\n' fake-atum-dependency > "$ATUM_TRANSACTION_DIR/packages-added"
ln -s "$ATUM_PREFIX/bin/atum" /usr/local/sbin/atum
ln -s "$ATUM_PREFIX/uninstall.php" /usr/local/sbin/atum-uninstall
printf '%s\n%s\n%s\n' symlink /usr/local/sbin/atum "$ATUM_PREFIX/bin/atum" > "$ATUM_TRANSACTION_DIR/host-created-cli"
printf '%s\n%s\n%s\n' symlink /usr/local/sbin/atum-uninstall "$ATUM_PREFIX/uninstall.php" > "$ATUM_TRANSACTION_DIR/host-created-uninstall"
partial_host_file="$TEST_ROOT/interrupted-host-config.partial"
printf '%s\n' 'incomplete interrupted write' > "$partial_host_file"
printf '%s\n%s\n' transient "$partial_host_file" > "$ATUM_TRANSACTION_DIR/host-created-partial"

# A live lifecycle lock must prevent a second installer from mistaking the
# transaction above for an interrupted operation.
mkdir -m 0700 "$ATUM_LIFECYCLE_LOCK_PATH"
exec 8< "$ATUM_LIFECYCLE_LOCK_PATH"
flock -n 8
if "$ROOT/install" --development --allow-no-kamailio --no-deps --yes > "$TEST_ROOT/concurrent-install.out" 2>&1; then
    echo 'concurrent installer acquired the lifecycle lock' >&2
    exit 1
fi
grep -q 'Another Atum install or uninstall operation is already running' "$TEST_ROOT/concurrent-install.out"
[ -f "$ATUM_TRANSACTION_DIR/install-id" ] && [ -f "$ATUM_STATE_DIR/.atum-provisional-install-id" ]
flock -u 8

# Once the interrupted process has released the lock, normal recovery remains
# available and the installation can proceed.
"$ROOT/install" --development --allow-no-kamailio --no-deps --yes
[ ! -e "$partial_host_file" ]
[ -f "$ATUM_CONFIG_DIR/install-ledger.json" ] && [ ! -d "$ATUM_TRANSACTION_DIR" ]
grep -q 'fake-atum-dependency' "$ATUM_CONFIG_DIR/install-ledger.json"

if command -v runuser >/dev/null 2>&1; then
    if runuser -u atum -- sh -c 'exec 7< "$1"' sh "$ATUM_LIFECYCLE_LOCK_PATH" 2>/dev/null; then
        echo 'unprivileged Atum account opened the lifecycle lock' >&2
        exit 1
    fi
fi

flock -n 8
if php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --check > "$TEST_ROOT/concurrent-uninstall.out" 2>&1; then
    echo 'uninstaller acquired a live installer lifecycle lock' >&2
    exit 1
fi
grep -q 'Another Atum install or uninstall operation is already running' "$TEST_ROOT/concurrent-uninstall.out"
flock -u 8

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
export ATUM_WEB_CONFIG_TEST_BINARY="$TEST_ROOT/bin/php-fpm-test"
mkdir -p "$TEST_ROOT/remote" "$TEST_ROOT/host/nginx" "$TEST_ROOT/host/fpm" "$TEST_ROOT/host/run" "$TEST_ROOT/bin"
test_php_mm=$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')
printf '%s\n' '#!/bin/sh' "echo \"PHP $test_php_mm.0 (fpm-fcgi)\"" > "$ATUM_FPM_BINARY"
printf '%s\n' '#!/bin/sh' 'exit 0' > "$TEST_ROOT/bin/nginx"
printf '%s\n' '#!/bin/sh' 'printf "%s\n" "$*" >> "'"$TEST_ROOT"'/service-actions"' > "$ATUM_SERVICE_COMMAND"
chmod 0755 "$ATUM_FPM_BINARY" "$ATUM_SERVICE_COMMAND" "$TEST_ROOT/bin/nginx"
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
PATH="$TEST_ROOT/bin:$PATH" "$ROOT/install" --remote --allow-no-kamailio --no-deps --yes >"$TEST_ROOT/remote-no-development.out" 2>&1 && { echo 'remote mode accepted without --development' >&2; exit 1; }
PATH="$TEST_ROOT/bin:$PATH" "$ROOT/install" --development --remote --allow-no-kamailio --no-deps --yes > "$TEST_ROOT/remote-install.out"
grep -q 'Recovering interrupted Atum installation' "$TEST_ROOT/remote-install.out"
grep -q 'NOT SUITABLE FOR PRODUCTION' "$TEST_ROOT/remote-install.out"
grep -q 'No firewall rules were changed' "$TEST_ROOT/remote-install.out"
! grep -q '^Operating system :' "$TEST_ROOT/remote-install.out"
! grep -q 'PUBLIC_IP' "$TEST_ROOT/remote-install.out"
[ "$(grep -c '^Kamailio configuration remains unchanged\.$' "$TEST_ROOT/remote-install.out")" -eq 1 ]
grep -q '^Initial administrator: testadmin$' "$TEST_ROOT/remote-install.out"
! grep -q '^Username \[admin\]:' "$TEST_ROOT/remote-install.out"
previous_stage=0
for stage_label in \
    '[1/6] Checking host' \
    '[2/6] Installing system dependencies' \
    '[3/6] Creating Atum service account' \
    '[4/6] Installing Atum application files' \
    '[5/6] Configuring and validating PHP-FPM and remote HTTPS' \
    '[6/6] Creating initial administrator'; do
    stage_line=$(grep -nF "$stage_label" "$TEST_ROOT/remote-install.out" | cut -d: -f1)
    [ -n "$stage_line" ] && [ "$stage_line" -gt "$previous_stage" ]
    previous_stage=$stage_line
done
grep -q '^Open:$' "$TEST_ROOT/remote-install.out"
grep -q '^https://' "$TEST_ROOT/remote-install.out"
grep -q '"remote_development"' "$ATUM_CONFIG_DIR/install-ledger.json"
[ -f "$ATUM_NGINX_CONFIG_DIR/atum.conf" ] && [ -f "$ATUM_FPM_POOL_DIR/atum.conf" ]
[ "$(stat -c %a "$ATUM_CONFIG_DIR/tls/development.key")" = 600 ]
grep -q "$ATUM_PREFIX/public" "$ATUM_NGINX_CONFIG_DIR/atum.conf"
! grep -q "$ATUM_PREFIX/admin" "$ATUM_NGINX_CONFIG_DIR/atum.conf"
PATH="$TEST_ROOT/bin:$PATH" php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --check > "$TEST_ROOT/remote-uninstall-check.out"
grep -q "$ATUM_NGINX_CONFIG_DIR/atum.conf" "$TEST_ROOT/remote-uninstall-check.out"
atum_cli_target=$(readlink /usr/local/sbin/atum)
unlink /usr/local/sbin/atum
ln -s "$TEST_ROOT/not-atum" /usr/local/sbin/atum

if PATH="$TEST_ROOT/bin:$PATH" php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --check > "$TEST_ROOT/remote-uninstall-cli-conflict.out" 2>&1; then
    echo 'uninstall check accepted a changed Atum CLI link' >&2
    exit 1
fi

grep -q 'Refusing to remove changed symlink' "$TEST_ROOT/remote-uninstall-cli-conflict.out"
[ -f "$ATUM_CONFIG_DIR/tls/development.crt" ]
[ -f "$ATUM_FPM_POOL_DIR/atum.conf" ]
[ -f "$ATUM_NGINX_CONFIG_DIR/atum.conf" ]

unlink /usr/local/sbin/atum
ln -s "$atum_cli_target" /usr/local/sbin/atum
cp "$ATUM_NGINX_CONFIG_DIR/atum.conf" "$TEST_ROOT/expected-nginx.conf"
printf '%s\n' '# administrator-modified Atum vhost' > "$ATUM_NGINX_CONFIG_DIR/atum.conf"
if PATH="$TEST_ROOT/bin:$PATH" php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies > "$TEST_ROOT/remote-uninstall-conflict.out" 2>&1; then
    echo 'uninstall removed a changed Atum host integration' >&2
    exit 1
fi
grep -q 'changed after installation' "$TEST_ROOT/remote-uninstall-conflict.out"
[ -f "$ATUM_CONFIG_DIR/tls/development.crt" ] && [ -f "$ATUM_CONFIG_DIR/tls/development.key" ]
[ -f "$ATUM_FPM_POOL_DIR/atum.conf" ] && [ -f "$ATUM_NGINX_CONFIG_DIR/atum.conf" ]
cp "$TEST_ROOT/expected-nginx.conf" "$ATUM_NGINX_CONFIG_DIR/atum.conf"
PATH="$TEST_ROOT/bin:$PATH" php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies >/dev/null
[ ! -e "$ATUM_NGINX_CONFIG_DIR/atum.conf" ] && [ ! -e "$ATUM_FPM_POOL_DIR/atum.conf" ]
[ -f "$TEST_ROOT/host/nginx/operator.conf" ]
! grep -Eq '(^| )(ufw|firewall-cmd|iptables|nft)( |$)' "$TEST_ROOT/service-actions"

# A default-site link disabled by a successful new-server installation is an
# owned ledger change and is restored only during successful uninstall.
default_restore_root="$TEST_ROOT/default-site-restore"
mkdir -p "$default_restore_root/application" "$default_restore_root/state" "$default_restore_root/config" "$default_restore_root/nginx/sites-available" "$default_restore_root/nginx/sites-enabled"
default_restore_id=11111111111111111111111111111111
for directory in application state config; do printf '%s\n' "$default_restore_id" > "$default_restore_root/$directory/.atum-install-id"; done
printf '%s\n' 'package default site' > "$default_restore_root/nginx/sites-available/default"
cat > "$default_restore_root/config/install-ledger.json" <<EOF
{"schema":1,"install_id":"$default_restore_id","package_manager":"apt-get","packages_added":[],"paths":{"application":"$default_restore_root/application","state":"$default_restore_root/state","configuration":"$default_restore_root/config"},"system_account":{"user_created":false,"group_created":false},"kamailio":{"installer_modified":false},"host_integrations":{"services":[],"created_files":[],"modified_files":[],"disabled_default_sites":[{"path":"$default_restore_root/nginx/sites-enabled/default","target":"../sites-available/default"}],"reload_services":[]},"remote_development":null}
EOF
chmod 0600 "$default_restore_root/config/install-ledger.json"
php "$ROOT/uninstall.php" --config-dir="$default_restore_root/config" --yes --keep-dependencies >/dev/null
[ -L "$default_restore_root/nginx/sites-enabled/default" ]
[ "$(readlink "$default_restore_root/nginx/sites-enabled/default")" = ../sites-available/default ]

# Install from a genuine Git work tree containing repository metadata and
# arbitrary untracked files. The manifest alone defines the installed files.
CHECKOUT="$TEST_ROOT/github-checkout"
cp -a "$ROOT" "$CHECKOUT"
[ "$(git -c safe.directory="$CHECKOUT" -C "$CHECKOUT" rev-parse --is-inside-work-tree)" = true ]
mkdir "$CHECKOUT/operator-scratch"
printf '%s\n' 'must remain in checkout only' > "$CHECKOUT/operator-scratch/notes.txt"
printf '%s\n' '<?php throw new RuntimeException("must never be installed");' > "$CHECKOUT/local-secret.php"
checkout_status_before=$(git -c safe.directory="$CHECKOUT" -C "$CHECKOUT" status --porcelain=v1 --untracked-files=all)
checkout_head_before=$(git -c safe.directory="$CHECKOUT" -C "$CHECKOUT" rev-parse HEAD)
checkout_index_before=$(sha256sum "$CHECKOUT/.git/index" | awk '{print $1}')
scratch_before=$(sha256sum "$CHECKOUT/operator-scratch/notes.txt" "$CHECKOUT/local-secret.php")
export ATUM_PREFIX="$TEST_ROOT/checkout-install/application"
export ATUM_STATE_DIR="$TEST_ROOT/checkout-install/state"
export ATUM_CONFIG_DIR="$TEST_ROOT/checkout-install/configuration"
export ATUM_TRANSACTION_DIR="$TEST_ROOT/checkout-install/transaction"
mkdir -p "$TEST_ROOT/checkout-install"
"$CHECKOUT/install" --development --allow-no-kamailio --no-deps --yes > "$TEST_ROOT/checkout-install.out"
(sed '/^[[:space:]]*$/d' "$CHECKOUT/install-files.txt"; printf '%s\n' install-files.txt) | sort -u > "$TEST_ROOT/expected-installed-files"
find "$ATUM_PREFIX" -type f ! -name '.atum-install-id' ! -name '.atum-provisional-install-id' -printf '%P\n' | sort -u > "$TEST_ROOT/actual-installed-files"
cmp "$TEST_ROOT/expected-installed-files" "$TEST_ROOT/actual-installed-files"
[ ! -e "$ATUM_PREFIX/.git" ] && [ ! -e "$ATUM_PREFIX/local-secret.php" ] && [ ! -e "$ATUM_PREFIX/operator-scratch" ]
[ "$(git -c safe.directory="$CHECKOUT" -C "$CHECKOUT" status --porcelain=v1 --untracked-files=all)" = "$checkout_status_before" ]
[ "$(git -c safe.directory="$CHECKOUT" -C "$CHECKOUT" rev-parse HEAD)" = "$checkout_head_before" ]
[ "$(sha256sum "$CHECKOUT/.git/index" | awk '{print $1}')" = "$checkout_index_before" ]
[ "$(sha256sum "$CHECKOUT/operator-scratch/notes.txt" "$CHECKOUT/local-secret.php")" = "$scratch_before" ]
/usr/local/sbin/atum-uninstall --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies >/dev/null
[ ! -e "$ATUM_PREFIX" ] && [ ! -e "$ATUM_STATE_DIR" ] && [ ! -e "$ATUM_CONFIG_DIR" ]
[ -d "$CHECKOUT/.git" ] && [ -f "$CHECKOUT/operator-scratch/notes.txt" ] && [ -f "$CHECKOUT/local-secret.php" ]
[ "$(git -c safe.directory="$CHECKOUT" -C "$CHECKOUT" status --porcelain=v1 --untracked-files=all)" = "$checkout_status_before" ]
echo 'PASS  locking, interrupted recovery, remote and Git-checkout install/uninstall lifecycle'
