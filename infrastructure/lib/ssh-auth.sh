#!/usr/bin/env bash
# Password-capable SSH/SCP wrappers.
# shellcheck shell=bash
#
# Password authentication over SSH needs a helper, because ssh deliberately
# refuses to read a password from a pipe. Three strategies, in order:
#
#   1. an SSH key            — always used once one is available
#   2. sshpass               — if the operator already has it
#   3. expect                — ships with macOS and most Linux distributions
#
# The password is passed through the environment, never on a command line,
# where it would be visible in the local process list.

WTP_SSH_STRATEGY=""
WTP_EXPECT_HELPER=""

# wtp_ssh_init  — decide how to authenticate. Call after the answers are known.
wtp_ssh_init() {
  if [[ "${VPS_AUTH_METHOD:-key}" == "key" && -n "${VPS_SSH_KEY:-}" ]]; then
    WTP_SSH_STRATEGY="key"
    return 0
  fi

  if command -v sshpass >/dev/null 2>&1; then
    WTP_SSH_STRATEGY="sshpass"
    return 0
  fi

  if command -v expect >/dev/null 2>&1; then
    WTP_SSH_STRATEGY="expect"
    WTP_EXPECT_HELPER="$(mktemp)"
    chmod 700 "$WTP_EXPECT_HELPER"
    cat > "$WTP_EXPECT_HELPER" <<'EXPECT'
# Feed the password to ssh/scp, then get out of the way.
#
# Output is suppressed until authentication is over. Without that, the
# "password:" prompt itself lands in whatever the caller captured, and every
# string comparison against a command's output silently breaks.
set timeout 45
log_user 0

set password $env(WTP_SSH_PASSWORD)
eval spawn -noecho [lrange $argv 0 end]

expect {
    -re "(?i)are you sure you want to continue connecting" {
        send "yes\r"
        exp_continue
    }
    -re "(?i)(password|passphrase)\[^\r\n\]*:\[ \]?" {
        send -- "$password\r"
        expect -re "\r?\n"
        log_user 1
        set timeout -1
        exp_continue
    }
    -re "(?i)permission denied" {
        log_user 1
        exit 5
    }
    timeout {
        # No prompt appeared — show whatever comes next rather than swallow it.
        log_user 1
        set timeout -1
        exp_continue
    }
    eof
}

catch wait result
exit [lindex $result 3]
EXPECT
    return 0
  fi

  die "Password authentication needs either 'sshpass' or 'expect'.
  macOS:  brew install expect        (or: brew install hudochenkov/sshpass/sshpass)
  Debian: sudo apt install expect
  Or use an SSH key instead, which avoids this entirely."
}

wtp_ssh_cleanup() {
  [[ -n "$WTP_EXPECT_HELPER" && -f "$WTP_EXPECT_HELPER" ]] && rm -f "$WTP_EXPECT_HELPER"
  unset SSHPASS WTP_SSH_PASSWORD
  return 0
}

# wtp_ssh [ssh args...]
wtp_ssh() {
  case "$WTP_SSH_STRATEGY" in
    key)     ssh "$@" ;;
    sshpass) SSHPASS="$VPS_SSH_PASSWORD" sshpass -e ssh "$@" ;;
    expect)  WTP_SSH_PASSWORD="$VPS_SSH_PASSWORD" expect -f "$WTP_EXPECT_HELPER" ssh "$@" ;;
    *)       die "SSH authentication was never initialised" ;;
  esac
}

# wtp_scp [scp args...]
wtp_scp() {
  case "$WTP_SSH_STRATEGY" in
    key)     scp "$@" ;;
    sshpass) SSHPASS="$VPS_SSH_PASSWORD" sshpass -e scp "$@" ;;
    expect)  WTP_SSH_PASSWORD="$VPS_SSH_PASSWORD" expect -f "$WTP_EXPECT_HELPER" scp "$@" ;;
    *)       die "SSH authentication was never initialised" ;;
  esac
}

# Command output through a pty picks up carriage returns, and the helper may
# emit a blank line as authentication finishes. Strip both, so callers can
# compare strings without surprises.
wtp_ssh_capture() {
  wtp_ssh "$@" 2>/dev/null | tr -d '\r' | sed '/^[[:space:]]*$/d'
}

# The last non-empty line, trimmed — for commands that return a single value.
wtp_ssh_value() {
  wtp_ssh_capture "$@" | tail -1 | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'
}
