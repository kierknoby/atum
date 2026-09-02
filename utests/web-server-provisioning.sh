#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/atum-web-provisioning.XXXXXX")
cleanup() { rm -rf "$TEST_ROOT"; }
trap cleanup EXIT HUP INT TERM

# Keep fixture command discovery independent of optional software installed on
# the host. In particular, a real runner nginx/apache2/httpd must not turn a
# mocked "no existing web server" case into an ambiguous-host pre-flight.
SUPPORT_BIN="$TEST_ROOT/support-bin"
mkdir -p "$SUPPORT_BIN"
for utility in awk cat chmod comm date dirname env find flock head hostname ln \
    mkdir mktemp mv od readlink rm rmdir sed sha256sum sleep sort stat tr uname wc; do
    utility_path=$(command -v "$utility")
    [ -x "$utility_path" ] || { echo "required test utility is missing: $utility" >&2; exit 1; }
    ln -s "$utility_path" "$SUPPORT_BIN/$utility"
done

make_case() {
    name=$1
    server=$2
    existing=$3
    fpm_version=${4:-8.4}
    case_root="$TEST_ROOT/$name"
    mkdir -p "$case_root/bin" "$case_root/packages" "$case_root/nginx/sites-available" "$case_root/nginx/sites-enabled" "$case_root/apache/sites-available" "$case_root/apache/sites-enabled" "$case_root/fpm" "$case_root/run"
    chmod 0700 "$case_root"
    cat > "$case_root/bin/php" <<EOF
#!/bin/sh
case "\$1" in
    -r)
        case "\$2" in
            *PHP_VERSION*) echo 8.4.0 ;;
            *version_compare*|*extension_loaded*|*filter_var*) exit 0 ;;
            *PHP_MAJOR_VERSION*) echo 8.4 ;;
        esac
        ;;
    *) printf '%s\n' "\$@" > "$case_root/php-arguments" ;;
esac
EOF
    cat > "$case_root/bin/dpkg-query" <<EOF
#!/bin/sh
    case "\$*" in
        *db:Status-Status*)
            for argument in "\$@"; do package=\$argument; done
            grep -qx "\$package" "$case_root/packages/installed" 2>/dev/null && echo installed
            ;;
        *)
cat "$case_root/packages/installed" 2>/dev/null || true
            ;;
    esac
EOF
    cat > "$case_root/bin/apt-get" <<EOF
#!/bin/sh
[ "\$1" = update ] && exit 0
printf '%s\n' "\$@" >> "$case_root/apt.log"
printf 'mock package-manager stdout: %s\n' "\$*"
printf 'mock package-manager stderr: native warning\n' >&2
if [ -e "$case_root/fail-packages" ]; then
    echo 'mock package-manager fatal diagnostic' >&2
    exit 42
fi
for package in "\$@"; do
    case "\$package" in
        nginx)
            printf '%s\n' nginx >> "$case_root/packages/installed"
            printf '%s\n' nginx.service >> "$case_root/units-enabled"
            printf '%s\n' '#!/bin/sh' 'exit 0' > "$case_root/bin/nginx"; chmod 0755 "$case_root/bin/nginx"
            ln -s ../sites-available/default "$case_root/nginx/sites-enabled/default"
            ;;
        apache2)
            printf '%s\n' apache2 >> "$case_root/packages/installed"
            printf '%s\n' '#!/bin/sh' 'exit 0' > "$case_root/bin/apache2"; chmod 0755 "$case_root/bin/apache2"
            ln -s ../sites-available/000-default.conf "$case_root/apache/sites-enabled/000-default.conf"
            ;;
        php-fpm)
            printf '%s\n' php-fpm >> "$case_root/packages/installed"
            printf '%s\n' php8.4-fpm.service phpsessionclean.timer >> "$case_root/units-enabled"
            printf '%s\n' '#!/bin/sh' 'echo "PHP $fpm_version.0 (fpm-fcgi)"' > "$case_root/bin/php-fpm8.4"; chmod 0755 "$case_root/bin/php-fpm8.4"
            ;;
        php-*) printf '%s\n' "\$package" >> "$case_root/packages/installed" ;;
    esac
done
EOF
    ln -s apt-get "$case_root/bin/dnf"
    ln -s apt-get "$case_root/bin/yum"
    cat > "$case_root/bin/systemctl" <<EOF
#!/bin/sh
printf '%s\n' "\$*" >> "$case_root/services.log"
    case "\$1" in
        list-unit-files) cat "$case_root/units-enabled" 2>/dev/null || true ;;
        disable)
            shift
            [ "\$1" = --now ] && shift
            grep -vx "\$1" "$case_root/units-enabled" > "$case_root/units-enabled.tmp" 2>/dev/null || true
            mv "$case_root/units-enabled.tmp" "$case_root/units-enabled"
            ;;
        is-active|start|reload|unmask|mask) exit 0 ;;
    esac
EOF
    printf '%s\n' '#!/bin/sh' '[ "$1" = -u ] && { echo 0; exit 0; }' 'exit 1' > "$case_root/bin/id"
    printf '%s\n' '#!/bin/sh' 'exit 1' > "$case_root/bin/getent"
    for utility in groupadd useradd groupdel userdel; do printf '%s\n' '#!/bin/sh' 'exit 0' > "$case_root/bin/$utility"; chmod 0755 "$case_root/bin/$utility"; done
    printf '%s\n' '#!/bin/sh' 'case "$*" in *"^atum:"*) exit 1 ;; *) exec /usr/bin/grep "$@" ;; esac' > "$case_root/bin/grep"
    printf '%s\n' '#!/bin/sh' 'exit 0' > "$case_root/bin/openssl"
    chmod 0755 "$case_root/bin/apt-get" "$case_root/bin/dpkg-query" "$case_root/bin/systemctl" "$case_root/bin/php" "$case_root/bin/openssl" "$case_root/bin/id" "$case_root/bin/getent" "$case_root/bin/grep"
    if [ "$existing" = yes ]; then
        case "$server" in
            nginx)
                printf '%s\n' nginx > "$case_root/packages/installed"
                printf '%s\n' '#!/bin/sh' 'exit 0' > "$case_root/bin/nginx"; chmod 0755 "$case_root/bin/nginx"
                ln -s ../sites-available/default "$case_root/nginx/sites-enabled/default"
                ;;
            apache)
                printf '%s\n' apache2 > "$case_root/packages/installed"
                printf '%s\n' '#!/bin/sh' 'exit 0' > "$case_root/bin/apache2"; chmod 0755 "$case_root/bin/apache2"
                ln -s ../sites-available/000-default.conf "$case_root/apache/sites-enabled/000-default.conf"
                ;;
        esac
        printf '%s\n' php-fpm >> "$case_root/packages/installed"
        printf '%s\n' '#!/bin/sh' 'echo "PHP 8.4.0 (fpm-fcgi)"' > "$case_root/bin/php-fpm8.4"; chmod 0755 "$case_root/bin/php-fpm8.4"
    fi
    printf '%s\n' "$case_root"
}

run_install() {
    case_root=$1
    shift
    if PATH="$case_root/bin:$SUPPORT_BIN" \
        ATUM_PREFIX="$case_root/application" ATUM_STATE_DIR="$case_root/state" ATUM_CONFIG_DIR="$case_root/config" ATUM_TRANSACTION_DIR="$case_root/transaction" ATUM_LIFECYCLE_LOCK_PATH="$case_root/lock" \
        ATUM_NGINX_CONFIG_DIR="$case_root/nginx" ATUM_APACHE_CONFIG_DIR="$case_root/apache/sites-available" ATUM_APACHE_ENABLED_DIR="$case_root/apache/sites-enabled" ATUM_FPM_POOL_DIR="$case_root/fpm" ATUM_FPM_SOCKET="$case_root/run/atum.sock" \
        ATUM_NGINX_DEFAULT_SITE="$case_root/nginx/sites-enabled/default" ATUM_APACHE_DEFAULT_SITE="$case_root/apache/sites-enabled/000-default.conf" \
        ATUM_SERVICE_COMMAND="$case_root/bin/systemctl" ATUM_POLICY_RC_D="$case_root/policy-rc.d" \
        ATUM_PACKAGE_MANAGER="${ATUM_PACKAGE_MANAGER_OVERRIDE:-}" \
        "$ROOT/install" --development --remote --allow-no-kamailio "$@" > "$case_root/output" 2>&1; then
        INSTALL_STATUS=0
    else
        INSTALL_STATUS=$?
    fi
    return "$INSTALL_STATUS"
}

nginx_case=$(make_case nginx-new nginx no)
run_install "$nginx_case" --yes
grep -q php-fpm "$nginx_case/apt.log"
grep -q nginx "$nginx_case/apt.log"
! [ -e "$nginx_case/policy-rc.d" ]
! [ -L "$nginx_case/nginx/sites-enabled/default" ]
grep -q -- '--packages-added=.*nginx' "$nginx_case/php-arguments"
grep -q '^Atum 0.1 Development Preview$' "$nginx_case/output"
grep -q '^Preparing remote Atum installation$' "$nginx_case/output"
grep -q '^Web server: Nginx (will be installed)$' "$nginx_case/output"
grep -q '^Atum requires:$' "$nginx_case/output"
grep -q '^  Nginx web server$' "$nginx_case/output"
grep -q '^Install required packages? yes (--yes)$' "$nginx_case/output"
! grep -q '^Operating system :' "$nginx_case/output"
! grep -q 'Install required operating-system package' "$nginx_case/output"
grep -q 'mock package-manager stdout' "$nginx_case/output"
grep -q 'mock package-manager stderr' "$nginx_case/output"
! grep -q 'Working\.\.\.' "$nginx_case/output"
! grep -q 'PUBLIC_IP' "$nginx_case/output"
[ "$(grep -c '^Kamailio:      unchanged$' "$nginx_case/output")" -eq 1 ]
grep -q '^No firewall rules were changed\.' "$nginx_case/output"
grep -q '^The development certificate is self-signed\.$' "$nginx_case/output"
stage1=$(grep -n '^\[1/6\] Checking host$' "$nginx_case/output" | cut -d: -f1)
stage2=$(grep -n '^\[2/6\] Installing system dependencies$' "$nginx_case/output" | cut -d: -f1)
native_stdout=$(grep -n 'mock package-manager stdout' "$nginx_case/output" | cut -d: -f1)
native_stderr=$(grep -n 'mock package-manager stderr' "$nginx_case/output" | cut -d: -f1)
stage3=$(grep -n '^\[3/6\] Creating Atum service account$' "$nginx_case/output" | cut -d: -f1)
[ "$stage1" -lt "$stage2" ] && [ "$stage2" -lt "$native_stdout" ] && [ "$stage2" -lt "$native_stderr" ] && [ "$native_stdout" -lt "$stage3" ] && [ "$native_stderr" -lt "$stage3" ]

verbose_case=$(make_case verbose-output nginx no)
run_install "$verbose_case" --yes --verbose
grep -q '^Atum GUI installation pre-flight$' "$verbose_case/output"
grep -q '^Operating system :' "$verbose_case/output"
grep -q '^  Packages: .*nginx' "$verbose_case/output"
grep -q 'mock package-manager stdout' "$verbose_case/output"
grep -q 'mock package-manager stderr' "$verbose_case/output"
! grep -q 'Working\.\.\.' "$verbose_case/output"

package_failure_case=$(make_case package-failure nginx no)
touch "$package_failure_case/fail-packages"
if run_install "$package_failure_case" --yes; then echo 'package-manager failure was accepted' >&2; exit 1; fi
[ "$INSTALL_STATUS" -eq 1 ]
grep -q 'mock package-manager stdout' "$package_failure_case/output"
grep -q 'mock package-manager fatal diagnostic' "$package_failure_case/output"

installed_dependency_case=$(make_case installed-fpm nginx no)
printf '%s\n' php-fpm > "$installed_dependency_case/packages/installed"
if run_install "$installed_dependency_case" --yes; then echo 'fixture unexpectedly completed without PHP-FPM binary' >&2; exit 1; fi
! grep -qx php-fpm "$installed_dependency_case/apt.log"

apache_case=$(make_case apache-new apache no)
run_install "$apache_case" --yes --web-server=apache
! [ -L "$apache_case/apache/sites-enabled/000-default.conf" ]
grep -q apache2 "$apache_case/apt.log"

existing_case=$(make_case nginx-existing nginx yes)
run_install "$existing_case" --yes
! [ -e "$existing_case/apt.log" ]
[ -L "$existing_case/nginx/sites-enabled/default" ]
! grep -q -- '--packages-added=.*nginx' "$existing_case/php-arguments"

existing_apache_case=$(make_case apache-existing apache yes)
run_install "$existing_apache_case" --yes --web-server=apache
! [ -e "$existing_apache_case/apt.log" ]
[ -L "$existing_apache_case/apache/sites-enabled/000-default.conf" ]

rpm_missing_case=$(make_case rpm-missing nginx no)
if ATUM_PACKAGE_MANAGER_OVERRIDE=dnf run_install "$rpm_missing_case" --yes; then echo 'DNF unexpectedly provisioned a new web server' >&2; exit 1; fi
grep -q 'implemented only for APT-family systems' "$rpm_missing_case/output"
! [ -e "$rpm_missing_case/apt.log" ]

rpm_nginx_case=$(make_case rpm-nginx-existing nginx yes)
ATUM_PACKAGE_MANAGER_OVERRIDE=dnf run_install "$rpm_nginx_case" --yes
! [ -e "$rpm_nginx_case/apt.log" ]

rpm_apache_case=$(make_case rpm-apache-existing apache yes)
ATUM_PACKAGE_MANAGER_OVERRIDE=yum run_install "$rpm_apache_case" --yes --web-server=apache
! [ -e "$rpm_apache_case/apt.log" ]

no_deps_case=$(make_case no-deps nginx no)
if run_install "$no_deps_case" --no-deps --yes; then echo 'no-deps unexpectedly installed web prerequisites' >&2; exit 1; fi
! [ -e "$no_deps_case/apt.log" ]

mismatch_case=$(make_case fpm-mismatch nginx no 8.3)
if run_install "$mismatch_case" --yes; then echo 'mismatched PHP-FPM was accepted' >&2; exit 1; fi
grep -q 'matching PHP-FPM' "$mismatch_case/output"

failure_case=$(make_case default-site-failure nginx no)
cat > "$failure_case/bin/rm" <<EOF
#!/bin/sh
case "\$1" in
    "$failure_case/nginx/sites-enabled/default") exit 1 ;;
    *) exec /bin/rm "\$@" ;;
esac
EOF
chmod 0755 "$failure_case/bin/rm"
if run_install "$failure_case" --yes; then echo 'default-site removal failure was accepted' >&2; exit 1; fi
grep -q 'Unable to disable the package default web-server site' "$failure_case/output"
! [ -e "$failure_case/policy-rc.d" ]
[ -L "$failure_case/nginx/sites-enabled/default" ]
grep -q '^disable --now nginx.service$' "$failure_case/services.log"
grep -q '^disable --now php8.4-fpm.service$' "$failure_case/services.log"
grep -q '^disable --now phpsessionclean.timer$' "$failure_case/services.log"

echo 'PASS  mocked web-server provisioning, default-site safety, reuse, accounting and FPM mismatch'
