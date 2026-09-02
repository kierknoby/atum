#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu
[ "$(id -u)" -eq 0 ] || { echo 'system lifecycle test requires root' >&2; exit 1; }
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TEST_ROOT=$(mktemp -d /tmp/atum-system-test.XXXXXX)
chmod 0711 "$TEST_ROOT"
export ATUM_PREFIX="$TEST_ROOT/application"
export ATUM_STATE_DIR="$TEST_ROOT/state"
export ATUM_CONFIG_DIR="$TEST_ROOT/configuration"
export ATUM_TRANSACTION_DIR="$TEST_ROOT/transaction"
export ATUM_LIFECYCLE_LOCK_PATH="$TEST_ROOT/lifecycle-lock"
export ATUM_ADMIN_USER=testadmin
export ATUM_ADMIN_PASSWORD_FILE="$TEST_ROOT/password"
HTTP_SERVER_PID=
cleanup() { [ -z "$HTTP_SERVER_PID" ] || kill "$HTTP_SERVER_PID" 2>/dev/null || true; if [ -f "$ATUM_CONFIG_DIR/install-ledger.json" ]; then php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies >/dev/null 2>&1 || true; fi; [ ! -L /usr/local/sbin/atum ] || [ "$(readlink /usr/local/sbin/atum)" != "$ATUM_PREFIX/bin/atum" ] || unlink /usr/local/sbin/atum; [ ! -L /usr/local/sbin/atum ] || [ "$(readlink /usr/local/sbin/atum)" != "$TEST_ROOT/default-site-restore/not-atum" ] || unlink /usr/local/sbin/atum; [ ! -L /usr/local/sbin/atum-uninstall ] || [ "$(readlink /usr/local/sbin/atum-uninstall)" != "$ATUM_PREFIX/uninstall.php" ] || unlink /usr/local/sbin/atum-uninstall; rm -rf "$TEST_ROOT"; }
trap cleanup EXIT HUP INT TERM
printf '%s\n' 'Lifecycle test password 123' > "$ATUM_ADMIN_PASSWORD_FILE"
chmod 0600 "$ATUM_ADMIN_PASSWORD_FILE"
install_id=0123456789abcdef0123456789abcdef
PHP_BINARY=$(command -v php)
HTTP_PORT=18092

assert_mode() {
    [ "$(stat -c %a "$1")" = "$2" ] || { echo "unexpected mode for $1: $(stat -c %a "$1"), expected $2" >&2; exit 1; }
}

assert_install_permissions() {
    assert_mode "$ATUM_PREFIX" 755
    find "$ATUM_PREFIX" -type d ! -perm 0755 -print -quit | grep -q . && { echo 'application directory mode is not 0755' >&2; exit 1; }
    while IFS= read -r relative; do
        [ -n "$relative" ] || continue
        case "$relative" in bin/atum|install|install.php|uninstall|uninstall.php) expected=755 ;; *) expected=644 ;; esac
        assert_mode "$ATUM_PREFIX/$relative" "$expected"
    done < "$ROOT/install-files.txt"
    assert_mode "$ATUM_PREFIX/install-files.txt" 644
    assert_mode "$ATUM_PREFIX/.atum-install-id" 600
    [ "$(stat -c %U:%G "$ATUM_PREFIX")" = root:root ]

    assert_mode "$ATUM_STATE_DIR" 700
    find "$ATUM_STATE_DIR" -type d ! -perm 0700 -print -quit | grep -q . && { echo 'state directory mode is not 0700' >&2; exit 1; }
    find "$ATUM_STATE_DIR" -type f ! -perm 0600 -print -quit | grep -q . && { echo 'state file mode is not 0600' >&2; exit 1; }
    [ "$(stat -c %U:%G "$ATUM_STATE_DIR")" = atum:atum ]

    assert_mode "$ATUM_CONFIG_DIR" 750
    assert_mode "$ATUM_CONFIG_DIR/atum.conf" 640
    assert_mode "$ATUM_CONFIG_DIR/install-ledger.json" 600
    assert_mode "$ATUM_CONFIG_DIR/.atum-install-id" 600
    [ "$(stat -c %U:%G "$ATUM_CONFIG_DIR")" = root:atum ]
    [ "$(stat -c %U:%G "$ATUM_CONFIG_DIR/atum.conf")" = root:atum ]
    [ "$(stat -c %U:%G "$ATUM_CONFIG_DIR/install-ledger.json")" = root:root ]
}

assert_web_access_and_http() {
    id www-data >/dev/null 2>&1 || { echo 'www-data identity is required for permission regression' >&2; exit 1; }
    runuser -u www-data -- test -x "$ATUM_PREFIX"
    runuser -u www-data -- test -x "$ATUM_PREFIX/public"
    runuser -u www-data -- test -r "$ATUM_PREFIX/public/index.php"
    runuser -u www-data -- test -r "$ATUM_PREFIX/public/assets/atum.css"
    if runuser -u www-data -- test -r "$ATUM_CONFIG_DIR/atum.conf"; then echo 'web-server identity can read Atum configuration' >&2; exit 1; fi
    if runuser -u www-data -- test -r "$ATUM_CONFIG_DIR/install-ledger.json"; then echo 'web-server identity can read the ownership ledger' >&2; exit 1; fi
    if runuser -u www-data -- test -r "$ATUM_STATE_DIR/atum.sqlite"; then echo 'web-server identity can read Atum state' >&2; exit 1; fi

    # Model the deployed identity split: the web-server checks above resolve the
    # public path, while the HTTP application process runs as the Atum FPM user.
    runuser -u atum -- env ATUM_CONFIG_DIR="$ATUM_CONFIG_DIR" ATUM_STATE_DIR="$ATUM_STATE_DIR" ATUM_REQUIRE_HTTPS=false \
        "$PHP_BINARY" -S "127.0.0.1:$HTTP_PORT" -t "$ATUM_PREFIX/public" > "$TEST_ROOT/installed-http-$HTTP_PORT.log" 2>&1 &
    HTTP_SERVER_PID=$!
    attempt=0
    http_code=000
    while [ "$attempt" -lt 20 ]; do
        http_code=$(curl -sS -o "$TEST_ROOT/installed-http-$HTTP_PORT.body" -w '%{http_code}' "http://127.0.0.1:$HTTP_PORT/index.php" 2>/dev/null || true)
        [ "$http_code" = 200 ] && break
        attempt=$((attempt + 1))
        sleep 1
    done
    [ "$http_code" = 200 ]
    grep -q 'Sign in' "$TEST_ROOT/installed-http-$HTTP_PORT.body"
    kill "$HTTP_SERVER_PID"
    wait "$HTTP_SERVER_PID" 2>/dev/null || true
    HTTP_SERVER_PID=
    HTTP_PORT=$((HTTP_PORT + 1))
}

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
(umask 022; "$ROOT/install" --development --allow-no-kamailio --no-deps --yes)
[ ! -e "$partial_host_file" ]
[ -f "$ATUM_CONFIG_DIR/install-ledger.json" ] && [ ! -d "$ATUM_TRANSACTION_DIR" ]
grep -q 'fake-atum-dependency' "$ATUM_CONFIG_DIR/install-ledger.json"
assert_install_permissions

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
printf '%s\n' '#!/bin/sh' \
    'printf "%s\n" "$*" >> "'"$TEST_ROOT"'/service-actions"' \
    'case "$1" in' \
    '  is-active) [ -f "'"$TEST_ROOT"'/service-active-$2" ] || exit 3 ;;' \
    '  start) : > "'"$TEST_ROOT"'/service-active-$2" ;;' \
    '  reload) [ -f "'"$TEST_ROOT"'/service-active-$2" ] ;;' \
    '  stop) rm -f "'"$TEST_ROOT"'/service-active-$2" ;;' \
    '  disable) rm -f "'"$TEST_ROOT"'/service-active-$3" ;;' \
    'esac' > "$ATUM_SERVICE_COMMAND"
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
printf '%s\n%s\n' php-test-fpm inactive > "$ATUM_TRANSACTION_DIR/service-state-1"
printf '%s\n' php-test-fpm > "$ATUM_TRANSACTION_DIR/package-enabled-unit-1"
PATH="$TEST_ROOT/bin:$PATH" "$ROOT/install" --remote --allow-no-kamailio --no-deps --yes >"$TEST_ROOT/remote-no-development.out" 2>&1 && { echo 'remote mode accepted without --development' >&2; exit 1; }
PATH="$TEST_ROOT/bin:$PATH" "$ROOT/install" --development --remote --allow-no-kamailio --no-deps --yes > "$TEST_ROOT/remote-install.out"
grep -q 'Recovering interrupted Atum installation' "$TEST_ROOT/remote-install.out"
grep -q 'NOT SUITABLE FOR PRODUCTION' "$TEST_ROOT/remote-install.out"
grep -q 'No firewall rules were changed' "$TEST_ROOT/remote-install.out"
! grep -q '^Operating system :' "$TEST_ROOT/remote-install.out"
! grep -q 'PUBLIC_IP' "$TEST_ROOT/remote-install.out"
[ "$(grep -c '^Kamailio:      unchanged$' "$TEST_ROOT/remote-install.out")" -eq 1 ]
grep -q '^Administrator: testadmin$' "$TEST_ROOT/remote-install.out"
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
grep -q '^                 ATUM INSTALLATION COMPLETE$' "$TEST_ROOT/remote-install.out"
grep -q '^OPEN ATUM:$' "$TEST_ROOT/remote-install.out"
grep -q '^  https://' "$TEST_ROOT/remote-install.out"
grep -q '^  atum-uninstall --check$' "$TEST_ROOT/remote-install.out"
grep -q '"remote_development"' "$ATUM_CONFIG_DIR/install-ledger.json"
grep -q '"service_states"' "$ATUM_CONFIG_DIR/install-ledger.json"
[ -f "$TEST_ROOT/service-active-php-test-fpm" ] && [ -f "$TEST_ROOT/service-active-nginx-test" ]
grep -q '^disable --now php-test-fpm$' "$TEST_ROOT/service-actions"
grep -q '^start php-test-fpm$' "$TEST_ROOT/service-actions"
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
[ ! -e "$TEST_ROOT/service-active-php-test-fpm" ] && [ ! -e "$TEST_ROOT/service-active-nginx-test" ]
! grep -Eq '(^| )(ufw|firewall-cmd|iptables|nft)( |$)' "$TEST_ROOT/service-actions"

# Reproduce the live KAM0 ledger sequence: a disabled default site and correct
# global CLI links must coexist without changing the application path used by
# either preflight or final tree removal.
default_restore_root="$TEST_ROOT/default-site-restore"
export ATUM_PREFIX="$default_restore_root/application"
export ATUM_STATE_DIR="$default_restore_root/state"
export ATUM_CONFIG_DIR="$default_restore_root/config"
mkdir -p "$ATUM_PREFIX/bin" "$ATUM_STATE_DIR" "$ATUM_CONFIG_DIR" \
    "$default_restore_root/nginx/sites-available" \
    "$default_restore_root/nginx/sites-enabled" \
    "$default_restore_root/work" \
    "$default_restore_root/sites-available/default"
default_restore_id=11111111111111111111111111111111
for directory in application state config; do printf '%s\n' "$default_restore_id" > "$default_restore_root/$directory/.atum-install-id"; done
printf '%s\n' 'package default site' > "$default_restore_root/nginx/sites-available/default"
printf '%s\n' 'must never be selected as the application tree' > "$default_restore_root/sites-available/default/sentinel"
printf '%s\n' '#!/bin/sh' 'exit 0' > "$ATUM_PREFIX/bin/atum"
printf '%s\n' '<?php' > "$ATUM_PREFIX/uninstall.php"
cat > "$default_restore_root/config/install-ledger.json" <<EOF
{"schema":1,"install_id":"$default_restore_id","package_manager":"apt-get","packages_added":[],"paths":{"application":"$default_restore_root/application","state":"$default_restore_root/state","configuration":"$default_restore_root/config"},"system_account":{"user_created":false,"group_created":false},"kamailio":{"installer_modified":false},"host_integrations":{"services":[],"created_files":[],"modified_files":[],"disabled_default_sites":[{"path":"$default_restore_root/nginx/sites-enabled/default","target":"../sites-available/default"}],"reload_services":[]},"remote_development":null}
EOF
chmod 0600 "$default_restore_root/config/install-ledger.json"
ln -s "$ATUM_PREFIX/bin/atum" /usr/local/sbin/atum
ln -s "$ATUM_PREFIX/uninstall.php" /usr/local/sbin/atum-uninstall

(cd "$default_restore_root/work" && php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --check) > "$default_restore_root/check.out"
grep -q "^Application     : $ATUM_PREFIX$" "$default_restore_root/check.out"

# The disabled-site record must not weaken the existing changed-link refusal.
unlink /usr/local/sbin/atum
ln -s "$default_restore_root/not-atum" /usr/local/sbin/atum
if (cd "$default_restore_root/work" && php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --check) > "$default_restore_root/changed-link.out" 2>&1; then
    echo 'uninstall check accepted a changed Atum CLI link with a disabled default-site record' >&2
    exit 1
fi
grep -q 'Refusing to remove changed symlink' "$default_restore_root/changed-link.out"
[ -d "$ATUM_PREFIX" ] && [ -d "$ATUM_STATE_DIR" ] && [ -d "$ATUM_CONFIG_DIR" ]
[ -f "$default_restore_root/sites-available/default/sentinel" ]

unlink /usr/local/sbin/atum
ln -s "$ATUM_PREFIX/bin/atum" /usr/local/sbin/atum
(cd "$default_restore_root/work" && php "$ROOT/uninstall.php" --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies) >/dev/null
[ -L "$default_restore_root/nginx/sites-enabled/default" ]
[ "$(readlink "$default_restore_root/nginx/sites-enabled/default")" = ../sites-available/default ]
[ ! -e "$ATUM_PREFIX" ] && [ ! -e "$ATUM_STATE_DIR" ] && [ ! -e "$ATUM_CONFIG_DIR" ]
[ ! -e /usr/local/sbin/atum ] && [ ! -e /usr/local/sbin/atum-uninstall ]
[ -f "$default_restore_root/sites-available/default/sentinel" ]

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
chmod 0755 "$TEST_ROOT/checkout-install"
(umask 077; "$CHECKOUT/install" --development --allow-no-kamailio --no-deps --yes) > "$TEST_ROOT/checkout-install.out"
(sed '/^[[:space:]]*$/d' "$CHECKOUT/install-files.txt"; printf '%s\n' install-files.txt) | sort -u > "$TEST_ROOT/expected-installed-files"
find "$ATUM_PREFIX" -type f ! -name '.atum-install-id' ! -name '.atum-provisional-install-id' -printf '%P\n' | sort -u > "$TEST_ROOT/actual-installed-files"
cmp "$TEST_ROOT/expected-installed-files" "$TEST_ROOT/actual-installed-files"
[ ! -e "$ATUM_PREFIX/.git" ] && [ ! -e "$ATUM_PREFIX/local-secret.php" ] && [ ! -e "$ATUM_PREFIX/operator-scratch" ]
[ "$(git -c safe.directory="$CHECKOUT" -C "$CHECKOUT" status --porcelain=v1 --untracked-files=all)" = "$checkout_status_before" ]
[ "$(git -c safe.directory="$CHECKOUT" -C "$CHECKOUT" rev-parse HEAD)" = "$checkout_head_before" ]
[ "$(sha256sum "$CHECKOUT/.git/index" | awk '{print $1}')" = "$checkout_index_before" ]
[ "$(sha256sum "$CHECKOUT/operator-scratch/notes.txt" "$CHECKOUT/local-secret.php")" = "$scratch_before" ]
assert_install_permissions
assert_web_access_and_http
/usr/local/sbin/atum-uninstall --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies >/dev/null
[ ! -e "$ATUM_PREFIX" ] && [ ! -e "$ATUM_STATE_DIR" ] && [ ! -e "$ATUM_CONFIG_DIR" ]
[ -d "$CHECKOUT/.git" ] && [ -f "$CHECKOUT/operator-scratch/notes.txt" ] && [ -f "$CHECKOUT/local-secret.php" ]
[ "$(git -c safe.directory="$CHECKOUT" -C "$CHECKOUT" status --porcelain=v1 --untracked-files=all)" = "$checkout_status_before" ]

# Exercise the live bootstrap/installer boundary with the caller itself at 077.
# A local Git fixture supplies this exact work tree while retaining bootstrap's
# clone verification and cleanup flow, avoiding any mutable network dependency.
bootstrap_root="$TEST_ROOT/bootstrap-install"
bootstrap_bin="$bootstrap_root/bin"
mkdir -p "$bootstrap_bin" "$bootstrap_root/work"
chmod 0755 "$bootstrap_root"
cat > "$bootstrap_bin/git" <<'EOF'
#!/bin/sh
if [ "${1:-}" = -c ]; then shift 2; fi
if [ "${1:-}" = -C ]; then shift 2; fi
operation=${1:-}
shift || true
case "$operation" in
    clone)
        destination=
        for argument in "$@"; do destination=$argument; done
        /bin/mkdir -m 0700 "$destination"
        /bin/cp -a "$ATUM_BOOTSTRAP_SOURCE/." "$destination/"
        /bin/chmod 0700 "$destination"
        /usr/bin/stat -c %a "${destination%/source}" > "$ATUM_BOOTSTRAP_RECORD/checkout.mode"
        ;;
    rev-parse) printf '%s\n' 0123456789abcdef0123456789abcdef01234567 ;;
    diff|status) exit 0 ;;
    *) exit 90 ;;
esac
EOF
chmod 0755 "$bootstrap_bin/git"
export ATUM_PREFIX="$bootstrap_root/application"
export ATUM_STATE_DIR="$bootstrap_root/state"
export ATUM_CONFIG_DIR="$bootstrap_root/configuration"
export ATUM_TRANSACTION_DIR="$bootstrap_root/transaction"
bootstrap_tmp="$bootstrap_root/tmp"
mkdir "$bootstrap_tmp"
(
    umask 077
    PATH="$bootstrap_bin:$TEST_ROOT/bin:$PATH" \
        TMPDIR="$bootstrap_tmp" \
        ATUM_BOOTSTRAP_SOURCE="$ROOT" \
        ATUM_BOOTSTRAP_RECORD="$bootstrap_root" \
        "$ROOT/bootstrap" --allow-no-kamailio --no-deps --yes
) > "$bootstrap_root/bootstrap.out"
[ "$(cat "$bootstrap_root/checkout.mode")" = 700 ]
[ -z "$(find "$bootstrap_tmp" -mindepth 1 -maxdepth 1 -print)" ]
assert_install_permissions
assert_mode "$ATUM_CONFIG_DIR/tls" 750
assert_mode "$ATUM_CONFIG_DIR/tls/development.crt" 644
assert_mode "$ATUM_CONFIG_DIR/tls/development.key" 600
[ "$(stat -c %U:%G "$ATUM_CONFIG_DIR/tls")" = root:root ]
[ "$(stat -c %U:%G "$ATUM_CONFIG_DIR/tls/development.crt")" = root:root ]
[ "$(stat -c %U:%G "$ATUM_CONFIG_DIR/tls/development.key")" = root:root ]
assert_web_access_and_http
PATH="$TEST_ROOT/bin:$PATH" /usr/local/sbin/atum-uninstall --config-dir="$ATUM_CONFIG_DIR" --yes --keep-dependencies >/dev/null
[ ! -e "$ATUM_PREFIX" ] && [ ! -e "$ATUM_STATE_DIR" ] && [ ! -e "$ATUM_CONFIG_DIR" ]

echo 'PASS  locking, recovery, deterministic permissions, restrictive-umask bootstrap/direct install, HTTP and uninstall lifecycle'
