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
