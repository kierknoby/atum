#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu

PHP_BIN=$1
FIXTURE=$2
RECORD=$3
STAGES=$4
PROVISIONAL=$5

cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    rm -f -- "$PROVISIONAL"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

printf '%s\n' provisional > "$PROVISIONAL"
"$PHP_BIN" "$FIXTURE" "$RECORD" "$STAGES"
