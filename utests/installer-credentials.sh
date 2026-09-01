#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
if [ -x /usr/local/php/8.4.15/bin/php ]; then
    PHP_BIN=/usr/local/php/8.4.15/bin/php
else
    PHP_BIN=$(command -v php)
fi
XDEBUG_MODE=off
export XDEBUG_MODE
TEST_ROOT=$(mktemp -d /tmp/atum-credential-unit.XXXXXX)

cleanup() {
    trap - EXIT HUP INT TERM
    case "$TEST_ROOT" in
        /tmp/atum-credential-unit.*) rm -rf -- "$TEST_ROOT" ;;
        *) printf '%s\n' "Refusing to clean unexpected test path: $TEST_ROOT" >&2 ;;
    esac
}
trap cleanup EXIT HUP INT TERM

host_snapshot() {
    destination=$1
    {
        getent passwd atum 2>/dev/null || true
        getent group atum 2>/dev/null || true
        for path in /usr/local/sbin/atum /usr/local/sbin/atum-uninstall; do
            if [ -e "$path" ] || [ -L "$path" ]; then
                stat -c '%n|%F|%U:%G|%a|%N' "$path"
            else
                printf '%s|absent\n' "$path"
            fi
        done
    } > "$destination"
}

run_fixture() {
    "$PHP_BIN" "$ROOT/utests/installer-credential-fixture.php" "$1" "$2"
}

host_snapshot "$TEST_ROOT/host-before"

# Invalid username, short password and confirmation mismatch all retry within
# the real credential collector. The persistence callback is reached once.
"$ROOT/utests/pty-driver.pl" \
    --step='Administrator username|input|21210a' \
    --step='Administrator username|input|726574727961646d696e0a' \
    --step='Administrator password|input|73686f72740a' \
    --step='Administrator password|input|436f72726563742070617373776f7264203132330a' \
    --step='Confirm password|input|646966666572656e742070617373776f7264203132330a' \
    --step='Administrator password|input|436f72726563742070617373776f7264203132330a' \
    --step='Confirm password|input|436f72726563742070617373776f7264203132330a' \
    -- "$PHP_BIN" "$ROOT/utests/installer-credential-fixture.php" \
    "$TEST_ROOT/interactive-record.json" "$TEST_ROOT/interactive-stages" \
    > "$TEST_ROOT/interactive.out" 2>&1

grep -q 'Username must be 3-64 characters' "$TEST_ROOT/interactive.out"
grep -q 'Password must be at least 12 characters' "$TEST_ROOT/interactive.out"
grep -q 'Administrator passwords do not match' "$TEST_ROOT/interactive.out"
grep -q 'Initial administrator: retryadmin' "$TEST_ROOT/interactive.out"
"$PHP_BIN" -r '$r=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); exit($r === ["create_calls" => 1, "username" => "retryadmin"] ? 0 : 1);' "$TEST_ROOT/interactive-record.json"
[ "$(wc -l < "$TEST_ROOT/interactive-stages")" -eq 1 ]

# Terminal EOF must reach each actual fgets() path and must never invoke the
# persistence callback. Each invocation has already completed its caller stage.
run_eof_case() {
    name=$1
    shift
    set +e
    "$ROOT/utests/pty-driver.pl" "$@" -- "$PHP_BIN" \
        "$ROOT/utests/installer-credential-fixture.php" \
        "$TEST_ROOT/$name-record.json" "$TEST_ROOT/$name-stages" \
        > "$TEST_ROOT/$name.out" 2>&1
    status=$?
    set -e
    [ "$status" -ne 0 ]
    grep -q 'credential input was aborted' "$TEST_ROOT/$name.out"
    [ ! -e "$TEST_ROOT/$name-record.json" ]
    [ "$(wc -l < "$TEST_ROOT/$name-stages")" -eq 1 ]
}

run_eof_case eof-username \
    --step='Administrator username|eof|'
run_eof_case eof-password \
    --step='Administrator username|input|726574727961646d696e0a' \
    --step='Administrator password|eof|'
run_eof_case eof-confirmation \
    --step='Administrator username|input|726574727961646d696e0a' \
    --step='Administrator password|input|436f72726563742070617373776f7264203132330a' \
    --step='Confirm password|eof|'

# SIGINT is delivered at every actual input phase to the collector and its
# outer shell process group. Ordinary EXIT rollback removes provisional state.
run_interrupt_case() {
    name=$1
    shift
    set +e
    "$ROOT/utests/pty-driver.pl" "$@" -- \
        "$ROOT/utests/installer-credential-signal.sh" "$PHP_BIN" \
        "$ROOT/utests/installer-credential-fixture.php" \
        "$TEST_ROOT/$name-record.json" "$TEST_ROOT/$name-stages" \
        "$TEST_ROOT/$name-provisional" > "$TEST_ROOT/$name.out" 2>&1
    interrupt_status=$?
    set -e
    [ "$interrupt_status" -eq 130 ]
    [ ! -e "$TEST_ROOT/$name-record.json" ]
    [ ! -e "$TEST_ROOT/$name-provisional" ]
}

run_interrupt_case interrupt-username \
    --step='Administrator username|interrupt|'
run_interrupt_case interrupt-password \
    --step='Administrator username|input|726574727961646d696e0a' \
    --step='Administrator password|interrupt|'
run_interrupt_case interrupt-confirmation \
    --step='Administrator username|input|726574727961646d696e0a' \
    --step='Administrator password|input|436f72726563742070617373776f7264203132330a' \
    --step='Confirm password|interrupt|'

# The PTY driver itself must reap its child session when the surrounding test
# process receives any supported termination signal.
run_driver_signal_case() {
    signal=$1
    expected_status=$2
    name=$(printf '%s' "$signal" | tr '[:upper:]' '[:lower:]')
    ready="$TEST_ROOT/driver-$name-ready"
    provisional="$TEST_ROOT/driver-$name-provisional"
    "$ROOT/utests/pty-driver.pl" \
        --step="Administrator username|ready|$ready" -- \
        "$ROOT/utests/installer-credential-signal.sh" "$PHP_BIN" \
        "$ROOT/utests/installer-credential-fixture.php" \
        "$TEST_ROOT/driver-$name-record.json" "$TEST_ROOT/driver-$name-stages" \
        "$provisional" > "$TEST_ROOT/driver-$name.out" 2>&1 &
    driver_pid=$!
    attempts=0
    while [ ! -e "$ready" ] && kill -0 "$driver_pid" 2>/dev/null && [ "$attempts" -lt 100 ]; do
        sleep 0.05
        attempts=$((attempts + 1))
    done
    [ -e "$ready" ]
    kill -s "$signal" "$driver_pid"
    set +e
    wait "$driver_pid"
    driver_status=$?
    set -e
    [ "$driver_status" -eq "$expected_status" ]
    [ ! -e "$TEST_ROOT/driver-$name-record.json" ]
    [ ! -e "$provisional" ]
}

run_driver_signal_case HUP 129
run_driver_signal_case INT 130
run_driver_signal_case TERM 143

# Environment/password-file credentials are strictly non-interactive. Invalid
# or incomplete pairs fail without falling back to a prompt; a valid pair works.
printf '%s\n' 'Valid password 123' > "$TEST_ROOT/bad-username-password"
set +e
ATUM_ADMIN_USER='!!' ATUM_ADMIN_PASSWORD_FILE="$TEST_ROOT/bad-username-password" \
    run_fixture "$TEST_ROOT/noninteractive-bad-user.json" "$TEST_ROOT/noninteractive-bad-user-stages" \
    > "$TEST_ROOT/noninteractive-bad-user.out" 2>&1
bad_user_status=$?
set -e
[ "$bad_user_status" -ne 0 ]
grep -q 'Username must be 3-64 characters' "$TEST_ROOT/noninteractive-bad-user.out"
! grep -q 'Administrator username \[' "$TEST_ROOT/noninteractive-bad-user.out"
[ ! -e "$TEST_ROOT/noninteractive-bad-user.json" ]

printf '%s\n' 'short' > "$TEST_ROOT/bad-policy-password"
set +e
ATUM_ADMIN_USER=validadmin ATUM_ADMIN_PASSWORD_FILE="$TEST_ROOT/bad-policy-password" \
    run_fixture "$TEST_ROOT/noninteractive-bad-password.json" "$TEST_ROOT/noninteractive-bad-password-stages" \
    > "$TEST_ROOT/noninteractive-bad-password.out" 2>&1
bad_password_status=$?
set -e
[ "$bad_password_status" -ne 0 ]
grep -q 'Password must be at least 12 characters' "$TEST_ROOT/noninteractive-bad-password.out"
! grep -q 'Administrator password' "$TEST_ROOT/noninteractive-bad-password.out"
[ ! -e "$TEST_ROOT/noninteractive-bad-password.json" ]

set +e
ATUM_ADMIN_USER=validadmin \
    run_fixture "$TEST_ROOT/noninteractive-incomplete.json" "$TEST_ROOT/noninteractive-incomplete-stages" \
    > "$TEST_ROOT/noninteractive-incomplete.out" 2>&1
incomplete_status=$?
set -e
[ "$incomplete_status" -ne 0 ]
grep -q 'must be supplied together' "$TEST_ROOT/noninteractive-incomplete.out"
! grep -q 'Administrator password' "$TEST_ROOT/noninteractive-incomplete.out"
[ ! -e "$TEST_ROOT/noninteractive-incomplete.json" ]

printf '%s\n' 'Valid password 123' > "$TEST_ROOT/good-password"
ATUM_ADMIN_USER=validadmin ATUM_ADMIN_PASSWORD_FILE="$TEST_ROOT/good-password" \
    run_fixture "$TEST_ROOT/noninteractive-good.json" "$TEST_ROOT/noninteractive-good-stages" \
    > "$TEST_ROOT/noninteractive-good.out" 2>&1
grep -q 'Initial administrator: validadmin' "$TEST_ROOT/noninteractive-good.out"
"$PHP_BIN" -r '$r=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); exit($r === ["create_calls" => 1, "username" => "validadmin"] ? 0 : 1);' "$TEST_ROOT/noninteractive-good.json"

# Secrets may exist only in their deliberate input files. They must not be
# echoed to captured output, callback records or stage/rollback artefacts.
for secret in 'short' 'different password 123' 'Correct password 123' 'Valid password 123'; do
    ! find "$TEST_ROOT" -type f \
        ! -name 'bad-username-password' \
        ! -name 'bad-policy-password' \
        ! -name 'good-password' \
        -exec grep -F -q "$secret" {} +
done

host_snapshot "$TEST_ROOT/host-after"
cmp -s "$TEST_ROOT/host-before" "$TEST_ROOT/host-after"

echo 'PASS  isolated credential retries, secrecy, EOF/SIGINT rollback and non-interactive validation'
