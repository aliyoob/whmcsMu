#!/usr/bin/env bash
# Shared helpers for the WTP infrastructure scripts.
# shellcheck shell=bash

set -euo pipefail

if [[ -t 1 ]] && [[ "${NO_COLOR:-}" == "" ]]; then
  C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
  C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'
else
  C_RESET=''; C_BOLD=''; C_DIM=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''
fi

log()      { printf '%s\n' "${C_DIM}·${C_RESET} $*"; }
info()     { printf '%s\n' "${C_BLUE}▸${C_RESET} $*"; }
success()  { printf '%s\n' "${C_GREEN}✓${C_RESET} $*"; }
warn()     { printf '%s\n' "${C_YELLOW}!${C_RESET} $*" >&2; }
error()    { printf '%s\n' "${C_RED}✗${C_RESET} $*" >&2; }
die()      { error "$*"; exit 1; }

heading() {
  printf '\n%s\n' "${C_BOLD}$*${C_RESET}"
  printf '%s\n' "$(printf '─%.0s' $(seq 1 ${#1}))"
}

# ask VARNAME "Prompt" ["default"]
ask() {
  local __var="$1" __prompt="$2" __default="${3:-}" __answer=""

  if [[ -n "${!__var:-}" ]]; then
    return 0   # already supplied by a config file or the environment
  fi
  if [[ "${WTP_NONINTERACTIVE:-0}" == "1" ]]; then
    [[ -n "$__default" ]] || die "$__var is required in non-interactive mode"
    printf -v "$__var" '%s' "$__default"
    return 0
  fi

  if [[ -n "$__default" ]]; then
    read -r -p "  ${__prompt} [${__default}]: " __answer
    __answer="${__answer:-$__default}"
  else
    while [[ -z "$__answer" ]]; do
      read -r -p "  ${__prompt}: " __answer
    done
  fi
  printf -v "$__var" '%s' "$__answer"
}

# ask_secret VARNAME "Prompt"
ask_secret() {
  local __var="$1" __prompt="$2" __answer=""

  if [[ -n "${!__var:-}" ]]; then
    return 0
  fi
  if [[ "${WTP_NONINTERACTIVE:-0}" == "1" ]]; then
    die "$__var must be supplied via the environment in non-interactive mode"
  fi

  read -r -s -p "  ${__prompt}: " __answer
  printf '\n'
  printf -v "$__var" '%s' "$__answer"
}

# ask_yes_no VARNAME "Prompt" "y|n"
ask_yes_no() {
  local __var="$1" __prompt="$2" __default="${3:-y}" __answer=""

  if [[ -n "${!__var:-}" ]]; then
    return 0
  fi
  if [[ "${WTP_NONINTERACTIVE:-0}" == "1" ]]; then
    printf -v "$__var" '%s' "$__default"
    return 0
  fi

  local __hint="[Y/n]"
  [[ "$__default" == "n" ]] && __hint="[y/N]"

  read -r -p "  ${__prompt} ${__hint}: " __answer
  __answer="${__answer:-$__default}"
  case "${__answer,,}" in
    y|yes) printf -v "$__var" '%s' "y" ;;
    *)     printf -v "$__var" '%s' "n" ;;
  esac
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "This script needs '$1' on your machine. $2"
}

# A URL-safe random string, using whatever entropy source is available.
random_token() {
  local length="${1:-32}"
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 $((length * 2)) | tr -d '\n=+/' | cut -c1-"$length"
  else
    head -c $((length * 2)) /dev/urandom | base64 | tr -d '\n=+/' | cut -c1-"$length"
  fi
}

# A syntactically valid hostname? Deliberately permissive about TLDs.
is_hostname() {
  [[ "$1" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$ ]]
}

is_ipv4() {
  [[ "$1" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]
}
