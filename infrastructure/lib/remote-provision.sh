#!/usr/bin/env bash
#
# remote-provision.sh — runs ON the VPS, as root, invoked by setup-whmcs.sh.
#
# Brings a bare Ubuntu/Debian or AlmaLinux/Rocky box to the point where the
# WHMCS web installer can be opened, and prepares everything the WordPress
# Theme Provisioner needs.
#
# Idempotent: safe to re-run. Existing configuration is backed up, not
# overwritten blindly, and packages already present are left alone.
#
set -euo pipefail

ANSWERS_FILE="${1:-}"
[[ -f "$ANSWERS_FILE" ]] || { echo "usage: $0 /path/to/answers.env" >&2; exit 2; }
# shellcheck disable=SC1090
source "$ANSWERS_FILE"

[[ "$(id -u)" == "0" ]] || { echo "This script must run as root." >&2; exit 1; }

BACKUP_DIR="/var/backups/wtp-setup-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$BACKUP_DIR"

# ---------------------------------------------------------------------------
# Keep apt from restarting the service we arrived on
# ---------------------------------------------------------------------------
#
# Ubuntu 22.04 and newer ship `needrestart`, which inspects running processes
# after every package operation and restarts anything using an outdated
# library. One of those things is ssh — so installing PHP can kill the very
# connection driving the installation, halfway through.
#
# `l` means list only: report what would need restarting and touch nothing.
# The dropped file covers the case where a package operation spawns its own
# environment, which the variables alone would not reach.

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=l
export NEEDRESTART_SUSPEND=1

if [ -d /etc/needrestart ]; then
    mkdir -p /etc/needrestart/conf.d
    cat > /etc/needrestart/conf.d/99-wtp.conf <<'NRCONF'
# Written by the WHMCS provisioner during installation.
# List services that need restarting; never restart them automatically —
# restarting sshd would sever the connection running this script.
$nrconf{restart} = 'l';
$nrconf{kernelhints} = 0;
NRCONF
fi

step()  { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
note()  { printf '  %s\n' "$*"; }
warn()  { printf '  \033[33m! %s\033[0m\n' "$*" >&2; }
fail()  { printf '  \033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# Preserve anything we are about to replace, so a re-run is reversible.
backup() {
  [[ -e "$1" ]] || return 0
  local target="${BACKUP_DIR}$(dirname "$1")"
  mkdir -p "$target"
  cp -a "$1" "$target/" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Distribution detection
# ---------------------------------------------------------------------------

source /etc/os-release
OS_ID="${ID}"
OS_VERSION="${VERSION_ID%%.*}"

case "$OS_ID" in
  ubuntu|debian) FAMILY="debian" ;;
  almalinux|rocky|rhel|centos|fedora) FAMILY="rhel" ;;
  *) fail "Unsupported distribution: ${ID} ${VERSION_ID}" ;;
esac

PHP="${WHMCS_PHP}"
WEB_USER="www-data"
[[ "$FAMILY" == "rhel" ]] && WEB_USER="nginx"

step "Detected ${PRETTY_NAME} (${FAMILY} family), targeting PHP ${PHP}"

# ---------------------------------------------------------------------------
# Packages
# ---------------------------------------------------------------------------

step "Installing packages"

# WHMCS's documented requirements plus what the provisioner needs: zip for
# packages, intl and bcmath for currency handling. Split into two lists so a
# distribution that has dropped an optional extension does not abort the
# entire installation.
PHP_EXTENSIONS=(cli fpm mysqlnd curl gd mbstring xml zip intl bcmath opcache)
PHP_EXTENSIONS_OPTIONAL=(soap imap)

if [[ "$FAMILY" == "debian" ]]; then
  # Keep existing config files without prompting: an interactive dpkg question
  # would hang forever with nobody there to answer it.
  APT_OPTS=(-y -qq -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)

  # `apt-get update` returns non-zero if any single source fails, and under
  # set -e that used to end the run with no message at all — the hardest kind
  # of failure to diagnose. Report it and continue: a stale index is usually
  # still workable, and the install below will say so plainly if it is not.
  if ! apt-get update -qq 2> /tmp/wtp-apt-update.log; then
    warn "apt-get update reported a problem:"
    tail -10 /tmp/wtp-apt-update.log | sed 's/^/      /' >&2
    warn "Continuing — the package installation below will fail clearly if this matters."
  fi
  rm -f /tmp/wtp-apt-update.log

  apt-get install "${APT_OPTS[@]}" \
    ca-certificates curl wget gnupg lsb-release apt-transport-https \
    software-properties-common unzip tar cron rsync >/dev/null

  # No pipe here on purpose. `apt-cache policy | grep -q` reports failure for
  # every package on this planet: apt-cache prints a version table after the
  # candidate line, grep -q exits at the candidate, apt-cache takes SIGPIPE,
  # and `set -o pipefail` turns a successful match into rc=141.
  php_available() {
    local policy
    policy="$(apt-cache policy "php$1-fpm" 2>/dev/null || true)"
    case "$policy" in
      *"Candidate: "[0-9]*) return 0 ;;
      *)                    return 1 ;;
    esac
  }

  # A specific PHP version usually means an external repository — Ondřej Surý's
  # PPA on Ubuntu, packages.sury.org on Debian. That is a network dependency
  # some servers cannot reach, so it is only used when the distribution does
  # not already provide a version WHMCS supports.
  if ! php_available "$PHP"; then
    note "PHP ${PHP} is not in this system's own repositories"

    NATIVE=""
    for candidate in 8.3 8.2 8.1; do
      if php_available "$candidate"; then
        NATIVE="$candidate"
        break
      fi
    done
    [ -n "$NATIVE" ] && note "This system provides PHP ${NATIVE} directly"

    note "Adding the PHP repository"
    REPO_LOG="$(mktemp)"
    REPO_OK=0

    if [[ "$OS_ID" == "ubuntu" ]]; then
      LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php > "$REPO_LOG" 2>&1 && REPO_OK=1
    else
      if curl -fsSL --max-time 60 https://packages.sury.org/php/apt.gpg \
           -o /usr/share/keyrings/sury-php.gpg 2> "$REPO_LOG"; then
        echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
          > /etc/apt/sources.list.d/sury-php.list
        REPO_OK=1
      fi
    fi

    if [ "$REPO_OK" = "1" ]; then
      apt-get update -qq 2>> "$REPO_LOG" || REPO_OK=0
    fi

    if [ "$REPO_OK" = "1" ] && php_available "$PHP"; then
      note "Repository added; PHP ${PHP} is now available"
    else
      # Never swallow this. A silent failure here used to end the whole
      # installation with no explanation at all.
      warn "Could not get PHP ${PHP} from an external repository."
      if [ -s "$REPO_LOG" ]; then
        warn "The repository tool said:"
        tail -15 "$REPO_LOG" | sed 's/^/      /' >&2
      fi

      if [ -n "$NATIVE" ]; then
        warn "Continuing with PHP ${NATIVE}, which this system already provides."
        warn "WHMCS 8.13 supports 8.1, 8.2 and 8.3, so that is a supported choice."
        PHP="$NATIVE"
      else
        rm -f "$REPO_LOG"
        fail "No PHP version WHMCS supports (8.1, 8.2 or 8.3) is available.

  This server could not reach the external PHP repository, and its own
  repositories offer nothing WHMCS supports. Usual causes:
    · no outbound HTTPS to launchpad.net or packages.sury.org
    · a proxy or firewall intercepting package downloads
    · a distribution release too old for any supported PHP

  Check from the server with:
    curl -I https://launchpad.net
    apt-cache policy php8.3-fpm php8.2-fpm php8.1-fpm"
      fi
    fi
    rm -f "$REPO_LOG"
  fi

  PACKAGES=(nginx mariadb-server)
  for extension in "${PHP_EXTENSIONS[@]}"; do
    PACKAGES+=("php${PHP}-${extension}")
  done

  apt-get install "${APT_OPTS[@]}" "${PACKAGES[@]}" >/dev/null || fail "Package installation failed"

  for extension in "${PHP_EXTENSIONS_OPTIONAL[@]}"; do
    apt-get install "${APT_OPTS[@]}" "php${PHP}-${extension}" >/dev/null 2>&1 \
      || warn "Optional extension php${PHP}-${extension} is unavailable; continuing"
  done

  PHP_INI_DIR="/etc/php/${PHP}/fpm/conf.d"
  PHP_CLI_INI_DIR="/etc/php/${PHP}/cli/conf.d"
  PHP_FPM_SERVICE="php${PHP}-fpm"
  PHP_FPM_SOCKET="/run/php/php${PHP}-fpm.sock"
  # Asked directly rather than parsed out of `php -i`: piping that into an awk
  # with `exit` sends SIGPIPE to php, and pipefail turns it into a failed
  # assignment that ends the whole run silently.
  PHP_EXT_DIR="$("php${PHP}" -r 'echo ini_get("extension_dir");' 2>/dev/null || true)"
else
  dnf install -y -q epel-release >/dev/null 2>&1 || true
  dnf install -y -q \
    "https://rpms.remirepo.net/enterprise/remi-release-${OS_VERSION}.rpm" >/dev/null 2>&1 || true

  dnf module reset -y php >/dev/null 2>&1 || true
  dnf module enable -y "php:remi-${PHP}" >/dev/null 2>&1 || \
    warn "Could not enable the remi PHP ${PHP} module; using the distribution default"

  PACKAGES=(nginx mariadb-server unzip tar cronie rsync policycoreutils-python-utils php php-fpm)
  for extension in "${PHP_EXTENSIONS[@]}"; do
    case "$extension" in
      cli|fpm) continue ;;
      mysqlnd) PACKAGES+=("php-mysqlnd") ;;
      *) PACKAGES+=("php-${extension}") ;;
    esac
  done

  dnf install -y -q "${PACKAGES[@]}" >/dev/null || fail "Package installation failed"

  for extension in "${PHP_EXTENSIONS_OPTIONAL[@]}"; do
    dnf install -y -q "php-${extension}" >/dev/null 2>&1 \
      || warn "Optional extension php-${extension} is unavailable; continuing"
  done

  PHP_INI_DIR="/etc/php.d"
  PHP_CLI_INI_DIR="/etc/php.d"
  PHP_FPM_SERVICE="php-fpm"
  PHP_FPM_SOCKET="/run/php-fpm/www.sock"
  PHP_EXT_DIR="$(php -r 'echo ini_get("extension_dir");' 2>/dev/null || true)"
fi

note "PHP $(php${PHP} -r 'echo PHP_VERSION;' 2>/dev/null || php -r 'echo PHP_VERSION;')"

# ---------------------------------------------------------------------------
# ionCube Loader — WHMCS will not run without it
# ---------------------------------------------------------------------------

step "Installing the ionCube Loader"

php_has_ioncube() {
  local modules
  modules="$( { "php${PHP}" -m 2>/dev/null || php -m 2>/dev/null || true; } | tr 'A-Z' 'a-z')"
  case "$modules" in
    *ioncube*) return 0 ;;
    *)         return 1 ;;
  esac
}

if php_has_ioncube; then
  note "Already loaded"
else
  ARCH="$(uname -m)"
  case "$ARCH" in
    x86_64)  IONCUBE_ARCH="x86-64" ;;
    aarch64) IONCUBE_ARCH="aarch64" ;;
    *) fail "No ionCube Loader for ${ARCH}. WHMCS cannot run on this machine." ;;
  esac

  TMP="$(mktemp -d)"

  note "Downloading loaders for ${IONCUBE_ARCH}"
  curl -fsSL "https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_${IONCUBE_ARCH}.tar.gz" \
    -o "${TMP}/ioncube.tar.gz" || fail "Could not download the ionCube Loader"

  tar -xzf "${TMP}/ioncube.tar.gz" -C "$TMP"

  LOADER="${TMP}/ioncube/ioncube_loader_lin_${PHP}.so"
  [[ -f "$LOADER" ]] || fail "ionCube does not ship a loader for PHP ${PHP}"

  [[ -n "$PHP_EXT_DIR" && -d "$PHP_EXT_DIR" ]] || fail "Cannot determine the PHP extension directory"
  install -m 0644 "$LOADER" "${PHP_EXT_DIR}/ioncube_loader.so"

  # 00- prefix: ionCube must be the first Zend extension loaded.
  for dir in "$PHP_INI_DIR" "$PHP_CLI_INI_DIR"; do
    [[ -d "$dir" ]] || continue
    printf 'zend_extension=%s/ioncube_loader.so\n' "$PHP_EXT_DIR" > "${dir}/00-ioncube.ini"
  done

  note "Installed $(basename "$LOADER")"
  rm -rf "$TMP"
fi

# ---------------------------------------------------------------------------
# PHP configuration
# ---------------------------------------------------------------------------

step "Applying WHMCS-appropriate PHP settings"

for dir in "$PHP_INI_DIR" "$PHP_CLI_INI_DIR"; do
  [[ -d "$dir" ]] || continue
  backup "${dir}/99-whmcs.ini"
  cat > "${dir}/99-whmcs.ini" <<'INI'
; WHMCS + WordPress Theme Provisioner
; Written by setup-whmcs.sh. Edit freely; it is only replaced on a re-run.

memory_limit = 512M
max_execution_time = 300
max_input_time = 300
max_input_vars = 5000
post_max_size = 64M
upload_max_filesize = 64M

; Uploaded demo packages can be large; the addon caps them again in PHP.
default_socket_timeout = 120

; WHMCS reports these as required.
allow_url_fopen = On
allow_url_include = Off

; Errors go to the log, never to a customer's browser.
display_errors = Off
display_startup_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT

expose_php = Off
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.use_strict_mode = 1

date.timezone = UTC

opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 60
INI
done

systemctl enable --now "$PHP_FPM_SERVICE" >/dev/null 2>&1 || true
systemctl restart "$PHP_FPM_SERVICE"
note "php-fpm restarted"

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------

step "Configuring MariaDB"

systemctl enable --now mariadb >/dev/null 2>&1 || systemctl enable --now mysqld >/dev/null 2>&1 || true

backup /etc/mysql/mariadb.conf.d/99-whmcs.cnf
MARIADB_CONF_DIR="/etc/mysql/mariadb.conf.d"
[[ -d "$MARIADB_CONF_DIR" ]] || MARIADB_CONF_DIR="/etc/my.cnf.d"
if [[ -d "$MARIADB_CONF_DIR" ]]; then
  cat > "${MARIADB_CONF_DIR}/99-whmcs.cnf" <<'CNF'
[mysqld]
# WHMCS stores serialized data in TEXT columns and does its own escaping;
# STRICT_TRANS_TABLES rejects rows it expects to be truncated.
sql_mode = "NO_ENGINE_SUBSTITUTION"
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
max_allowed_packet = 64M
innodb_file_per_table = 1
CNF
  systemctl restart mariadb 2>/dev/null || systemctl restart mysqld 2>/dev/null || true
fi

# Local socket auth as root; no password ever reaches the process list.
mysql --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`${WHMCS_DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${WHMCS_DB_USER}'@'localhost'
  IDENTIFIED BY '${WHMCS_DB_PASSWORD}';
ALTER USER '${WHMCS_DB_USER}'@'localhost' IDENTIFIED BY '${WHMCS_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${WHMCS_DB_NAME}\`.* TO '${WHMCS_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

note "Database ${WHMCS_DB_NAME} and user ${WHMCS_DB_USER} ready"

# ---------------------------------------------------------------------------
# WHMCS files
# ---------------------------------------------------------------------------

step "Preparing ${WHMCS_WEBROOT}"

mkdir -p "$WHMCS_WEBROOT"

if [[ -n "${WHMCS_ARCHIVE_URL:-}" ]]; then
  note "Downloading WHMCS"
  curl -fsSL "$WHMCS_ARCHIVE_URL" -o /tmp/whmcs.zip || fail "Could not download the WHMCS archive"
  WHMCS_ARCHIVE_FILE=/tmp/whmcs.zip
fi

if [[ -n "${WHMCS_ARCHIVE_FILE:-}" && -f "$WHMCS_ARCHIVE_FILE" ]]; then
  if [[ -f "${WHMCS_WEBROOT}/init.php" ]]; then
    warn "WHMCS is already installed here; leaving the existing files alone"
  elif [[ -n "$(ls -A "$WHMCS_WEBROOT" 2>/dev/null)" && "${WHMCS_RESET_WEBROOT:-n}" == "y" ]]; then
    BACKUP_WEBROOT="/var/backups/whmcs-webroot-$(date -u +%Y%m%dT%H%M%SZ)"
    note "Moving the existing web root aside to ${BACKUP_WEBROOT}"
    mv "$WHMCS_WEBROOT" "$BACKUP_WEBROOT"
    mkdir -p "$WHMCS_WEBROOT"
    note "Extracting WHMCS"
    TMP_EXTRACT="$(mktemp -d)"
    unzip -q "$WHMCS_ARCHIVE_FILE" -d "$TMP_EXTRACT"
    SOURCE="$TMP_EXTRACT"
    [[ -d "${TMP_EXTRACT}/whmcs" ]] && SOURCE="${TMP_EXTRACT}/whmcs"
    cp -a "${SOURCE}/." "$WHMCS_WEBROOT/"
    rm -rf "$TMP_EXTRACT"
    [[ -f "${WHMCS_WEBROOT}/configuration.php" && ! -s "${WHMCS_WEBROOT}/configuration.php" ]] \
      && rm -f "${WHMCS_WEBROOT}/configuration.php"
    [[ -f "${WHMCS_WEBROOT}/configuration.php.new" && ! -f "${WHMCS_WEBROOT}/configuration.php" ]] && \
      mv "${WHMCS_WEBROOT}/configuration.php.new" "${WHMCS_WEBROOT}/configuration.php"
    note "WHMCS extracted"
  else
    note "Extracting WHMCS"
    TMP_EXTRACT="$(mktemp -d)"
    unzip -q "$WHMCS_ARCHIVE_FILE" -d "$TMP_EXTRACT"

    # WHMCS archives put everything under whmcs/; tolerate either shape.
    SOURCE="$TMP_EXTRACT"
    [[ -d "${TMP_EXTRACT}/whmcs" ]] && SOURCE="${TMP_EXTRACT}/whmcs"
    cp -a "${SOURCE}/." "$WHMCS_WEBROOT/"
    rm -rf "$TMP_EXTRACT"

    # WHMCS ships these with a .new suffix for exactly this step.
    [[ -f "${WHMCS_WEBROOT}/configuration.php.new" && ! -f "${WHMCS_WEBROOT}/configuration.php" ]] && \
      mv "${WHMCS_WEBROOT}/configuration.php.new" "${WHMCS_WEBROOT}/configuration.php"
    note "WHMCS extracted"
  fi
else
  warn "No WHMCS archive supplied — the web root is prepared but empty"
fi

# Ownership and modes. configuration.php is 0400 once WHMCS has written it;
# before installation it must stay writable, so 0640 is correct here.
chown -R "${WEB_USER}:${WEB_USER}" "$WHMCS_WEBROOT"
find "$WHMCS_WEBROOT" -type d -exec chmod 0755 {} \;
find "$WHMCS_WEBROOT" -type f -exec chmod 0644 {} \;
[[ -f "${WHMCS_WEBROOT}/configuration.php" ]] && chmod 0640 "${WHMCS_WEBROOT}/configuration.php"

for writable in attachments downloads templates_c crons; do
  [[ -d "${WHMCS_WEBROOT}/${writable}" ]] && chmod -R 0775 "${WHMCS_WEBROOT}/${writable}"
done

# ---------------------------------------------------------------------------
# Provisioner storage and secrets
# ---------------------------------------------------------------------------

step "Preparing the theme provisioner"

mkdir -p "${WTP_STORAGE_PATH}"/{agent,demos,themes,core}
chown -R "${WEB_USER}:${WEB_USER}" "$WTP_STORAGE_PATH"
chmod -R 0750 "$WTP_STORAGE_PATH"
note "Package storage at ${WTP_STORAGE_PATH}"

ENV_DIR="$(dirname "$WTP_ENV_PATH")"
mkdir -p "$ENV_DIR"
chmod 0750 "$ENV_DIR"
chown "root:${WEB_USER}" "$ENV_DIR"

if [[ -f "$WTP_ENV_PATH" ]] && grep -q '^WTP_MASTER_KEY=' "$WTP_ENV_PATH"; then
  warn "An encryption key already exists at ${WTP_ENV_PATH}; keeping it"
  warn "Replacing it would make every stored agent secret undecryptable."
else
  backup "$WTP_ENV_PATH"
  MASTER_KEY="$(head -c 32 /dev/urandom | base64 | tr '+/' '-_' | tr -d '=')"

  cat > "$WTP_ENV_PATH" <<ENV
# WHMCS WordPress Theme Provisioner — secrets and overrides.
#
# Read by the addon, the worker and the agent API. Never commit this file, and
# never move it inside the web root.
#
# Losing WTP_MASTER_KEY makes every stored agent secret and every encrypted
# job payload unreadable. Back it up wherever you keep your other keys.

WTP_MASTER_KEY=${MASTER_KEY}
WTP_STORAGE_PATH=${WTP_STORAGE_PATH}
WTP_API_BASE_URL=https://${WHMCS_DOMAIN}
WTP_ENFORCE_HTTPS=1
WTP_LOG_LEVEL=info
ENV

  chown "root:${WEB_USER}" "$WTP_ENV_PATH"
  chmod 0640 "$WTP_ENV_PATH"
  note "Generated a new encryption key in ${WTP_ENV_PATH}"
fi

# The web user needs to find it without an environment variable.
for dir in "$PHP_INI_DIR" "$PHP_CLI_INI_DIR"; do
  [[ -d "$dir" ]] || continue
  printf 'env[WTP_ENV_FILE] = %s\n' "$WTP_ENV_PATH" > "${dir}/99-wtp.ini" 2>/dev/null || true
done
# php-fpm pools read env[] from the pool file, not conf.d, so set it there too.
for pool in "/etc/php/${PHP}/fpm/pool.d/www.conf" /etc/php-fpm.d/www.conf; do
  [[ -f "$pool" ]] || continue
  backup "$pool"
  grep -q '^env\[WTP_ENV_FILE\]' "$pool" || printf '\nenv[WTP_ENV_FILE] = %s\n' "$WTP_ENV_PATH" >> "$pool"
done

# ---------------------------------------------------------------------------
# nginx
# ---------------------------------------------------------------------------

step "Configuring nginx"

# `http2 on;` arrived in nginx 1.25.1. Before that it belongs on the listen
# directive. Ubuntu 24.04 ships 1.24.0, so both forms are still needed.
NGINX_VERSION="$(nginx -v 2>&1 | sed -E 's#.*/([0-9.]+).*#\1#')"
nginx_at_least() {
    # sort into a variable rather than piping to head: head exits after one
    # line and SIGPIPEs sort, which pipefail then reports as a failure.
    local ordered
    ordered="$(printf '%s\n%s\n' "$1" "$NGINX_VERSION" | sort -V)"
    [ "${ordered%%$'\n'*}" = "$1" ]
}

if nginx_at_least 1.25.1; then
    NGINX_LISTEN_443="listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;"
else
    NGINX_LISTEN_443="listen 443 ssl http2;
    listen [::]:443 ssl http2;"
fi
note "nginx ${NGINX_VERSION}"

NGINX_SITE="/etc/nginx/sites-available/whmcs.conf"
NGINX_LINK="/etc/nginx/sites-enabled/whmcs.conf"
if [[ ! -d /etc/nginx/sites-available ]]; then
  NGINX_SITE="/etc/nginx/conf.d/whmcs.conf"
  NGINX_LINK=""
fi

backup "$NGINX_SITE"
cat > "$NGINX_SITE" <<NGINX
# WHMCS + WordPress Theme Provisioner
# Written by setup-whmcs.sh.

server {
    listen 80;
    listen [::]:80;
    server_name ${WHMCS_DOMAIN};

    # ACME needs plain HTTP; everything else is redirected.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/html;
        default_type "text/plain";
    }
    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    ${NGINX_LISTEN_443}
    server_name ${WHMCS_DOMAIN};

    root ${WHMCS_WEBROOT};
    index index.php index.html;

    ssl_certificate     /etc/ssl/wtp/${WHMCS_DOMAIN}.crt;
    ssl_certificate_key /etc/ssl/wtp/${WHMCS_DOMAIN}.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    client_max_body_size 64M;

    # The provisioning API: one entry point, everything below it rewritten.
    location /provisioning/v1 {
        try_files \$uri /provisioning/v1/index.php\$is_args\$args;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    # WHMCS's own recommended denials, plus this module's storage.
    location ~ ^/(vendor|tests|modules/servers/[^/]+/lib)/ { deny all; }
    location ~ ^/(configuration\.php|attachments|downloads|templates_c|crons) { deny all; }
    location ~ /\.(?!well-known) { deny all; }
    location ~* \.(sql|log|env|ini|bak|old)\$ { deny all; }

    access_log /var/log/nginx/whmcs.access.log;
    error_log  /var/log/nginx/whmcs.error.log;
}
NGINX

[[ -n "$NGINX_LINK" ]] && ln -sf "$NGINX_SITE" "$NGINX_LINK"
[[ -f /etc/nginx/sites-enabled/default ]] && rm -f /etc/nginx/sites-enabled/default

# A self-signed placeholder so nginx starts before certbot has run.
mkdir -p /etc/ssl/wtp
if [[ ! -f "/etc/ssl/wtp/${WHMCS_DOMAIN}.crt" ]]; then
  if ! openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
       -keyout "/etc/ssl/wtp/${WHMCS_DOMAIN}.key" \
       -out "/etc/ssl/wtp/${WHMCS_DOMAIN}.crt" \
       -subj "/CN=${WHMCS_DOMAIN}" > /tmp/wtp-openssl.log 2>&1; then
    warn "openssl could not create the placeholder certificate:"
    sed 's/^/      /' /tmp/wtp-openssl.log >&2
    rm -f /tmp/wtp-openssl.log
    fail "Cannot create a TLS certificate; nginx has nothing to serve HTTPS with."
  fi
  rm -f /tmp/wtp-openssl.log
  chmod 0600 "/etc/ssl/wtp/${WHMCS_DOMAIN}.key"
  note "Created a temporary self-signed certificate"
fi

if ! nginx -t > /tmp/wtp-nginx-test.log 2>&1; then
    warn "nginx rejected the generated configuration:"
    sed 's/^/      /' /tmp/wtp-nginx-test.log >&2

    # Leave the server as it was rather than with a vhost that will not load.
    [ -n "$NGINX_LINK" ] && rm -f "$NGINX_LINK"
    rm -f /tmp/wtp-nginx-test.log
    fail "The generated nginx configuration is invalid (the reason is above).
  The offending file was left at ${NGINX_SITE} and unlinked from sites-enabled,
  so nginx keeps running with its previous configuration."
fi
rm -f /tmp/wtp-nginx-test.log
systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl reload nginx
note "nginx serving ${WHMCS_DOMAIN}"

if [[ "$FAMILY" == "rhel" ]] && command -v setsebool >/dev/null 2>&1; then
  setsebool -P httpd_can_network_connect 1 2>/dev/null || true
  setsebool -P httpd_can_network_connect_db 1 2>/dev/null || true
  command -v restorecon >/dev/null 2>&1 && restorecon -R "$WHMCS_WEBROOT" 2>/dev/null || true
  note "SELinux booleans set for outbound connections"
fi

# ---------------------------------------------------------------------------
# TLS
# ---------------------------------------------------------------------------

if [[ "$WHMCS_ISSUE_TLS" == "y" ]]; then
  step "Requesting a Let's Encrypt certificate"

  if [[ "$FAMILY" == "debian" ]]; then
    apt-get install "${APT_OPTS[@]}" certbot python3-certbot-nginx >/dev/null
  else
    dnf install -y -q certbot python3-certbot-nginx >/dev/null
  fi

  mkdir -p /var/www/html
  if certbot certonly --webroot -w /var/www/html \
       -d "$WHMCS_DOMAIN" --email "$WHMCS_ADMIN_EMAIL" \
       --agree-tos --non-interactive --keep-until-expiring >/dev/null 2>&1; then

    LIVE="/etc/letsencrypt/live/${WHMCS_DOMAIN}"
    ln -sf "${LIVE}/fullchain.pem" "/etc/ssl/wtp/${WHMCS_DOMAIN}.crt"
    ln -sf "${LIVE}/privkey.pem"   "/etc/ssl/wtp/${WHMCS_DOMAIN}.key"
    systemctl reload nginx
    note "Certificate issued and installed"

    # Renewal must reload nginx, or the new certificate is never served.
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
  else
    warn "Certificate request failed — the self-signed placeholder is still in place."
    warn "Point ${WHMCS_DOMAIN} at this server, then run:"
    warn "  certbot certonly --webroot -w /var/www/html -d ${WHMCS_DOMAIN}"
  fi
fi

# ---------------------------------------------------------------------------
# Firewall
# ---------------------------------------------------------------------------

if [[ "$WHMCS_CONFIGURE_FIREWALL" == "y" ]]; then
  step "Configuring the firewall"

  if command -v ufw >/dev/null 2>&1; then
    ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp >/dev/null 2>&1
    ufw allow 80/tcp >/dev/null 2>&1
    ufw allow 443/tcp >/dev/null 2>&1
    yes | ufw enable >/dev/null 2>&1 || true
    note "ufw: 22, 80 and 443 open"
  elif command -v firewall-cmd >/dev/null 2>&1; then
    systemctl enable --now firewalld >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-service=ssh   >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-service=http  >/dev/null 2>&1 || true
    firewall-cmd --permanent --add-service=https >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
    note "firewalld: ssh, http and https allowed"
  else
    warn "No ufw or firewalld found; configure your firewall manually"
  fi
fi

# ---------------------------------------------------------------------------
# Cron
# ---------------------------------------------------------------------------

step "Installing cron jobs"

systemctl enable --now cron >/dev/null 2>&1 || systemctl enable --now crond >/dev/null 2>&1 || true

PHP_BIN="$(command -v "php${PHP}" || command -v php)"
CRON_FILE="/etc/cron.d/whmcs-wtp"
backup "$CRON_FILE"

# WHMCS 8 moved its cron out of the web root; support both layouts.
WHMCS_CRON_PATH="${WHMCS_WEBROOT}/crons/cron.php"

cat > "$CRON_FILE" <<CRON
# WHMCS and WordPress Theme Provisioner
# Written by setup-whmcs.sh.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
WTP_ENV_FILE=${WTP_ENV_PATH}

# WHMCS's own scheduled tasks.
*/5 * * * * ${WEB_USER} ${PHP_BIN} -q ${WHMCS_CRON_PATH} >/dev/null 2>&1

# The provisioning queue: one bounded tick a minute.
* * * * * ${WEB_USER} ${PHP_BIN} -q ${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php >/dev/null 2>&1
CRON

chmod 0644 "$CRON_FILE"
note "Cron installed at ${CRON_FILE}"

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

step "Server preparation complete"

note "PHP            $("$PHP_BIN" -r 'echo PHP_VERSION;')"
IONCUBE_STATE="$( { "$PHP_BIN" -m 2>/dev/null || true; } | tr 'A-Z' 'a-z')"
case "$IONCUBE_STATE" in
  *ioncube*) note "ionCube        loaded" ;;
  *)         warn "ionCube        NOT LOADED — WHMCS will not start" ;;
esac
note "MariaDB        $(mysql --version | awk '{print $5}' | tr -d ,)"
note "nginx          $(nginx -v 2>&1 | awk -F/ '{print $2}')"
note "Web root       ${WHMCS_WEBROOT}"
note "Storage        ${WTP_STORAGE_PATH}"
note "Secrets        ${WTP_ENV_PATH}"
note "Backups        ${BACKUP_DIR}"

echo
