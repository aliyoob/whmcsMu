#!/bin/bash
#
# The scripts under infrastructure/ that run on *your* machine must work with
# bash 3.2, because that is what macOS still ships as /bin/bash. Constructs
# like ${var,,} parse fine there and then fail at runtime with "bad
# substitution" — halfway through a deployment, which is the worst possible
# moment to discover it.
#
# Deliberately invoked with /bin/bash, not `env bash`.
#
set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1

LOCAL_SCRIPTS="
infrastructure/setup-whmcs.sh
infrastructure/deploy.sh
infrastructure/deploy-all.sh
infrastructure/install-module.sh
infrastructure/lib/common.sh
infrastructure/lib/ssh-auth.sh
"

failures=0
report() { printf '  %s %s\n' "$1" "$2"; }

echo "bash $(/bin/bash -c 'echo $BASH_VERSION')"
echo

# --- 1. syntax --------------------------------------------------------------

for script in $LOCAL_SCRIPTS; do
    if /bin/bash -n "$script" 2>/dev/null; then
        report "ok  " "parses: $script"
    else
        report "FAIL" "syntax error under bash 3.2: $script"
        failures=$((failures + 1))
    fi
done

# --- 2. bash 4+ only constructs ---------------------------------------------

# ${v,,} ${v^^} case modification · associative arrays · mapfile/readarray ·
# the ;;& case fallthrough. Comments are excluded: they may name the construct
# in order to explain why it is avoided.
PATTERN='\$\{[A-Za-z_][A-Za-z0-9_]*(\[[^]]*\])?(,,|\^\^)|declare -A|local -A|\bmapfile\b|\breadarray\b|;;&'

for script in $LOCAL_SCRIPTS; do
    hits=$(grep -nE "$PATTERN" "$script" | grep -vE '^\s*[0-9]+:\s*#' | grep -vE '^[0-9]+:\s*#')
    if [ -z "$hits" ]; then
        report "ok  " "bash 3.2 safe: $script"
    else
        report "FAIL" "bash 4+ syntax in $script:"
        printf '%s\n' "$hits" | sed 's/^/         /'
        failures=$((failures + 1))
    fi
done

# --- 3. pipefail + grep -q --------------------------------------------------
#
# `cmd | grep -q PATTERN` reports failure whenever the writer is still
# producing output when grep exits at the first match: SIGPIPE, then pipefail
# turns a *successful* match into rc=141. It works on short output and fails
# on long output, so it survives testing and breaks in production.

ALL_SCRIPTS="$LOCAL_SCRIPTS infrastructure/lib/remote-provision.sh
infrastructure/lib/install-whmcs.sh infrastructure/lib/extract-whmcs.sh
infrastructure/lib/install-module-remote.sh"

for script in $ALL_SCRIPTS; do
    [ -f "$script" ] || continue
    # Any consumer that can exit before its producer finishes: grep -q,
    # grep -m, head, `awk ... exit`, `sed ... q`. All of them send SIGPIPE
    # upstream, and pipefail then reports a *successful* match as a failure.
    EARLY_EXIT='\|[[:space:]]*(grep[[:space:]]+-[a-zA-Z]*q|grep[^|]*-m[[:space:]]*[0-9]|head\b|awk[^|]*\bexit\b|sed[^|]*[^a-z]q\b)'
    hits=$(grep -nE "$EARLY_EXIT" "$script" | grep -vE '^[0-9]+:[[:space:]]*#')
    if [ -z "$hits" ]; then
        report "ok  " "no SIGPIPE/pipefail trap: $script"
    else
        report "FAIL" "pipe into an early-exiting consumer in $script:"
        printf '%s\n' "$hits" | sed 's/^/         /'
        report "    " "use contains(), or capture the output and parse it"
        failures=$((failures + 1))
    fi
done

# And prove the trap is real, so nobody "simplifies" contains() away.
if /bin/bash -c 'set -euo pipefail; seq 1 200000 | grep -q "^1$"' 2>/dev/null; then
    report "ok  " "this shell does not exhibit the SIGPIPE trap"
else
    report "ok  " "SIGPIPE trap reproduced — contains() is load-bearing"
fi

# --- 4. the prompt helpers, exercised for real ------------------------------
#
# Parsing is not enough: ${var,,} is a *runtime* failure.

source infrastructure/lib/common.sh

expect() {
    if [ "$2" = "$3" ]; then
        report "ok  " "$1"
    else
        report "FAIL" "$1: got '$2', want '$3'"
        failures=$((failures + 1))
    fi
}

expect "lower()" "$(lower 'YeS')" "yes"

is_yes "YES" && yes_upper=y || yes_upper=n
expect "is_yes accepts YES" "$yes_upper" "y"

is_yes "no" && no_lower=y || no_lower=n
expect "is_yes rejects no" "$no_lower" "n"

ANSWER=""; ANSWER=$(printf 'Y\n' | { ask_yes_no A "Q" "n" >/dev/null 2>&1; echo "$A"; })
expect "ask_yes_no maps uppercase Y" "$ANSWER" "y"

ANSWER=""; ANSWER=$(printf '\n' | { ask_yes_no A "Q" "y" >/dev/null 2>&1; echo "$A"; })
expect "ask_yes_no takes its default" "$ANSWER" "y"

ANSWER=""; ANSWER=$(printf 'value\n' | { ask V "Q" >/dev/null 2>&1; echo "$V"; })
expect "ask() reads a value" "$ANSWER" "value"

ANSWER=""; ANSWER=$(printf 'secret\n' | { ask_secret S "Q" >/dev/null 2>&1; echo "$S"; })
expect "ask_secret() reads a value" "$ANSWER" "secret"

expect "is_hostname accepts a real host" "$(is_hostname billing.example.com && echo y || echo n)" "y"
expect "is_hostname rejects a phrase"    "$(is_hostname 'not a host' && echo y || echo n)"        "n"
contains "hay needle stack" "needle" && c1=y || c1=n
expect "contains() finds a substring" "$c1" "y"
contains "hay stack" "needle" && c2=y || c2=n
expect "contains() rejects a miss"    "$c2" "n"

expect "random_token length"             "$(random_token 24 | tr -d '\n' | wc -c | tr -d ' ')"    "24"

echo
if [ "$failures" -eq 0 ]; then
    echo "OK — the deployment scripts run on bash 3.2"
    exit 0
fi
echo "FAILURES: $failures"
exit 1
