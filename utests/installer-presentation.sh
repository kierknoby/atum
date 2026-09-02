#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
ATUM_PRESENTATION_COMMAND=
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

run_progress_checked() {
    expected_status=$1
    progress_output=$2
    ready_file=$3
    progress_label=$4
    shift 4
    env ATUM_PRESENTATION_COMMAND=run-quiet ATUM_PROGRESS_INTERVAL_SECONDS=1 \
        "$ROOT/utests/presentation-signal-driver.pl" WAIT "$expected_status" \
        "$ready_file" -- "$ROOT/installer/presentation.sh" "$progress_output" \
        "$progress_label" -- "$@"
}

# Quiet mode keeps child diagnostics captured while emitting stable, line-based
# elapsed-time progress. It returns as soon as the real child finishes.
started=$(date +%s)
run_progress_checked 0 "$TEST_ROOT/success-child.out" \
    "$TEST_ROOT/success-child.pid" 'Installing test packages' \
    sh -c 'printf "%s\n" "$$" > "$1"; sleep 2; echo success-diagnostic' sh \
    "$TEST_ROOT/success-child.pid" > "$TEST_ROOT/success-progress.out"
finished=$(date +%s)
[ $((finished - started)) -lt 4 ]
grep -q '^      Installing test packages\.\.\.$' "$TEST_ROOT/success-progress.out"
grep -Eq '^      Working\.\.\. [12]s$' "$TEST_ROOT/success-progress.out"
grep -Eq '^      Required packages installed \([23]s\)$' "$TEST_ROOT/success-progress.out"
grep -qx 'success-diagnostic' "$TEST_ROOT/success-child.out"
! grep -q 'success-diagnostic' "$TEST_ROOT/success-progress.out"
success_child=$(cat "$TEST_ROOT/success-child.pid")
! kill -0 "$success_child" 2>/dev/null
cp "$TEST_ROOT/success-progress.out" "$TEST_ROOT/success-progress.complete"
sleep 1
cmp -s "$TEST_ROOT/success-progress.out" "$TEST_ROOT/success-progress.complete"

# The wrapper preserves a non-zero child status and leaves the complete captured
# diagnostic available for the installer's existing replay path.
run_progress_checked 42 "$TEST_ROOT/failure-child.out" \
    "$TEST_ROOT/failure-child.pid" 'Installing failing packages' \
    sh -c 'printf "%s\n" "$$" > "$1"; echo complete-failure-diagnostic >&2; exit 42' sh \
    "$TEST_ROOT/failure-child.pid" > "$TEST_ROOT/failure-progress.out"
grep -qx 'complete-failure-diagnostic' "$TEST_ROOT/failure-child.out"
! grep -q 'complete-failure-diagnostic' "$TEST_ROOT/failure-progress.out"
failure_child=$(cat "$TEST_ROOT/failure-child.pid")
! kill -0 "$failure_child" 2>/dev/null
cat "$TEST_ROOT/failure-child.out" >> "$TEST_ROOT/failure-progress.out"
grep -qx 'complete-failure-diagnostic' "$TEST_ROOT/failure-progress.out"

cat > "$TEST_ROOT/signal-child.sh" <<'EOF'
#!/bin/sh
pid_file=$1
live_file=$2
trap 'rm -f "$live_file"; exit 130' INT
trap 'rm -f "$live_file"; exit 143' TERM
printf '%s\n' "$$" > "$pid_file"
: > "$live_file"
while :; do sleep 1; done
EOF
chmod 0755 "$TEST_ROOT/signal-child.sh"

run_signal_case() {
    signal=$1
    expected_status=$2
    name=$(printf '%s' "$signal" | tr '[:upper:]' '[:lower:]')
    pid_file="$TEST_ROOT/$name-child.pid"
    live_file="$TEST_ROOT/$name-child.live"
    env ATUM_PRESENTATION_COMMAND=run-quiet ATUM_PROGRESS_INTERVAL_SECONDS=1 \
        "$ROOT/utests/presentation-signal-driver.pl" "$signal" \
        "$expected_status" "$pid_file" -- "$ROOT/installer/presentation.sh" \
        "$TEST_ROOT/$name-child.out" 'Installing signal-test packages' -- \
        "$TEST_ROOT/signal-child.sh" "$pid_file" "$live_file" \
        > "$TEST_ROOT/$name-progress.out" 2>&1
    child_pid=$(cat "$pid_file")
    [ ! -e "$live_file" ]
    ! kill -0 "$child_pid" 2>/dev/null
}

run_signal_case INT 130
run_signal_case TERM 143

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
! grep -q "$(printf '\r')" "$TEST_ROOT/success-progress.out"
! grep -q "$(printf '\033')" "$TEST_ROOT/success-progress.out"

echo 'PASS  installer progress lifecycle, completion rendering and safe URL fallback'
