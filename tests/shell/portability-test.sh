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

# --- 3. the prompt helpers, exercised for real ------------------------------
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
expect "random_token length"             "$(random_token 24 | tr -d '\n' | wc -c | tr -d ' ')"    "24"

echo
if [ "$failures" -eq 0 ]; then
    echo "OK — the deployment scripts run on bash 3.2"
    exit 0
fi
echo "FAILURES: $failures"
exit 1
