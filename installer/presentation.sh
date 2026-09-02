#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later

# Shared, side-effect-free presentation helpers for the system installer.

atum_ip_is_valid() {
    php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP) === false ? 1 : 0);' "$1" >/dev/null 2>&1
}

atum_url_host() {
    case "$1" in
        *:*) printf '[%s]' "$1" ;;
        *) printf '%s' "$1" ;;
    esac
}

atum_candidate_is_useful() {
    candidate=$1
    family=$2
    atum_ip_is_valid "$candidate" || return 1
    case "$candidate" in
        0.0.0.0|127.*|169.254.*|::|::1|[fF][eE]80:*) return 1 ;;
    esac
    case "$family:$candidate" in
        4:*:*) return 1 ;;
        6:*:*) return 0 ;;
        6:*) return 1 ;;
        4:*) return 0 ;;
    esac
}

atum_https_url() {
    listen_address=$1
    listen_port=$2
    host_addresses=${3:-}
    ssh_connection=${4:-}

    case "$listen_address" in
        0.0.0.0) family=4 ;;
        ::) family=6 ;;
        *)
            url_host=$(atum_url_host "$listen_address")
            printf 'https://%s:%s/\n' "$url_host" "$listen_port"
            return 0
            ;;
    esac

    # SSH_CONNECTION is: client-address client-port server-address server-port.
    if [ -n "$ssh_connection" ]; then
        ssh_server_address=$(printf '%s\n' "$ssh_connection" | awk '{print $3}')
        if atum_candidate_is_useful "$ssh_server_address" "$family"; then
            url_host=$(atum_url_host "$ssh_server_address")
            printf 'https://%s:%s/\n' "$url_host" "$listen_port"
            return 0
        fi
    fi

    selected=
    candidates_seen=" "
    candidate_count=0
    for candidate in $host_addresses; do
        atum_candidate_is_useful "$candidate" "$family" || continue
        case "$candidates_seen" in *" $candidate "*) continue ;; esac
        candidates_seen="$candidates_seen$candidate "
        selected=$candidate
        candidate_count=$((candidate_count + 1))
    done
    if [ "$candidate_count" -eq 1 ]; then
        url_host=$(atum_url_host "$selected")
        printf 'https://%s:%s/\n' "$url_host" "$listen_port"
        return 0
    fi
    printf 'https://<server-address>:%s/\n' "$listen_port"
}

atum_progress_signal() {
    progress_status=$1
    trap - HUP INT TERM
    if [ -n "${progress_heartbeat_pid:-}" ]; then
        kill -s TERM "$progress_heartbeat_pid" 2>/dev/null || true
        wait "$progress_heartbeat_pid" 2>/dev/null || true
        progress_heartbeat_pid=
    fi
    exit "$progress_status"
}

atum_progress_heartbeat() {
    heartbeat_started=$1
    heartbeat_interval=$2
    heartbeat_last_report=0
    trap 'exit 0' HUP INT TERM
    while :; do
        sleep 0.1
        heartbeat_now=$(date +%s)
        heartbeat_elapsed=$((heartbeat_now - heartbeat_started))
        if [ "$heartbeat_elapsed" -ge $((heartbeat_last_report + heartbeat_interval)) ]; then
            atum_progress_detail "Working... ${heartbeat_elapsed}s"
            heartbeat_last_report=$heartbeat_elapsed
        fi
    done
}

atum_progress_stop_heartbeat() {
    if [ -n "${progress_heartbeat_pid:-}" ]; then
        kill -s TERM "$progress_heartbeat_pid" 2>/dev/null || true
        wait "$progress_heartbeat_pid" 2>/dev/null || true
        progress_heartbeat_pid=
    fi
}

atum_progress_detail() {
    printf '      %s\n' "$1"
}

atum_run_quiet_with_progress() {
    progress_output=$1
    progress_label=$2
    shift 2
    [ "${1:-}" = -- ] && shift
    [ "$#" -gt 0 ] || return 2

    progress_interval=${ATUM_PROGRESS_INTERVAL_SECONDS:-10}
    case "$progress_interval" in
        *[!0-9]*|'') progress_interval=10 ;;
    esac
    [ "$progress_interval" -gt 0 ] || progress_interval=10

    progress_heartbeat_pid=
    trap 'atum_progress_signal 129' HUP
    trap 'atum_progress_signal 130' INT
    trap 'atum_progress_signal 143' TERM

    progress_started=$(date +%s)
    atum_progress_detail "$progress_label..."
    atum_progress_heartbeat "$progress_started" "$progress_interval" &
    progress_heartbeat_pid=$!

    if "$@" > "$progress_output" 2>&1; then
        progress_status=0
    else
        progress_status=$?
    fi
    atum_progress_stop_heartbeat
    trap - HUP INT TERM

    progress_finished=$(date +%s)
    progress_elapsed=$((progress_finished - progress_started))
    if [ "$progress_status" -eq 0 ]; then
        if [ "$progress_elapsed" -gt 0 ]; then
            atum_progress_detail "Required packages installed (${progress_elapsed}s)"
        else
            atum_progress_detail 'Required packages installed'
        fi
    fi
    return "$progress_status"
}

atum_print_completion() {
    completion_prefix=$1
    completion_administrator=$2
    completion_remote=$3
    completion_web_server=${4:-}
    completion_listen_address=${5:-}
    completion_listen_port=${6:-}
    completion_url=${7:-}
    completion_rule='============================================================'

    printf '\n%s\n' "$completion_rule"
    printf '                 ATUM INSTALLATION COMPLETE\n'
    printf '%s\n\n' "$completion_rule"
    printf 'Atum 0.1 has been installed successfully.\n\n'
    if [ "$completion_remote" -eq 1 ]; then
        printf 'OPEN ATUM:\n  %s\n\n' "$completion_url"
    fi
    [ -z "$completion_administrator" ] || printf 'Administrator: %s\n' "$completion_administrator"
    if [ "$completion_remote" -eq 1 ]; then
        completion_listen_host=$(atum_url_host "$completion_listen_address")
        printf 'Web server:    %s\n' "$completion_web_server"
        printf 'HTTPS:         enabled at %s:%s\n' "$completion_listen_host" "$completion_listen_port"
    fi
    printf 'Kamailio:      unchanged\n\n'
    printf 'Installed at:\n  %s\n\n' "$completion_prefix"
    if [ "$completion_remote" -eq 1 ]; then
        printf 'The development certificate is self-signed.\n'
        printf 'No firewall rules were changed. Permit TCP/%s only from your trusted administration address or network.\n\n' "$completion_listen_port"
    else
        printf 'Run for local testing:\n  sudo -u atum atum serve\n'
        printf 'For remote testing, reinstall explicitly with --development --remote.\n\n'
    fi
    printf 'To preview removal:\n  atum-uninstall --check\n\n'
    printf '%s\n' "$completion_rule"
    printf '       DEVELOPMENT PREVIEW - NOT FOR PRODUCTION\n'
    printf '%s\n' "$completion_rule"
}

if [ "${ATUM_PRESENTATION_COMMAND:-}" = run-quiet ]; then
    atum_run_quiet_with_progress "$@"
    exit $?
fi
