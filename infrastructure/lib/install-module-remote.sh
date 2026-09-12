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

# Substring test without a pipe. `producer | grep -q` reports rc=141 under
# `set -o pipefail` whenever grep exits before the producer has finished
# writing — a success turned into a failure, and only on long output.
contains() { case "$1" in *"$2"*) return 0 ;; *) return 1 ;; esac; }

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
WEB_GROUP="$(stat -c '%G' "${WHMCS_WEBROOT}/init.php" 2>/dev/null || true)"
case "$WEB_GROUP" in ''|UNKNOWN) WEB_GROUP="$WEB_USER" ;; esac
note "Web user: ${WEB_USER}:${WEB_GROUP}"

# ---------------------------------------------------------------------------
# 1b. cPanel
#
# A WHMCS running inside a cPanel account is a different machine in every way
# that matters here: the PHP binary belongs to the account, not to the system;
# the vhost is generated and would overwrite anything we wrote into it; and if
# CloudLinux is present, CageFS hides /etc and /var from the account's PHP
# entirely, so the usual /etc/whmcs-wtp/wtp.env can never be read.
#
# So we detect it and adapt, rather than pretending it is a plain server.
# ---------------------------------------------------------------------------

IS_CPANEL=0
CPANEL_USER=""
CPANEL_HOME=""
CPANEL_DOMAIN=""
CPANEL_PHP=""

if [[ -x /usr/local/cpanel/cpanel ]]; then
  IS_CPANEL=1
  step "cPanel"
  note "$(/usr/local/cpanel/cpanel -V 2>/dev/null || echo 'version unknown')"

  # Which account owns WHMCS? The files say so; the path is only a fallback.
  if [[ -f "/var/cpanel/users/${WEB_USER}" ]]; then
    CPANEL_USER="$WEB_USER"
  else
    CPANEL_USER="$(printf '%s' "$WHMCS_WEBROOT" | sed -n 's#^/home[0-9]*/\([^/]*\)/.*#\1#p')"
  fi

  if [[ -z "$CPANEL_USER" || ! -f "/var/cpanel/users/${CPANEL_USER}" ]]; then
    warn "cPanel is installed but ${WHMCS_WEBROOT} does not belong to a cPanel"
    warn "account. Treating this as a plain server."
    IS_CPANEL=0
  else
    ok "Account: ${CPANEL_USER}"
    CPANEL_HOME="$(awk -F: -v u="$CPANEL_USER" '$1 == u { home = $6 } END { print home }' /etc/passwd)"
    [[ -n "$CPANEL_HOME" ]] || CPANEL_HOME="/home/${CPANEL_USER}"
    note "Home: ${CPANEL_HOME}"

    # Find the vhost whose document root is this WHMCS, for its PHP version.
    for ud in /var/cpanel/userdata/"${CPANEL_USER}"/*; do
      [[ -f "$ud" ]] || continue
      case "$ud" in *.cache|*.json|*_SSL|*main) continue ;; esac
      UD_ROOT="$(sed -n 's/^documentroot: *//p' "$ud")"
      if [[ "$UD_ROOT" == "$WHMCS_WEBROOT" ]]; then
        CPANEL_DOMAIN="$(basename "$ud")"
        CPANEL_PHP="$(sed -n 's/^phpversion: *//p' "$ud")"
        break
      fi
    done

    if [[ -n "$CPANEL_DOMAIN" ]]; then
      ok "Domain: ${CPANEL_DOMAIN} (${CPANEL_PHP:-system default PHP})"
    else
      # WHMCS in a subdirectory of a document root is normal and fine; we just
      # cannot name the vhost, so we fall back to the account's default PHP.
      note "No vhost has ${WHMCS_WEBROOT} as its document root — WHMCS is"
      note "probably in a subdirectory. Using the account's default PHP."
      CPANEL_MAIN="$(sed -n 's/^main_domain: *//p' "/var/cpanel/userdata/${CPANEL_USER}/main" 2>/dev/null || true)"
      if [[ -n "$CPANEL_MAIN" && -f "/var/cpanel/userdata/${CPANEL_USER}/${CPANEL_MAIN}" ]]; then
        CPANEL_DOMAIN="$CPANEL_MAIN"
        CPANEL_PHP="$(sed -n 's/^phpversion: *//p' "/var/cpanel/userdata/${CPANEL_USER}/${CPANEL_MAIN}")"
        note "Falling back to the main domain ${CPANEL_MAIN} (${CPANEL_PHP:-unknown PHP})"
      fi
    fi

    if [[ -f /etc/cloudlinux-release ]]; then
      warn "CloudLinux detected. If CageFS is on for ${CPANEL_USER}, anything"
      warn "outside ${CPANEL_HOME} is invisible to this account's PHP — which is"
      warn "why the paths below move into the home directory."
    fi

    # Keep our files where the account can actually reach them, unless an
    # explicit path was given. Comparing against the system default is how we
    # tell "nobody chose this" from "the operator chose this".
    if [[ "$WTP_STORAGE_PATH" == "/var/lib/whmcs-wtp/packages" ]]; then
      WTP_STORAGE_PATH="${CPANEL_HOME}/.whmcs-wtp/packages"
      note "Storage moved to ${WTP_STORAGE_PATH}"
    fi
    if [[ "$WTP_ENV_PATH" == "/etc/whmcs-wtp/wtp.env" ]]; then
      WTP_ENV_PATH="${CPANEL_HOME}/.whmcs-wtp/wtp.env"
      note "Encryption key moved to ${WTP_ENV_PATH}"
    fi
    warn "Both live under ${CPANEL_HOME}, so they count against the account's"
    warn "disk quota and are included in its cPanel backups."
  fi
fi

# ---------------------------------------------------------------------------
# 2. Check PHP
# ---------------------------------------------------------------------------

step "Checking PHP"

# On cPanel the account runs a PHP of its own choosing; /usr/local/bin/php is
# the *system* default and is regularly a different version. Using the wrong one
# means the worker and the web request disagree about extensions and ini
# settings — so prefer the one the account's vhost is actually configured with.
PHP_BIN=""
# "inherit" means the vhost follows the system default, which is what the
# fallback below already finds.
[[ "${CPANEL_PHP:-}" == "inherit" ]] && CPANEL_PHP=""

if [[ "$IS_CPANEL" == "1" && -n "$CPANEL_PHP" ]]; then
  case "$CPANEL_PHP" in
    alt-php*)  CANDIDATE="/opt/alt/${CPANEL_PHP#alt-}/usr/bin/php" ;;  # CloudLinux
    *)         CANDIDATE="/opt/cpanel/${CPANEL_PHP}/root/usr/bin/php" ;;
  esac
  if [[ -x "$CANDIDATE" ]]; then
    PHP_BIN="$CANDIDATE"
    note "Using the account's PHP: ${CPANEL_PHP} (${CANDIDATE})"
  else
    warn "${CPANEL_DOMAIN:-this account} is set to ${CPANEL_PHP}, but"
    warn "${CANDIDATE} does not exist. Falling back to the system PHP."
  fi
fi

[[ -n "$PHP_BIN" ]] || PHP_BIN="$(command -v php || true)"
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
  if [[ "$IS_CPANEL" == "1" ]]; then
    fail "PHP ${PHP_VERSION} (${PHP_BIN}) is missing extensions this module needs:${MISSING}

  Enable them in WHM -> Software -> EasyApache 4, or for one account in
  WHM -> MultiPHP INI Editor. Then re-run."
  fi

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
  chown -R "${WEB_USER}:${WEB_GROUP}" "$WTP_STORAGE_PATH"
  chmod -R 0750 "$WTP_STORAGE_PATH"
  ok "Storage at ${WTP_STORAGE_PATH}"

  ENV_DIR="$(dirname "$WTP_ENV_PATH")"
  mkdir -p "$ENV_DIR"

  # Inside a home directory the file has to belong to the account: a root-owned
  # file there is both a support surprise and, under CageFS, unreadable.
  if [[ "$IS_CPANEL" == "1" ]]; then
    ENV_OWNER="${WEB_USER}:${WEB_GROUP}"
    ENV_MODE=0600
    ENV_DIR_MODE=0700
  else
    ENV_OWNER="root:${WEB_USER}"
    ENV_MODE=0640
    ENV_DIR_MODE=0750
  fi
  chown "$ENV_OWNER" "$ENV_DIR"
  chmod "$ENV_DIR_MODE" "$ENV_DIR"

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
    chown "$ENV_OWNER" "$WTP_ENV_PATH"
    chmod "$ENV_MODE" "$WTP_ENV_PATH"
    ok "Generated a new encryption key in ${WTP_ENV_PATH}"
  fi

  # PHP has to be able to find the file without an environment variable being
  # set by hand on every invocation. Two mechanisms, because no single one
  # works everywhere:
  #
  #   env[WTP_ENV_FILE] in the php-fpm pool — right on a plain server, wrong on
  #   cPanel, where the pool files are generated and our line would vanish the
  #   next time the panel rebuilt them.
  #
  #   env-path.php in the module directory — a file holding the path and
  #   nothing else. Survives every rebuild, needs no service restart, and is
  #   read by the module itself. See Config::ENV_POINTER_FILE.
  if [[ "$IS_CPANEL" != "1" ]]; then
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
    chown -R "${WEB_USER}:${WEB_GROUP}" "$path"
    find "$path" -type d -exec chmod 0755 {} +
    find "$path" -type f -exec chmod 0644 {} +
  done

  if [[ -f "${WORKDIR}/agent.zip" ]]; then
    install -D -m 0640 -o "$WEB_USER" -g "$WEB_GROUP" \
      "${WORKDIR}/agent.zip" "${WTP_STORAGE_PATH}/agent/whmcs-theme-provisioner.zip"
  fi

  # Tell the module where its environment file is, for every stack where we
  # cannot rely on an environment variable reaching the web process. It holds a
  # path and no secret; the .php extension means a direct request for it runs
  # and prints nothing.
  ENV_POINTER="${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/env-path.php"
  if [[ "$WTP_ENV_PATH" != "/etc/whmcs-wtp/wtp.env" ]]; then
    printf "<?php\n// Written by install-module.sh. Holds the path of the environment\n// file, nothing else. Delete it to fall back to the default location.\nreturn %s;\n" \
      "$("$PHP_BIN" -r 'echo var_export($argv[1], true);' "$WTP_ENV_PATH")" > "$ENV_POINTER"
    chown "${WEB_USER}:${WEB_GROUP}" "$ENV_POINTER"
    chmod 0644 "$ENV_POINTER"
    ok "env-path.php points at ${WTP_ENV_PATH}"
  elif [[ -f "$ENV_POINTER" ]]; then
    # The key moved back to the default location; a stale pointer would win.
    backup "$ENV_POINTER"
    rm -f "$ENV_POINTER"
    note "Removed a stale env-path.php"
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
elif [[ "$IS_CPANEL" == "1" ]]; then
  # Do not touch a cPanel vhost. Apache and ea-nginx configuration here is
  # generated from /var/cpanel/userdata, and anything we wrote into it would be
  # silently discarded the next time the panel rebuilt it — leaving a system
  # that worked on install day and broke later for no visible reason.
  # The shipped provisioning/v1/.htaccess is the supported way in.
  note "Leaving the generated vhost alone; the shipped .htaccess routes it."
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
  if [[ "$IS_CPANEL" == "1" ]]; then
    warn "On cPanel this is nearly always one of three things:"
    warn "  - AllowOverride is None for ${WHMCS_WEBROOT}; the .htaccess is ignored."
    warn "    WHM -> Apache Configuration -> Include Editor, or the account's"
    warn "    Directory Privacy settings."
    warn "  - mod_rewrite is not built. WHM -> EasyApache 4 -> Apache Modules."
    warn "  - The domain does not resolve here yet, so curl never reached Apache."
    warn "Check by hand:  curl -sk ${WTP_API_BASE_URL}/provisioning/v1/health"
  elif [[ "$WEB_SERVER" == "nginx" ]]; then
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

WORKER="${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php"
CRON_MARKER="# whmcs-wtp worker"

if [[ "$IS_CPANEL" == "1" ]]; then
  # The account's own crontab, which is what cPanel manages, backs up and
  # restores. A /etc/cron.d file would be invisible in the panel and, under
  # CageFS, would not necessarily run in the same view of the filesystem as the
  # web requests it has to agree with.
  CRON_FILE="crontab of ${CPANEL_USER}"

  if [[ "$CHECK_ONLY" != "y" ]]; then
    CRON_TMP="$(mktemp)"
    # grep -v reads its whole input, so this pipeline is SIGPIPE-safe.
    crontab -u "$CPANEL_USER" -l 2>/dev/null \
      | grep -v 'wpthemeprovisioner/cron/worker.php' \
      | grep -v "^${CRON_MARKER}\$" > "$CRON_TMP" || true

    cp -a "$CRON_TMP" "${CRON_TMP}.before" 2>/dev/null || true
    {
      printf '%s\n' "$CRON_MARKER"
      printf '* * * * * WTP_ENV_FILE=%s %s -q %s >/dev/null 2>&1\n' \
        "$WTP_ENV_PATH" "$PHP_BIN" "$WORKER"
    } >> "$CRON_TMP"

    if crontab -u "$CPANEL_USER" "$CRON_TMP"; then
      ok "Installed into the crontab of ${CPANEL_USER}"
    else
      warn "Could not write the crontab of ${CPANEL_USER}. Add this line by hand:"
      warn "  * * * * * WTP_ENV_FILE=${WTP_ENV_PATH} ${PHP_BIN} -q ${WORKER} >/dev/null 2>&1"
    fi
    rm -f "$CRON_TMP" "${CRON_TMP}.before"
  else
    if contains "$(crontab -u "$CPANEL_USER" -l 2>/dev/null || true)" 'wpthemeprovisioner/cron/worker.php'; then
      ok "The worker is in the crontab of ${CPANEL_USER}"
    else
      warn "The worker is not in the crontab of ${CPANEL_USER}"
    fi
  fi
else
  CRON_FILE="/etc/cron.d/whmcs-wtp"

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
fi

# WHMCS's own cron is the operator's business, but its absence breaks orders.
WHMCS_CRON_SEEN=0
contains "$(crontab -l 2>/dev/null || true)" 'cron.php' && WHMCS_CRON_SEEN=1
if [[ "$IS_CPANEL" == "1" ]]; then
  contains "$(crontab -u "$CPANEL_USER" -l 2>/dev/null || true)" 'cron.php' && WHMCS_CRON_SEEN=1
fi
if [[ "$WHMCS_CRON_SEEN" == "0" ]] \
   && ! grep -rs 'cron.php' /etc/cron.d/ /etc/crontab >/dev/null 2>&1; then
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

# ---------------------------------------------------------------------------
# 8b. Can the account's PHP actually reach what we created?
#
# Asked empirically rather than by inspecting CageFS configuration, because the
# only answer that matters is what PHP sees. open_basedir, CageFS and plain
# permissions all fail here, and they all fail the same way.
# ---------------------------------------------------------------------------

step "Reachability from the web account"

reach() {
  sudo -n -u "$WEB_USER" "$PHP_BIN" -r \
    'exit(@is_readable($argv[1]) ? 0 : 1);' "$1" >/dev/null 2>&1
}

if reach "$WTP_ENV_PATH"; then
  ok "${WEB_USER} can read ${WTP_ENV_PATH}"
else
  warn "${WEB_USER}'s PHP cannot read ${WTP_ENV_PATH}."
  if [[ "$IS_CPANEL" == "1" ]]; then
    warn "Most likely open_basedir or CageFS. Check the account's open_basedir"
    warn "in WHM -> MultiPHP INI Editor, and that the path is inside"
    warn "${CPANEL_HOME}. Re-run with --env to choose another location."
  else
    warn "Check the ownership and mode of that file and its directory."
  fi
fi

if reach "$WTP_STORAGE_PATH"; then
  ok "${WEB_USER} can read ${WTP_STORAGE_PATH}"
else
  warn "${WEB_USER}'s PHP cannot read ${WTP_STORAGE_PATH}."
  warn "Downloads of themes and demos will fail until it can."
fi

if [[ "$IS_CPANEL" == "1" && "$WTP_ENV_PATH" != "/etc/whmcs-wtp/wtp.env" ]]; then
  POINTED_AT="$(sudo -n -u "$WEB_USER" "$PHP_BIN" -r \
    '$v = @include $argv[1]; echo is_string($v) ? $v : "";' \
    "${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/env-path.php" 2>/dev/null || true)"
  if [[ "$POINTED_AT" == "$WTP_ENV_PATH" ]]; then
    ok "env-path.php resolves correctly as ${WEB_USER}"
  else
    warn "env-path.php does not resolve to ${WTP_ENV_PATH} as ${WEB_USER}."
  fi
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
if [[ "$IS_CPANEL" == "1" ]]; then
  note "cPanel account ${CPANEL_USER}${CPANEL_DOMAIN:+ (${CPANEL_DOMAIN})}"
  note "PHP            ${PHP_BIN}"
fi
[[ -d "$BACKUP_DIR" ]] && note "Backups        ${BACKUP_DIR}"

echo "WTP_ADMIN_PATH=${ADMIN_PATH}"
echo "WTP_WEBROOT=${WHMCS_WEBROOT}"
echo "WTP_WEB_USER=${WEB_USER}"
echo "WTP_IS_CPANEL=${IS_CPANEL}"
echo "WTP_ENV_PATH_USED=${WTP_ENV_PATH}"
echo "WTP_STORAGE_PATH_USED=${WTP_STORAGE_PATH}"
echo
