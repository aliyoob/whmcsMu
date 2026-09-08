#!/usr/bin/env bash
#
# extract-whmcs.sh — runs ON the server, as root.
#
# Puts the WHMCS files into the web root. Server preparation normally does
# this, but you can also skip WHMCS the first time round (no licence yet, no
# archive downloaded yet) and come back later — at which point the packages,
# the database and TLS are all already in place and only the files are
# missing.
#
# Idempotent: an existing installation is never overwritten.
#
set -euo pipefail

ANSWERS="${1:?usage: $0 /path/to/answers.env}"
# shellcheck disable=SC1090
source "$ANSWERS"

[[ "$(id -u)" == "0" ]] || { echo "This script must run as root." >&2; exit 1; }

note() { printf '  %s\n' "$*"; }
warn() { printf '  \033[33m! %s\033[0m\n' "$*" >&2; }
fail() { printf '  \033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

WEBROOT="${WHMCS_WEBROOT}"
mkdir -p "$WEBROOT"

if [[ -f "${WEBROOT}/init.php" ]]; then
  note "WHMCS files are already present — leaving them alone."
  exit 0
fi

# A web root with files but no init.php is a broken or partial installation.
# Extracting on top would interleave two copies, which is worse than either.
if [[ -n "$(ls -A "$WEBROOT" 2>/dev/null)" ]]; then
  if [[ "${WHMCS_RESET_WEBROOT:-n}" == "y" ]]; then
    BACKUP="/var/backups/whmcs-webroot-$(date -u +%Y%m%dT%H%M%SZ)"
    note "Moving the existing web root aside to ${BACKUP}"
    mkdir -p "$(dirname "$BACKUP")"
    mv "$WEBROOT" "$BACKUP"
    mkdir -p "$WEBROOT"
  else
    fail "${WEBROOT} already contains files but no init.php.

  That is an incomplete or foreign WHMCS. Extracting on top of it would leave
  the two copies interleaved, which is worse than either on its own.

  Re-run the deployment with --reset-whmcs to move the existing files to
  /var/backups/ first, or clear ${WEBROOT} yourself."
  fi
fi

if [[ -n "${WHMCS_ARCHIVE_URL:-}" ]]; then
  note "Downloading WHMCS…"
  curl -fsSL "$WHMCS_ARCHIVE_URL" -o /tmp/whmcs.zip || fail "Could not download the archive."
  WHMCS_ARCHIVE_FILE=/tmp/whmcs.zip
fi

[[ -n "${WHMCS_ARCHIVE_FILE:-}" && -f "$WHMCS_ARCHIVE_FILE" ]] \
  || fail "No WHMCS archive was supplied."

command -v unzip >/dev/null 2>&1 || fail "unzip is not installed; run the server preparation first."

note "Extracting…"
TMP="$(mktemp -d)"
unzip -q "$WHMCS_ARCHIVE_FILE" -d "$TMP" || fail "The archive could not be extracted."

# WHMCS archives nest everything under whmcs/; tolerate either shape.
SOURCE="$TMP"
[[ -d "${TMP}/whmcs" ]] && SOURCE="${TMP}/whmcs"

[[ -f "${SOURCE}/init.php" ]] \
  || fail "That archive does not look like WHMCS — no init.php inside it."

cp -a "${SOURCE}/." "$WEBROOT/"
rm -rf "$TMP"

# WHMCS ships this with a .new suffix for exactly this step.
# A zero-byte configuration.php is worse than none: WHMCS fatals in dbconnect
# instead of redirecting to its installer, and the rename below would skip it
# because the file technically exists.
if [[ -f "${WEBROOT}/configuration.php" && ! -s "${WEBROOT}/configuration.php" ]]; then
  rm -f "${WEBROOT}/configuration.php"
  note "Removed an empty configuration.php"
fi

if [[ -f "${WEBROOT}/configuration.php.new" && ! -f "${WEBROOT}/configuration.php" ]]; then
  mv "${WEBROOT}/configuration.php.new" "${WEBROOT}/configuration.php"
fi

WEB_USER="$(stat -c '%U' "${WEBROOT}/modules" 2>/dev/null || true)"
if [[ -z "$WEB_USER" || "$WEB_USER" == "root" ]]; then
  WEB_USER="$(id -u www-data >/dev/null 2>&1 && echo www-data || echo nginx)"
fi

chown -R "${WEB_USER}:${WEB_USER}" "$WEBROOT"
find "$WEBROOT" -type d -exec chmod 0755 {} +
find "$WEBROOT" -type f -exec chmod 0644 {} +
[[ -f "${WEBROOT}/configuration.php" ]] && chmod 0640 "${WEBROOT}/configuration.php"

for writable in attachments downloads templates_c crons; do
  [[ -d "${WEBROOT}/${writable}" ]] && chmod -R 0775 "${WEBROOT}/${writable}"
done

note "WHMCS extracted to ${WEBROOT} as ${WEB_USER}"
