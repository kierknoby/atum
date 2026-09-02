#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
TEST_ROOT=$(mktemp -d /tmp/atum-bootstrap-test.XXXXXX)
PERL=$(command -v perl)
SH=$(command -v sh)
FIXED_COMMIT=0123456789abcdef0123456789abcdef01234567
CASE_ROOT=

cleanup() {
    status=$?
    trap - EXIT
    case "$TEST_ROOT" in
        /tmp/atum-bootstrap-test.*) rm -rf -- "$TEST_ROOT" ;;
        *) printf 'Refusing to clean unexpected test path: %s\n' "$TEST_ROOT" >&2 ;;
    esac
    exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

new_case() {
    name=$1
    CASE_ROOT="$TEST_ROOT/$name"
    mkdir -p "$CASE_ROOT/bin" "$CASE_ROOT/tmp with spaces"
    printf 'firewall-state-must-not-change\n' > "$CASE_ROOT/firewall.before"
    cp "$CASE_ROOT/firewall.before" "$CASE_ROOT/firewall.after"

    cat > "$CASE_ROOT/bin/id" <<'EOF'
#!/bin/sh
[ "${1:-}" = -u ] || exit 2
printf '0\n'
EOF
    chmod 0755 "$CASE_ROOT/bin/id"
    for utility in mktemp rm; do
        utility_path=$(command -v "$utility")
        ln -s "$utility_path" "$CASE_ROOT/bin/$utility"
    done

    cat > "$CASE_ROOT/installer" <<'EOF'
#!/bin/sh
printf 'invoked\n' >> "$ATUM_BOOTSTRAP_TEST_ROOT/installer.count"
printf '%s\n' "$@" > "$ATUM_BOOTSTRAP_TEST_ROOT/installer.args"
umask > "$ATUM_BOOTSTRAP_TEST_ROOT/installer.umask"
source_dir=${0%/*}
/usr/bin/stat -c %a "${source_dir%/*}" > "$ATUM_BOOTSTRAP_TEST_ROOT/checkout.mode"
if [ -f "$ATUM_BOOTSTRAP_TEST_ROOT/installer.wait" ]; then
    trap '/bin/rm -f "$ATUM_BOOTSTRAP_TEST_ROOT/installer.live"; exit 129' HUP
    trap '/bin/rm -f "$ATUM_BOOTSTRAP_TEST_ROOT/installer.live"; exit 130' INT
    trap '/bin/rm -f "$ATUM_BOOTSTRAP_TEST_ROOT/installer.live"; exit 143' TERM
    printf '%s\n' "$$" > "$ATUM_BOOTSTRAP_TEST_ROOT/installer.ready"
    : > "$ATUM_BOOTSTRAP_TEST_ROOT/installer.live"
    while :; do /bin/sleep 1; done
fi
if [ -f "$ATUM_BOOTSTRAP_TEST_ROOT/installer.fail" ]; then
    IFS= read -r installer_status < "$ATUM_BOOTSTRAP_TEST_ROOT/installer.fail"
    exit "$installer_status"
fi
EOF
    chmod 0755 "$CASE_ROOT/installer"

    cat > "$CASE_ROOT/git-command" <<'EOF'
#!/bin/sh
printf '<%s>\n' "$@" >> "$ATUM_BOOTSTRAP_TEST_ROOT/git.args"
if [ "${1:-}" = -c ]; then
    shift 2
fi
if [ "${1:-}" = -C ]; then
    shift 2
fi
git_operation=${1:-}
shift || true
case "$git_operation" in
    clone)
        clone_destination=
        for clone_argument in "$@"; do clone_destination=$clone_argument; done
        printf '%s\n' "$clone_destination" > "$ATUM_BOOTSTRAP_TEST_ROOT/clone.path"
        /bin/mkdir -p "$clone_destination/.git"
        if [ -f "$ATUM_BOOTSTRAP_TEST_ROOT/clone.fail" ]; then
            exit 41
        fi
        /bin/cp "$ATUM_BOOTSTRAP_TEST_ROOT/installer" "$clone_destination/install"
        /bin/chmod 0755 "$clone_destination/install"
        ;;
    rev-parse)
        printf '%s\n' 0123456789abcdef0123456789abcdef01234567
        ;;
    diff)
        [ ! -f "$ATUM_BOOTSTRAP_TEST_ROOT/diff.fail" ]
        ;;
    status)
        if [ -f "$ATUM_BOOTSTRAP_TEST_ROOT/status.dirty" ]; then
            printf ' M install\n'
        fi
        ;;
    *)
        printf 'unexpected mock git operation: %s\n' "$git_operation" >&2
        exit 90
        ;;
esac
EOF
    chmod 0755 "$CASE_ROOT/git-command"

    cat > "$CASE_ROOT/bin/apt-get" <<'EOF'
#!/bin/sh
printf '%s\n' "$@" > "$ATUM_BOOTSTRAP_TEST_ROOT/package.args"
printf 'mock package-manager stdout\n'
printf 'mock package-manager stderr\n' >&2
if [ -f "$ATUM_BOOTSTRAP_TEST_ROOT/package.fail" ]; then
    exit 42
fi
/bin/ln -s "$ATUM_BOOTSTRAP_TEST_ROOT/git-command" "$ATUM_BOOTSTRAP_TEST_ROOT/bin/git"
EOF
    chmod 0755 "$CASE_ROOT/bin/apt-get"

    cat > "$CASE_ROOT/firewall-command" <<'EOF'
#!/bin/sh
: > "$ATUM_BOOTSTRAP_TEST_ROOT/firewall.called"
exit 99
EOF
    chmod 0755 "$CASE_ROOT/firewall-command"
    for firewall_command in ufw firewall-cmd iptables nft; do
        ln -s "$CASE_ROOT/firewall-command" "$CASE_ROOT/bin/$firewall_command"
    done
}

enable_git() {
    ln -s "$CASE_ROOT/git-command" "$CASE_ROOT/bin/git"
}

run_bootstrap() {
    if env -i \
        PATH="$CASE_ROOT/bin" \
        TMPDIR="$CASE_ROOT/tmp with spaces" \
        ATUM_BOOTSTRAP_TEST_ROOT="$CASE_ROOT" \
        "$ROOT/bootstrap" "$@" > "$CASE_ROOT/stdout" 2> "$CASE_ROOT/stderr"
    then
        BOOTSTRAP_STATUS=0
    else
        BOOTSTRAP_STATUS=$?
    fi
}

run_bootstrap_stdin() {
    if env -i \
        PATH="$CASE_ROOT/bin" \
        TMPDIR="$CASE_ROOT/tmp with spaces" \
        ATUM_BOOTSTRAP_TEST_ROOT="$CASE_ROOT" \
        "$SH" -s -- "$@" < "$ROOT/bootstrap" \
        > "$CASE_ROOT/stdout" 2> "$CASE_ROOT/stderr"
    then
        BOOTSTRAP_STATUS=0
    else
        BOOTSTRAP_STATUS=$?
    fi
}

assert_no_checkout() {
    clone_path=$(cat "$CASE_ROOT/clone.path")
    [ ! -e "$clone_path" ]
    [ ! -e "${clone_path%/source}" ]
}

assert_private_checkout() {
    [ "$(cat "$CASE_ROOT/checkout.mode")" = 700 ]
}

assert_not_invoked() {
    [ ! -e "$CASE_ROOT/installer.count" ]
}

assert_firewall_unchanged() {
    cmp -s "$CASE_ROOT/firewall.before" "$CASE_ROOT/firewall.after"
    [ ! -e "$CASE_ROOT/firewall.called" ]
}

# A streamed shell may parse and execute complete top-level commands before
# reaching EOF. Every cut before the sole final invocation therefore must be
# inert, including a valid file containing the complete function definition.
invocation_line=$(awk '$0 == "atum_bootstrap \"$@\"" { print NR }' "$ROOT/bootstrap")
[ -n "$invocation_line" ]
[ "$invocation_line" -eq "$(wc -l < "$ROOT/bootstrap")" ]
truncation_middle=$((invocation_line / 2))
for truncation_line in 1 "$truncation_middle" $((invocation_line - 2)) $((invocation_line - 1)); do
    new_case "truncated-$truncation_line"
    awk -v last="$truncation_line" 'NR <= last' "$ROOT/bootstrap" > "$CASE_ROOT/partial-bootstrap"
    if env -i \
        PATH="$CASE_ROOT/bin" \
        TMPDIR="$CASE_ROOT/tmp with spaces" \
        ATUM_BOOTSTRAP_TEST_ROOT="$CASE_ROOT" \
        "$SH" -s -- --yes < "$CASE_ROOT/partial-bootstrap" \
        > "$CASE_ROOT/stdout" 2> "$CASE_ROOT/stderr"
    then
        :
    fi
    [ ! -e "$CASE_ROOT/git.args" ]
    [ ! -e "$CASE_ROOT/package.args" ]
    [ ! -e "$CASE_ROOT/clone.path" ]
    assert_not_invoked
    assert_firewall_unchanged
    [ -z "$(find "$CASE_ROOT/tmp with spaces" -mindepth 1 -maxdepth 1 -print)" ]
done

# Existing Git is reused, the exact commit is displayed, defaults precede all
# stdin caller arguments, and spaces/metacharacters remain single arguments.
new_case existing-git
enable_git
expected_installer_umask=$(umask)
run_bootstrap_stdin --yes --verbose '--prefix=/tmp/Atum source; literal' '--listen-address=2001:db8::1'
[ "$BOOTSTRAP_STATUS" -eq 0 ]
grep -qx 'Atum source: https://github.com/kierknoby/atum.git' "$CASE_ROOT/stdout"
grep -qx "Commit: $FIXED_COMMIT" "$CASE_ROOT/stdout"
[ ! -e "$CASE_ROOT/package.args" ]
[ "$(wc -l < "$CASE_ROOT/installer.count")" -eq 1 ]
cat > "$CASE_ROOT/installer.args.expected" <<'EOF'
--development
--remote
--yes
--verbose
--prefix=/tmp/Atum source; literal
--listen-address=2001:db8::1
EOF
cmp -s "$CASE_ROOT/installer.args.expected" "$CASE_ROOT/installer.args"
[ "$(cat "$CASE_ROOT/installer.umask")" = "$expected_installer_umask" ]
assert_private_checkout
assert_no_checkout
assert_firewall_unchanged

# Bootstrap's checkout policy stays private, while the installer sees exactly
# the caller's original umask rather than an unconditional leaked 077.
new_case restored-umask
enable_git
original_test_umask=$(umask)
umask 027
expected_installer_umask=$(umask)
run_bootstrap --yes
umask "$original_test_umask"
[ "$BOOTSTRAP_STATUS" -eq 0 ]
[ "$(cat "$CASE_ROOT/installer.umask")" = "$expected_installer_umask" ]
assert_private_checkout
assert_no_checkout
assert_firewall_unchanged

# If the caller itself selected 077, restoring that value is still correct; the
# installer must independently establish every deployment-critical mode.
new_case restrictive-caller-umask
enable_git
original_test_umask=$(umask)
umask 077
expected_installer_umask=$(umask)
run_bootstrap --yes
umask "$original_test_umask"
[ "$BOOTSTRAP_STATUS" -eq 0 ]
[ "$(cat "$CASE_ROOT/installer.umask")" = "$expected_installer_umask" ]
assert_private_checkout
assert_no_checkout
assert_firewall_unchanged

# Missing Git is installed once through the detected native manager when
# --yes permits it, and --yes is still forwarded to the real installer.
new_case install-git
run_bootstrap --yes
[ "$BOOTSTRAP_STATUS" -eq 0 ]
cat > "$CASE_ROOT/package.args.expected" <<'EOF'
install
-y
--no-install-recommends
git
EOF
cmp -s "$CASE_ROOT/package.args.expected" "$CASE_ROOT/package.args"
grep -qx 'mock package-manager stdout' "$CASE_ROOT/stdout"
grep -qx 'mock package-manager stderr' "$CASE_ROOT/stderr"
! grep -q 'Working\.\.\.' "$CASE_ROOT/stdout"
! grep -q 'Working\.\.\.' "$CASE_ROOT/stderr"
[ "$(wc -l < "$CASE_ROOT/installer.count")" -eq 1 ]
grep -qx -- '--yes' "$CASE_ROOT/installer.args"
assert_no_checkout
assert_firewall_unchanged

# --no-deps blocks bootstrapping Git and is not silently overridden by --yes.
new_case no-deps
run_bootstrap --no-deps --yes
[ "$BOOTSTRAP_STATUS" -ne 0 ]
assert_not_invoked
[ ! -e "$CASE_ROOT/package.args" ]
grep -q -- '--no-deps forbids installing it' "$CASE_ROOT/stderr"
assert_firewall_unchanged

# Package-manager, clone, verification and installer failures all prevent or
# preserve installer status as appropriate, and every partial checkout is gone.
new_case package-failure
: > "$CASE_ROOT/package.fail"
run_bootstrap --yes
[ "$BOOTSTRAP_STATUS" -ne 0 ]
assert_not_invoked
grep -q 'mock package-manager stdout' "$CASE_ROOT/stdout"
grep -q 'mock package-manager stderr' "$CASE_ROOT/stderr"
grep -q 'native package manager could not install Git' "$CASE_ROOT/stderr"
assert_firewall_unchanged

new_case clone-failure
enable_git
: > "$CASE_ROOT/clone.fail"
run_bootstrap --yes
[ "$BOOTSTRAP_STATUS" -ne 0 ]
assert_not_invoked
assert_no_checkout
assert_firewall_unchanged

new_case dirty-checkout
enable_git
: > "$CASE_ROOT/status.dirty"
run_bootstrap --yes
[ "$BOOTSTRAP_STATUS" -ne 0 ]
assert_not_invoked
assert_no_checkout
grep -q 'checkout changed before execution' "$CASE_ROOT/stderr"
assert_firewall_unchanged

new_case installer-failure
enable_git
printf '37\n' > "$CASE_ROOT/installer.fail"
run_bootstrap --yes
[ "$BOOTSTRAP_STATUS" -eq 37 ]
[ "$(wc -l < "$CASE_ROOT/installer.count")" -eq 1 ]
assert_no_checkout
assert_firewall_unchanged

# HUP, SIGINT and SIGTERM reach the real installer process, return conventional
# statuses, reap the whole process group and remove the temporary checkout.
for signal_case in 'HUP 129' 'INT 130' 'TERM 143'; do
    set -- $signal_case
    new_case "installer-signal-$1"
    enable_git
    : > "$CASE_ROOT/installer.wait"
    env -i \
        PATH="$CASE_ROOT/bin" \
        TMPDIR="$CASE_ROOT/tmp with spaces" \
        ATUM_BOOTSTRAP_TEST_ROOT="$CASE_ROOT" \
        "$PERL" "$ROOT/utests/presentation-signal-driver.pl" "$1" "$2" \
        "$CASE_ROOT/installer.ready" -- "$ROOT/bootstrap" --yes \
        > "$CASE_ROOT/stdout" 2> "$CASE_ROOT/stderr"
    [ ! -e "$CASE_ROOT/installer.live" ]
    assert_private_checkout
    assert_no_checkout
    assert_firewall_unchanged
done

echo 'PASS  bootstrap acquisition, private checkout, umask restoration, argument forwarding and failure cleanup'
