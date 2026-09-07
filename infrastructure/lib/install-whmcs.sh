#!/usr/bin/env bash
#
# install-whmcs.sh — runs ON the server, as root, invoked by deploy-all.sh.
#
# Drives WHMCS's own non-interactive installer:
#
#     php -f bin/installer.php -- -i -n -c   < configuration JSON on stdin
#
# That is WHMCS's documented command-line installation path, not a simulated
# click-through of the web wizard, so it stays supported across versions.
#
# WHMCS's documentation warns against putting credentials on a command line.
# Everything sensitive here therefore travels in a 0600 file and reaches the
# installer on stdin — nothing lands in the server's process list.
#
# Answers are read from a sibling file written by deploy-all.sh.
#
set -euo pipefail

ANSWERS="${1:-$(dirname "${BASH_SOURCE[0]}")/whmcs-install.env}"
[[ -f "$ANSWERS" ]] || { echo "Missing answers file: $ANSWERS" >&2; exit 2; }
# shellcheck disable=SC1090
source "$ANSWERS"

[[ "$(id -u)" == "0" ]] || { echo "This script must run as root." >&2; exit 1; }

step() { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
note() { printf '  %s\n' "$*"; }
warn() { printf '  \033[33m! %s\033[0m\n' "$*" >&2; }
fail() { printf '  \033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

WEBROOT="${WHMCS_WEBROOT}"
INSTALLER_BIN="${WEBROOT}/bin/installer.php"

# ---------------------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------------------

step "Checking the installation"

[[ -d "$WEBROOT" ]] || fail "No web root at ${WEBROOT}"

if [[ ! -f "$INSTALLER_BIN" ]]; then
  fail "This WHMCS release has no bin/installer.php.
  The command-line installer arrived in WHMCS 8.x. Use the web installer at
  https://${WHMCS_DOMAIN}/install/install.php instead."
fi

# Whose files are these? Never let a failing stat abort the run under set -e —
# there is a perfectly good fallback below.
WEB_USER="$(stat -c '%U' "${WEBROOT}/index.php" 2>/dev/null || true)"
[[ -n "$WEB_USER" ]] || WEB_USER="$(stat -c '%U' "$WEBROOT" 2>/dev/null || true)"
if [[ -z "$WEB_USER" || "$WEB_USER" == "root" ]]; then
  WEB_USER="$(id -u www-data >/dev/null 2>&1 && echo www-data || echo nginx)"
fi
note "Files owned by ${WEB_USER}"

PHP_BIN="$(command -v "php${WHMCS_PHP}" || command -v php)"
note "Using $("$PHP_BIN" -r 'echo "PHP " . PHP_VERSION;')"

# Already installed? The installer would refuse anyway; say so plainly first.
ALREADY_INSTALLED=0
if [[ -f "${WEBROOT}/configuration.php" ]] \
   && grep -qE '^\s*\$db_password\s*=\s*["'"'"'][^"'"'"']+' "${WEBROOT}/configuration.php" 2>/dev/null; then
  ALREADY_INSTALLED=1
fi

if [[ "$ALREADY_INSTALLED" == "1" ]]; then
  note "WHMCS is already installed here — leaving it alone."
  echo "WTP_INSTALL_RESULT=already-installed"
  exit 0
fi

# WHMCS 8.7 and earlier need the template renamed before the installer runs.
# Later releases handle it themselves; renaming an unpopulated template is
# harmless either way.
if [[ -f "${WEBROOT}/configuration.php.new" && ! -f "${WEBROOT}/configuration.php" ]]; then
  mv "${WEBROOT}/configuration.php.new" "${WEBROOT}/configuration.php"
  note "Renamed configuration.php.new"
fi

if [[ -f "${WEBROOT}/configuration.php" ]]; then
  chown "${WEB_USER}:${WEB_USER}" "${WEBROOT}/configuration.php"
  chmod 0640 "${WEBROOT}/configuration.php"
fi

# ---------------------------------------------------------------------------
# Encryption hash
# ---------------------------------------------------------------------------

step "Preparing the configuration"

if [[ -z "${WHMCS_CC_HASH:-}" ]]; then
  # 64 characters, as WHMCS's own documentation specifies.
  WHMCS_CC_HASH="$(openssl rand -base64 128 | tr -d '\n\/+=' | cut -c 1-64)"
  note "Generated a 64-character encryption hash"
else
  note "Using the supplied encryption hash"
fi

if [[ "${#WHMCS_CC_HASH}" -ne 64 ]]; then
  fail "The encryption hash must be exactly 64 characters (got ${#WHMCS_CC_HASH})."
fi

# The JSON reaches the installer on stdin. It is written with a restrictive
# umask, owned by the web user, and shredded whatever happens next.
CONFIG_JSON="$(mktemp)"
chmod 600 "$CONFIG_JSON"
chown "${WEB_USER}:${WEB_USER}" "$CONFIG_JSON"

cleanup() {
  shred -u "$CONFIG_JSON" 2>/dev/null || rm -f "$CONFIG_JSON"
}
trap cleanup EXIT

# Built with PHP's own JSON encoder so a password containing a quote, a
# backslash or a non-ASCII character cannot break the document.
WHMCS_ADMIN_USER="$WHMCS_ADMIN_USER" \
WHMCS_ADMIN_PASSWORD="$WHMCS_ADMIN_PASSWORD" \
WHMCS_LICENSE="$WHMCS_LICENSE" \
WHMCS_DB_HOST="${WHMCS_DB_HOST:-localhost}" \
WHMCS_DB_USER="$WHMCS_DB_USER" \
WHMCS_DB_PASSWORD="$WHMCS_DB_PASSWORD" \
WHMCS_DB_NAME="$WHMCS_DB_NAME" \
WHMCS_CC_HASH="$WHMCS_CC_HASH" \
"$PHP_BIN" -r '
    $payload = [
        "admin" => [
            "username" => getenv("WHMCS_ADMIN_USER"),
            "password" => getenv("WHMCS_ADMIN_PASSWORD"),
        ],
        "configuration" => [
            "license"            => getenv("WHMCS_LICENSE"),
            "db_host"            => getenv("WHMCS_DB_HOST"),
            "db_username"        => getenv("WHMCS_DB_USER"),
            "db_password"        => getenv("WHMCS_DB_PASSWORD"),
            "db_name"            => getenv("WHMCS_DB_NAME"),
            "cc_encryption_hash" => getenv("WHMCS_CC_HASH"),
            "mysql_charset"      => "utf8",
        ],
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
' > "$CONFIG_JSON"

[[ -s "$CONFIG_JSON" ]] || fail "Could not build the installer configuration."

# ---------------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------------

step "Running the WHMCS installer"
note "This contacts WHMCS to validate the licence; it can take a minute."
echo

INSTALL_LOG="$(mktemp)"
set +e
# -i install · -n non-interactive · -c read the configuration from stdin
( cd "$WEBROOT" && sudo -u "$WEB_USER" "$PHP_BIN" -f bin/installer.php -- -i -n -c ) \
  < "$CONFIG_JSON" > "$INSTALL_LOG" 2>&1
INSTALL_STATUS=$?
set -e

# The installer echoes configuration back on failure, so nothing reaches the
# screen unfiltered. Redaction is literal, not a regex: a password containing
# a bracket or a star would otherwise turn into an invalid pattern and fail
# *after* a successful installation.
WTP_R1="$WHMCS_ADMIN_PASSWORD" \
WTP_R2="$WHMCS_DB_PASSWORD" \
WTP_R3="$WHMCS_LICENSE" \
WTP_R4="$WHMCS_CC_HASH" \
"$PHP_BIN" -r '
    $log = (string) stream_get_contents(STDIN);
    foreach (["WTP_R1", "WTP_R2", "WTP_R3", "WTP_R4"] as $var) {
        $secret = (string) getenv($var);
        if ($secret !== "") {
            $log = str_replace($secret, "[redacted]", $log);
        }
    }
    foreach (explode("\n", rtrim($log)) as $line) {
        echo "  " . $line . "\n";
    }
' < "$INSTALL_LOG"
rm -f "$INSTALL_LOG"

if [[ "$INSTALL_STATUS" -ne 0 ]]; then
  fail "The WHMCS installer exited with status ${INSTALL_STATUS}.

  The usual causes:
    · the licence key is wrong, expired, or already bound to another domain
      (a licence is tied to a domain and an IP — reissue it in the WHMCS
       members area for ${WHMCS_DOMAIN})
    · the server cannot reach WHMCS's licensing service over HTTPS
    · the database credentials are wrong
    · configuration.php is not writable by ${WEB_USER}

  Nothing was left half-installed. Fix the cause and re-run, or finish the
  installation in a browser at https://${WHMCS_DOMAIN}/install/install.php"
fi

# Trust the database, not the exit code.
TABLE_COUNT="$(mysql --protocol=socket -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${WHMCS_DB_NAME}'" 2>/dev/null || echo 0)"

if [[ "${TABLE_COUNT:-0}" -lt 50 ]]; then
  fail "The installer reported success but ${WHMCS_DB_NAME} holds only ${TABLE_COUNT} tables.
  Something went wrong; check the output above."
fi

note "WHMCS installed — ${TABLE_COUNT} tables created"

# ---------------------------------------------------------------------------
# System URL
# ---------------------------------------------------------------------------

if [[ -n "${WHMCS_SYSTEM_URL:-}" ]]; then
  step "Setting the System URL"

  # The caller validates the domain, but this value is interpolated into SQL,
  # so it is checked here too rather than trusted across a boundary.
  if [[ ! "$WHMCS_SYSTEM_URL" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?/?$ ]]; then
    fail "Refusing to write an implausible System URL: ${WHMCS_SYSTEM_URL}"
  fi

  # This is the one WHMCS table this project writes to directly. The installer
  # does not take a URL, and every alternative (the admin UI, the API) needs a
  # session or an API credential that does not exist yet. It is exactly what
  # General Settings writes.
  MYSQL_DEFAULTS="$(mktemp)"
  chmod 600 "$MYSQL_DEFAULTS"
  cat > "$MYSQL_DEFAULTS" <<CNF
[client]
user=${WHMCS_DB_USER}
password=${WHMCS_DB_PASSWORD}
host=${WHMCS_DB_HOST:-localhost}
CNF

  mysql --defaults-extra-file="$MYSQL_DEFAULTS" "$WHMCS_DB_NAME" <<SQL || warn "Could not set the System URL; set it in General Settings."
INSERT INTO tblconfiguration (setting, value)
SELECT 'SystemURL', '${WHMCS_SYSTEM_URL}'
WHERE NOT EXISTS (SELECT 1 FROM tblconfiguration WHERE setting = 'SystemURL');
UPDATE tblconfiguration SET value = '${WHMCS_SYSTEM_URL}' WHERE setting = 'SystemURL';
SQL

  shred -u "$MYSQL_DEFAULTS" 2>/dev/null || rm -f "$MYSQL_DEFAULTS"
  note "System URL set to ${WHMCS_SYSTEM_URL}"
fi

# ---------------------------------------------------------------------------
# Hardening
# ---------------------------------------------------------------------------

step "Hardening"

if [[ -d "${WEBROOT}/install" ]]; then
  rm -rf "${WEBROOT}/install"
  note "Removed the install directory"
fi

ADMIN_PATH="admin"
if [[ "${WHMCS_RENAME_ADMIN:-y}" == "y" && -d "${WEBROOT}/admin" ]]; then
  ADMIN_PATH="admin-$(openssl rand -hex 4)"
  mv "${WEBROOT}/admin" "${WEBROOT}/${ADMIN_PATH}"

  # WHMCS reads $customadminpath from configuration.php to find the renamed
  # directory. Without this line the new path 404s.
  if ! grep -q 'customadminpath' "${WEBROOT}/configuration.php"; then
    printf "\n\$customadminpath = '%s';\n" "$ADMIN_PATH" >> "${WEBROOT}/configuration.php"
  else
    sed -i -E "s|^\s*\\\$customadminpath\s*=.*|\$customadminpath = '${ADMIN_PATH}';|" "${WEBROOT}/configuration.php"
  fi
  note "Admin directory renamed to ${ADMIN_PATH}"
fi

# Only now: WHMCS itself asks for 0400 once installation is finished.
chown "${WEB_USER}:${WEB_USER}" "${WEBROOT}/configuration.php"
chmod 0400 "${WEBROOT}/configuration.php"
note "configuration.php set to 0400"

for writable in attachments downloads templates_c; do
  [[ -d "${WEBROOT}/${writable}" ]] || continue
  chown -R "${WEB_USER}:${WEB_USER}" "${WEBROOT}/${writable}"
  chmod -R 0775 "${WEBROOT}/${writable}"
done

# ---------------------------------------------------------------------------
# Provisioner schema
# ---------------------------------------------------------------------------

WORKER="${WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php"
if [[ "${WTP_CREATE_SCHEMA:-y}" == "y" && -f "$WORKER" ]]; then
  step "Creating the provisioner's tables"

  # Ours, not WHMCS's — so creating them early is safe, and it makes the one
  # remaining click (Activate) instant. Activation re-runs the same migrations,
  # which are recorded and therefore a no-op.
  if sudo -u "$WEB_USER" env WTP_ENV_FILE="${WTP_ENV_PATH}" "$PHP_BIN" "$WORKER" --migrate 2>&1 | sed 's/^/  /'; then
    note "Schema ready"
  else
    warn "Could not pre-create the tables; activating the addon will do it."
  fi
fi

# ---------------------------------------------------------------------------

step "WHMCS is installed"
echo "WTP_INSTALL_RESULT=installed"
echo "WTP_ADMIN_PATH=${ADMIN_PATH}"
echo
