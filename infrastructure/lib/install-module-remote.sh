#!/usr/bin/env bash
#
# install-module-remote.sh — runs ON the server, as root.
#
# Installs the WordPress Theme Provisioner into a WHMCS that is already up and
# running. It never touches WHMCS core files and never touches a WHMCS table:
# the only database objects it creates are this module's own mod_wtp_* tables.
#
# Everything it changes is backed up first, and it is safe to run repeatedly.
#
set -euo pipefail

ANSWERS="${1:?usage: $0 /path/to/answers.env}"
# shellcheck disable=SC1090
source "$ANSWERS"

[[ "$(id -u)" == "0" ]] || { echo "This script must run as root." >&2; exit 1; }

step()    { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
note()    { printf '  %s\n' "$*"; }
ok()      { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn()    { printf '  \033[33m!\033[0m %s\n' "$*" >&2; }
fail()    { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; exit 1; }

BACKUP_DIR="/var/backups/wtp-module-$(date -u +%Y%m%dT%H%M%SZ)"
backup() {
  [[ -e "$1" ]] || return 0
  mkdir -p "${BACKUP_DIR}$(dirname "$1")"
  cp -a "$1" "${BACKUP_DIR}$(dirname "$1")/" 2>/dev/null || true
}

CHECK_ONLY="${WTP_CHECK_ONLY:-n}"

# ---------------------------------------------------------------------------
# 1. Find WHMCS
# ---------------------------------------------------------------------------

step "Locating WHMCS"

if [[ -n "${WHMCS_WEBROOT:-}" && -f "${WHMCS_WEBROOT}/init.php" ]]; then
  note "Using the path you gave: ${WHMCS_WEBROOT}"
else
  # Look where WHMCS is normally installed rather than scanning the whole disk.
  FOUND=""
  for candidate in /var/www/whmcs /var/www/html/whmcs /var/www/html \
                   /home/*/public_html/whmcs /home/*/public_html \
                   /usr/share/nginx/html/whmcs /srv/www/whmcs "${WHMCS_WEBROOT:-}"
  do
    [[ -n "$candidate" && -f "${candidate}/init.php" && -d "${candidate}/modules" ]] || continue
    FOUND="$candidate"
    break
  done

  if [[ -z "$FOUND" ]]; then
    fail "Could not find a WHMCS installation.

  Looked in the usual places and found no directory containing both init.php
  and modules/. Re-run with the path, for example:

    ./infrastructure/install-module.sh --webroot /home/billing/public_html"
  fi
  WHMCS_WEBROOT="$FOUND"
  note "Found WHMCS at ${WHMCS_WEBROOT}"
fi

[[ -f "${WHMCS_WEBROOT}/configuration.php" ]] \
  || fail "${WHMCS_WEBROOT}/configuration.php is missing — is WHMCS really installed?"

[[ -s "${WHMCS_WEBROOT}/configuration.php" ]] \
  || fail "${WHMCS_WEBROOT}/configuration.php is empty — finish the WHMCS installer first."

# Read the database credentials WHMCS itself uses. Parsed with PHP rather than
# grep so quoting and escaping behave exactly as WHMCS sees them.
read_config() {
  php -r '
    $file = $argv[1];
    $vars = [];
    // include() would execute; this only extracts the assignments we need.
    $src = (string) file_get_contents($file);
    foreach (["db_host","db_username","db_password","db_name","db_port","customadminpath"] as $name) {
      if (preg_match("/\\\$" . $name . "\\s*=\\s*([\"\x27])(.*?)\\1\\s*;/s", $src, $m)) {
        $vars[$name] = $m[2];
      }
    }
    foreach ($vars as $k => $v) { echo $k . "=" . $v . "\n"; }
  ' "$1"
}

CONFIG_VALUES="$(read_config "${WHMCS_WEBROOT}/configuration.php")"
DB_HOST="$(printf '%s\n' "$CONFIG_VALUES" | sed -n 's/^db_host=//p')"
DB_USER="$(printf '%s\n' "$CONFIG_VALUES" | sed -n 's/^db_username=//p')"
DB_PASS="$(printf '%s\n' "$CONFIG_VALUES" | sed -n 's/^db_password=//p')"
DB_NAME="$(printf '%s\n' "$CONFIG_VALUES" | sed -n 's/^db_name=//p')"
ADMIN_PATH="$(printf '%s\n' "$CONFIG_VALUES" | sed -n 's/^customadminpath=//p')"
[[ -n "$ADMIN_PATH" ]] || ADMIN_PATH="admin"

[[ -n "$DB_NAME" ]] || fail "Could not read the database name out of configuration.php."
note "Database: ${DB_NAME} on ${DB_HOST:-localhost}"
note "Admin directory: ${ADMIN_PATH}"

# Whose files are these? Read a file WHMCS definitely owns.
WEB_USER="$(stat -c '%U' "${WHMCS_WEBROOT}/init.php" 2>/dev/null || true)"
case "$WEB_USER" in
  ''|UNKNOWN|root)
    WEB_USER="$(id -u www-data >/dev/null 2>&1 && echo www-data || echo nginx)"
    warn "WHMCS files are owned by root or an unknown user; assuming ${WEB_USER}"
    ;;
esac
note "Web user: ${WEB_USER}"

# ---------------------------------------------------------------------------
# 2. Check PHP
# ---------------------------------------------------------------------------

step "Checking PHP"

PHP_BIN="$(command -v php || true)"
[[ -n "$PHP_BIN" ]] || fail "No php binary on PATH."

PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
note "CLI: PHP ${PHP_VERSION} (${PHP_BIN})"

case "$PHP_VERSION" in
  8.1|8.2|8.3) ok "Supported by WHMCS 8.13" ;;
  *) warn "WHMCS 8.13 supports 8.1, 8.2 and 8.3. This module needs 7.4+, so it will run, but check WHMCS's own requirements." ;;
esac

MODULES="$( { "$PHP_BIN" -m 2>/dev/null || true; } | tr 'A-Z' 'a-z')"
MISSING=""
for ext in curl zip openssl json mbstring pdo_mysql; do
  case "$MODULES" in
    *"$ext"*) : ;;
    *) MISSING="${MISSING} ${ext}" ;;
  esac
done

if [[ -n "$MISSING" ]]; then
  fail "The PHP CLI is missing extensions this module needs:${MISSING}

  Install them and re-run. On Debian/Ubuntu, for PHP ${PHP_VERSION}:
    apt-get install${MISSING// / php${PHP_VERSION}-}"
fi
ok "curl, zip, openssl, json, mbstring, pdo_mysql all present"

case "$MODULES" in
  *ioncube*) ok "ionCube Loader present" ;;
  *) warn "ionCube Loader is not loaded in the CLI. WHMCS needs it; the worker will not be able to boot WHMCS without it." ;;
esac

# ---------------------------------------------------------------------------
# 3. Storage and secrets
# ---------------------------------------------------------------------------

step "Package storage and encryption key"

if [[ "$CHECK_ONLY" == "y" ]]; then
  note "(check only — nothing will be created)"
else
  mkdir -p "${WTP_STORAGE_PATH}"/{agent,demos,themes,core}
  chown -R "${WEB_USER}:${WEB_USER}" "$WTP_STORAGE_PATH"
  chmod -R 0750 "$WTP_STORAGE_PATH"
  ok "Storage at ${WTP_STORAGE_PATH}"

  ENV_DIR="$(dirname "$WTP_ENV_PATH")"
  mkdir -p "$ENV_DIR"
  chown "root:${WEB_USER}" "$ENV_DIR"
  chmod 0750 "$ENV_DIR"

  if [[ -f "$WTP_ENV_PATH" ]] && grep -q '^WTP_MASTER_KEY=' "$WTP_ENV_PATH"; then
    ok "Encryption key already present — keeping it"
    warn "Replacing it would make every stored credential undecryptable."
  else
    backup "$WTP_ENV_PATH"
    MASTER_KEY="$(head -c 32 /dev/urandom | base64 | tr '+/' '-_' | tr -d '=')"
    cat > "$WTP_ENV_PATH" <<ENV
# WHMCS WordPress Theme Provisioner — secrets and overrides.
#
# Never commit this file, and never move it inside the web root.
# Losing WTP_MASTER_KEY makes every stored agent secret and every encrypted
# job payload unreadable. Back it up wherever you keep your other keys.

WTP_MASTER_KEY=${MASTER_KEY}
WTP_STORAGE_PATH=${WTP_STORAGE_PATH}
WTP_API_BASE_URL=${WTP_API_BASE_URL}
WTP_ENFORCE_HTTPS=1
WTP_LOG_LEVEL=info
ENV
    chown "root:${WEB_USER}" "$WTP_ENV_PATH"
    chmod 0640 "$WTP_ENV_PATH"
    ok "Generated a new encryption key in ${WTP_ENV_PATH}"
  fi

  # PHP has to be able to find it without an environment variable being set
  # by hand on every invocation.
  for pool in /etc/php/*/fpm/pool.d/www.conf /etc/php-fpm.d/www.conf; do
    [[ -f "$pool" ]] || continue
    if ! grep -q '^env\[WTP_ENV_FILE\]' "$pool"; then
      backup "$pool"
      printf '\nenv[WTP_ENV_FILE] = %s\n' "$WTP_ENV_PATH" >> "$pool"
      note "Registered WTP_ENV_FILE in $(basename "$(dirname "$(dirname "$pool")")")"
      RESTART_FPM=1
    fi
  done
fi

# ---------------------------------------------------------------------------
# 4. Module files
# ---------------------------------------------------------------------------

step "Module files"

WORKDIR="$(dirname "$ANSWERS")"

if [[ "$CHECK_ONLY" != "y" ]]; then
  [[ -f "${WORKDIR}/wtp-whmcs.tar.gz" ]] || fail "The module bundle did not arrive on the server."

  # --no-same-owner: the bundle is built on a workstation, whose numeric uid
  # means nothing here.
  tar --no-same-owner -xzf "${WORKDIR}/wtp-whmcs.tar.gz" -C "$WHMCS_WEBROOT"

  for path in \
    "${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner" \
    "${WHMCS_WEBROOT}/modules/servers/wpthemehost" \
    "${WHMCS_WEBROOT}/provisioning"
  do
    [[ -d "$path" ]] || continue
    chown -R "${WEB_USER}:${WEB_USER}" "$path"
    find "$path" -type d -exec chmod 0755 {} +
    find "$path" -type f -exec chmod 0644 {} +
  done

  if [[ -f "${WORKDIR}/agent.zip" ]]; then
    install -D -m 0640 -o "$WEB_USER" -g "$WEB_USER" \
      "${WORKDIR}/agent.zip" "${WTP_STORAGE_PATH}/agent/whmcs-theme-provisioner.zip"
  fi
fi

for path in modules/addons/wpthemeprovisioner modules/servers/wpthemehost provisioning/v1; do
  if [[ -e "${WHMCS_WEBROOT}/${path}" ]]; then
    ok "${path}"
  else
    fail "${path} is missing from ${WHMCS_WEBROOT}"
  fi
done

if [[ -f "${WTP_STORAGE_PATH}/agent/whmcs-theme-provisioner.zip" ]]; then
  ok "agent package ($(stat -c '%s' "${WTP_STORAGE_PATH}/agent/whmcs-theme-provisioner.zip") bytes)"
else
  fail "The agent package is not in ${WTP_STORAGE_PATH}/agent/"
fi

# ---------------------------------------------------------------------------
# 5. Web server route for /provisioning/v1
# ---------------------------------------------------------------------------

step "Web server route"

WEB_SERVER="unknown"
command -v nginx  >/dev/null 2>&1 && WEB_SERVER="nginx"
command -v apache2 >/dev/null 2>&1 && WEB_SERVER="apache"
command -v httpd  >/dev/null 2>&1 && WEB_SERVER="apache"
note "Detected: ${WEB_SERVER}"

ROUTE_OK=0
probe_endpoint() {
  local code
  code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 12 \
          "${WTP_API_BASE_URL}/provisioning/v1/health" 2>/dev/null || echo 000)"
  [[ "$code" == "200" || "$code" == "503" ]]
}

if probe_endpoint; then
  ok "The provisioning endpoint already answers"
  ROUTE_OK=1
elif [[ "$WEB_SERVER" == "nginx" && "$CHECK_ONLY" != "y" ]]; then
  # Find the vhost whose root is this WHMCS and add the location block, with a
  # backup and a config test. A billing server is not a place to guess.
  VHOST=""
  for f in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
    [[ -f "$f" ]] || continue
    if grep -qE "^[[:space:]]*root[[:space:]]+${WHMCS_WEBROOT}/?;" "$f" 2>/dev/null; then
      VHOST="$f"
      break
    fi
  done

  if [[ -z "$VHOST" ]]; then
    warn "Could not identify which nginx vhost serves ${WHMCS_WEBROOT}."
  elif grep -q 'provisioning/v1' "$VHOST"; then
    note "The route is already in $(basename "$VHOST") but the endpoint did not answer."
  else
    backup "$VHOST"
    # Insert just before the last closing brace of the file's final server block.
    TMP_VHOST="$(mktemp)"
    awk '
      { lines[NR] = $0 }
      END {
        for (i = NR; i >= 1; i--) { if (lines[i] ~ /^[[:space:]]*}[[:space:]]*$/) { last = i; break } }
        for (i = 1; i <= NR; i++) {
          if (i == last) {
            print "";
            print "    # WHMCS WordPress Theme Provisioner — agent API.";
            print "    location /provisioning/v1 {";
            print "        try_files $uri /provisioning/v1/index.php$is_args$args;";
            print "    }";
          }
          print lines[i];
        }
      }
    ' "$VHOST" > "$TMP_VHOST"

    cp "$TMP_VHOST" "$VHOST"
    rm -f "$TMP_VHOST"

    if nginx -t > /tmp/wtp-nginx.log 2>&1; then
      systemctl reload nginx 2>/dev/null || service nginx reload 2>/dev/null || true
      ok "Added the route to $(basename "$VHOST")"
    else
      warn "nginx rejected the change; restoring the original:"
      sed 's/^/      /' /tmp/wtp-nginx.log >&2
      cp "${BACKUP_DIR}${VHOST}" "$VHOST"
      nginx -t >/dev/null 2>&1 && { systemctl reload nginx 2>/dev/null || true; }
    fi
    rm -f /tmp/wtp-nginx.log
  fi
fi

if [[ "$ROUTE_OK" != "1" ]]; then
  if probe_endpoint; then
    ok "The provisioning endpoint answers"
    ROUTE_OK=1
  fi
fi

if [[ "$ROUTE_OK" != "1" ]]; then
  warn "The provisioning endpoint is not reachable at"
  warn "  ${WTP_API_BASE_URL}/provisioning/v1/health"
  if [[ "$WEB_SERVER" == "nginx" ]]; then
    warn "Add this inside the server block that serves ${WHMCS_WEBROOT}:"
    warn '    location /provisioning/v1 { try_files $uri /provisioning/v1/index.php$is_args$args; }'
  else
    warn "On Apache the shipped provisioning/v1/.htaccess handles it, provided"
    warn "AllowOverride is not None for ${WHMCS_WEBROOT}."
  fi
  warn "Provisioning cannot work until a hosting server can reach this URL."
fi

# ---------------------------------------------------------------------------
# 6. Cron
# ---------------------------------------------------------------------------

step "Cron"

CRON_FILE="/etc/cron.d/whmcs-wtp"
WORKER="${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php"

if [[ "$CHECK_ONLY" != "y" ]]; then
  backup "$CRON_FILE"
  cat > "$CRON_FILE" <<CRON
# WordPress Theme Provisioner — provisioning queue.
# Written by install-module.sh. One bounded tick a minute.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
WTP_ENV_FILE=${WTP_ENV_PATH}

* * * * * ${WEB_USER} ${PHP_BIN} -q ${WORKER} >/dev/null 2>&1
CRON
  chmod 0644 "$CRON_FILE"
  systemctl enable --now cron >/dev/null 2>&1 || systemctl enable --now crond >/dev/null 2>&1 || true
  ok "Installed ${CRON_FILE}"
else
  [[ -f "$CRON_FILE" ]] && ok "${CRON_FILE} present" || warn "${CRON_FILE} is not installed"
fi

# WHMCS's own cron is the operator's business, but its absence breaks orders.
if ! crontab -l 2>/dev/null | grep -q 'cron.php' \
   && ! grep -rq 'cron.php' /etc/cron.d/ /etc/crontab 2>/dev/null; then
  warn "No WHMCS cron entry found anywhere. WHMCS needs its own, separate from"
  warn "this one — see WHMCS admin → System Settings → Automation Settings."
fi

# ---------------------------------------------------------------------------
# 7. Schema
# ---------------------------------------------------------------------------

step "Database tables"

if [[ "$CHECK_ONLY" != "y" ]]; then
  if sudo -n -u "$WEB_USER" env WTP_ENV_FILE="$WTP_ENV_PATH" "$PHP_BIN" "$WORKER" --migrate 2>&1 | sed 's/^/  /'; then
    ok "Schema ready"
  else
    warn "Could not create the tables from the command line."
    warn "Activating the addon in WHMCS does the same thing — try that instead."
  fi
fi

TABLE_COUNT="$(mysql --protocol=socket -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema='${DB_NAME}' AND table_name LIKE 'mod\\_wtp\\_%'" 2>/dev/null || echo 0)"
note "mod_wtp_* tables: ${TABLE_COUNT:-0} of 14"

# ---------------------------------------------------------------------------
# 8. Restart and verify
# ---------------------------------------------------------------------------

if [[ "${RESTART_FPM:-0}" == "1" ]]; then
  step "Restarting php-fpm"
  for svc in php-fpm "php${PHP_VERSION}-fpm"; do
    systemctl restart "$svc" >/dev/null 2>&1 && { ok "restarted ${svc}"; break; }
  done
fi

step "Self-test"
sudo -n -u "$WEB_USER" env WTP_ENV_FILE="$WTP_ENV_PATH" "$PHP_BIN" "$WORKER" --selftest 2>&1 | sed 's/^/  /' || true

# ---------------------------------------------------------------------------

step "Summary"
note "WHMCS          ${WHMCS_WEBROOT}"
note "Admin URL      ${WTP_API_BASE_URL}/${ADMIN_PATH}/"
note "Web user       ${WEB_USER}"
note "Storage        ${WTP_STORAGE_PATH}"
note "Encryption key ${WTP_ENV_PATH}"
note "Worker cron    ${CRON_FILE}"
[[ -d "$BACKUP_DIR" ]] && note "Backups        ${BACKUP_DIR}"

echo "WTP_ADMIN_PATH=${ADMIN_PATH}"
echo "WTP_WEBROOT=${WHMCS_WEBROOT}"
echo "WTP_WEB_USER=${WEB_USER}"
echo
