#!/usr/bin/env bash
#
#  deploy-all.sh — one command: bare server ➜ WHMCS + WordPress Theme Provisioner
#
#  Run it from your own machine, inside a checkout of this repository:
#
#      ./infrastructure/deploy-all.sh
#
#  It asks for the server address and password, then does everything:
#  installs the web stack, PHP with ionCube, MariaDB, TLS, the firewall and
#  cron; extracts WHMCS; builds and deploys this module; generates the
#  encryption key; and verifies the result.
#
#  The one thing it cannot do is run the WHMCS web installer — that needs your
#  licence key and sets the admin password. It finishes by printing the URL and
#  the database credentials for that step.
#
#  Options
#      --config FILE     load the answers from a file instead of asking
#      --yes             accept every default, never prompt
#      --skip-server     the server is already prepared; deploy the module only
#      --skip-deploy     prepare the server only
#      --skip-tests      do not run the test suite before deploying
#      --dry-run         show what would happen, change nothing
#
#  Safe to re-run. Everything it replaces on the server is backed up to
#  /var/backups/wtp-setup-<timestamp>/ first.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"
# shellcheck source=lib/ssh-auth.sh
source "${SCRIPT_DIR}/lib/ssh-auth.sh"

SKIP_SERVER=0
SKIP_DEPLOY=0
SKIP_TESTS=0
DRY_RUN=0
CONFIG_FILE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config)      CONFIG_FILE="${2:?}"; shift 2 ;;
    --config=*)    CONFIG_FILE="${1#*=}"; shift ;;
    --yes|-y)      WTP_NONINTERACTIVE=1; shift ;;
    --skip-server) SKIP_SERVER=1; shift ;;
    --skip-deploy) SKIP_DEPLOY=1; shift ;;
    --skip-tests)  SKIP_TESTS=1; shift ;;
    --dry-run)     DRY_RUN=1; shift ;;
    --help|-h)     awk 'NR>1 && /^#/ { sub(/^#[ ]?/, ""); print; next } NR>1 { exit }' "${BASH_SOURCE[0]}"; exit 0 ;;
    *)             die "Unknown option: $1 (try --help)" ;;
  esac
done

if [[ -n "$CONFIG_FILE" ]]; then
  [[ -f "$CONFIG_FILE" ]] || die "No such config file: $CONFIG_FILE"
  # shellcheck disable=SC1090
  source "$CONFIG_FILE"
fi

trap wtp_ssh_cleanup EXIT

cat <<'BANNER'

  WHMCS WordPress Theme Provisioner
  Full deployment

BANNER

# ═══════════════════════════════════════════════════════════════════════════
# 0. Local prerequisites
# ═══════════════════════════════════════════════════════════════════════════

heading "Checking your machine"

require_command ssh   "Install OpenSSH."
require_command scp   "Install OpenSSH."
require_command tar   "Install tar."
require_command php   "Install PHP 7.4 or newer to build the distribution."

PHP_LOCAL_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
success "php ${PHP_LOCAL_VERSION}, ssh, scp, tar"

# ═══════════════════════════════════════════════════════════════════════════
# 1. Answers
# ═══════════════════════════════════════════════════════════════════════════

heading "Server"

ask VPS_HOST     "Server IP address or hostname"
ask VPS_SSH_PORT "SSH port" "22"
ask VPS_SSH_USER "SSH username" "root"

if [[ -z "${VPS_AUTH_METHOD:-}" ]]; then
  echo
  echo "    1) Password  — you type the server's root password"
  echo "    2) SSH key   — you already have a key on the server"
  echo
  ask VPS_AUTH_CHOICE "How should I sign in?" "1"
  case "$VPS_AUTH_CHOICE" in
    2) VPS_AUTH_METHOD="key" ;;
    *) VPS_AUTH_METHOD="password" ;;
  esac
fi

if [[ "$VPS_AUTH_METHOD" == "key" ]]; then
  ask VPS_SSH_KEY "Path to your private key" "${HOME}/.ssh/id_ed25519"
  VPS_SSH_KEY="${VPS_SSH_KEY/#\~/$HOME}"
  [[ -f "$VPS_SSH_KEY" ]] || die "No key at ${VPS_SSH_KEY}"
else
  ask_secret VPS_SSH_PASSWORD "Server password for ${VPS_SSH_USER}@${VPS_HOST}"
  [[ -n "$VPS_SSH_PASSWORD" ]] || die "The password cannot be empty."
fi

wtp_ssh_init

SSH_OPTS=(-p "${VPS_SSH_PORT}" -o StrictHostKeyChecking=accept-new
          -o UserKnownHostsFile="${HOME}/.ssh/known_hosts" -o ConnectTimeout=20)
SCP_OPTS=(-P "${VPS_SSH_PORT}" -o StrictHostKeyChecking=accept-new
          -o UserKnownHostsFile="${HOME}/.ssh/known_hosts" -o ConnectTimeout=20)

if [[ "$VPS_AUTH_METHOD" == "key" ]]; then
  SSH_OPTS+=(-i "$VPS_SSH_KEY" -o IdentitiesOnly=yes)
  SCP_OPTS+=(-i "$VPS_SSH_KEY" -o IdentitiesOnly=yes)
else
  # Without this, ssh tries every key in the agent first and can exhaust the
  # server's auth attempts before it ever offers the password.
  SSH_OPTS+=(-o PreferredAuthentications=password -o PubkeyAuthentication=no)
  SCP_OPTS+=(-o PreferredAuthentications=password -o PubkeyAuthentication=no)
fi

TARGET="${VPS_SSH_USER}@${VPS_HOST}"

remote()       { wtp_ssh "${SSH_OPTS[@]}" "$TARGET" "$@"; }
remote_out()   { wtp_ssh_capture "${SSH_OPTS[@]}" "$TARGET" "$@"; }
remote_value() { wtp_ssh_value "${SSH_OPTS[@]}" "$TARGET" "$@"; }
remote_tty()   { wtp_ssh -t "${SSH_OPTS[@]}" "$TARGET" "$@"; }
push()         { wtp_scp "${SCP_OPTS[@]}" "$1" "${TARGET}:$2" >/dev/null; }

info "Connecting…"
if ! remote_out 'echo wtp-connection-ok' | grep -q 'wtp-connection-ok'; then
  die "Could not sign in to ${TARGET}:${VPS_SSH_PORT}.
  Check the address, the port, the username and the password.
  If the server is brand new, make sure PasswordAuthentication is enabled in
  /etc/ssh/sshd_config, or use an SSH key instead."
fi
success "Signed in to ${VPS_HOST}"

REMOTE_OS="$(remote_value 'source /etc/os-release && echo "${PRETTY_NAME}"')"
REMOTE_IP="$(remote_value "hostname -I 2>/dev/null | awk '{print \$1}'")"
info "${REMOTE_OS}"

# Privilege escalation, decided once.
if [[ "$VPS_SSH_USER" == "root" ]]; then
  SUDO=""
elif remote_out 'sudo -n true 2>/dev/null && echo wtp-sudo-ok' | grep -q 'wtp-sudo-ok'; then
  SUDO="sudo -n"
else
  die "The '${VPS_SSH_USER}' account cannot become root without a prompt.
  Either run this as root, or give the account passwordless sudo."
fi

# ---------------------------------------------------------------------------

heading "WHMCS"

ask WHMCS_DOMAIN "Domain WHMCS will run on (e.g. billing.example.com)"
is_hostname "$WHMCS_DOMAIN" || die "'${WHMCS_DOMAIN}' does not look like a hostname."

ask WHMCS_ADMIN_EMAIL "Your email (for TLS certificates and alerts)"
[[ "$WHMCS_ADMIN_EMAIL" == *@*.* ]] || die "'${WHMCS_ADMIN_EMAIL}' does not look like an email address."

ask WHMCS_WEBROOT "Where to install WHMCS" "/var/www/whmcs"
ask WHMCS_PHP     "PHP version (8.1, 8.2 or 8.3)" "8.2"
case "$WHMCS_PHP" in
  8.1|8.2|8.3) : ;;
  *) die "WHMCS 8.13 supports 8.1, 8.2 and 8.3 only." ;;
esac

ask WHMCS_DB_NAME "Database name" "whmcs"
ask WHMCS_DB_USER "Database username" "whmcs"
if [[ -z "${WHMCS_DB_PASSWORD:-}" ]]; then
  WHMCS_DB_PASSWORD="$(random_token 28)"
  log "Generated a database password — shown at the end."
fi

# DNS is worth checking before certbot spends a rate-limit attempt on it.
WHMCS_ISSUE_TLS_DEFAULT="y"
if command -v dig >/dev/null 2>&1 || command -v host >/dev/null 2>&1; then
  if command -v dig >/dev/null 2>&1; then
    RESOLVED="$(dig +short "$WHMCS_DOMAIN" A | tail -1)"
  else
    RESOLVED="$(host -t A "$WHMCS_DOMAIN" 2>/dev/null | awk '/has address/ {print $NF; exit}')"
  fi

  if [[ -z "$RESOLVED" ]]; then
    warn "${WHMCS_DOMAIN} does not resolve yet."
    WHMCS_ISSUE_TLS_DEFAULT="n"
  elif [[ "$RESOLVED" != "$VPS_HOST" && "$RESOLVED" != "$REMOTE_IP" ]]; then
    warn "${WHMCS_DOMAIN} resolves to ${RESOLVED}, not this server (${REMOTE_IP:-$VPS_HOST})."
    WHMCS_ISSUE_TLS_DEFAULT="n"
  else
    success "${WHMCS_DOMAIN} → ${RESOLVED}"
  fi
fi

if [[ "$WHMCS_ISSUE_TLS_DEFAULT" == "n" ]]; then
  warn "A Let's Encrypt certificate cannot be issued until DNS points here."
  warn "The script will install a self-signed placeholder and tell you how to"
  warn "obtain the real one once DNS has propagated."
fi

ask_yes_no WHMCS_ISSUE_TLS "Request a Let's Encrypt certificate now?" "$WHMCS_ISSUE_TLS_DEFAULT"
ask_yes_no WHMCS_CONFIGURE_FIREWALL "Configure the firewall (allow 22, 80, 443)?" "y"

echo
echo "  WHMCS is licensed software, so its archive cannot be downloaded"
echo "  automatically. Download whmcs.zip from your WHMCS members area first."
echo
ask WHMCS_ARCHIVE "Path to whmcs.zip on this machine (or a URL, or 'skip')" "skip"

if [[ "$WHMCS_ARCHIVE" != "skip" && "$WHMCS_ARCHIVE" != http* ]]; then
  WHMCS_ARCHIVE="${WHMCS_ARCHIVE/#\~/$HOME}"
  [[ -f "$WHMCS_ARCHIVE" ]] || die "No archive at ${WHMCS_ARCHIVE}"
fi

heading "Theme provisioner"

ask WTP_STORAGE_PATH "Where to keep theme and demo packages" "/var/lib/whmcs-wtp/packages"
ask WTP_ENV_PATH     "Where to keep the encryption key"      "/etc/whmcs-wtp/wtp.env"

# ═══════════════════════════════════════════════════════════════════════════
# 2. Confirm
# ═══════════════════════════════════════════════════════════════════════════

heading "Plan"

TLS_LABEL="self-signed placeholder"
[[ "$WHMCS_ISSUE_TLS" == "y" ]] && TLS_LABEL="Let's Encrypt"

ARCHIVE_LABEL="$WHMCS_ARCHIVE"
[[ "$WHMCS_ARCHIVE" == "skip" ]] && ARCHIVE_LABEL="not supplied — web root prepared but empty"

cat <<PLAN
  Server         ${TARGET}:${VPS_SSH_PORT}
                 ${REMOTE_OS}
  Sign-in        ${VPS_AUTH_METHOD}$([[ "$WTP_SSH_STRATEGY" == "expect" ]] && echo " (via expect)" || true)

  Domain         ${WHMCS_DOMAIN}
  Web root       ${WHMCS_WEBROOT}
  PHP            ${WHMCS_PHP} + ionCube Loader
  Database       ${WHMCS_DB_NAME} / ${WHMCS_DB_USER}
  TLS            ${TLS_LABEL}
  Firewall       $([[ "$WHMCS_CONFIGURE_FIREWALL" == "y" ]] && echo "22, 80, 443" || echo "left alone")
  WHMCS archive  ${ARCHIVE_LABEL}

  Packages       ${WTP_STORAGE_PATH}
  Encryption key ${WTP_ENV_PATH}

  Steps          $([[ "$SKIP_SERVER" == "1" ]] && echo "skip server prep · " || echo "prepare server · ")\
$([[ "$SKIP_DEPLOY" == "1" ]] && echo "skip deploy" || echo "deploy provisioner · verify")
PLAN

if [[ "$DRY_RUN" == "1" ]]; then
  echo
  warn "Dry run — stopping here. Nothing was changed."
  exit 0
fi

if [[ "${WTP_NONINTERACTIVE:-0}" != "1" ]]; then
  echo
  read -r -p "  Start? [y/N]: " CONFIRM
  [[ "${CONFIRM,,}" == "y" || "${CONFIRM,,}" == "yes" ]] || die "Cancelled."
fi

STARTED_AT="$(date +%s)"
REMOTE_WORKDIR="/tmp/wtp-deploy-$(random_token 10)"
remote "mkdir -p ${REMOTE_WORKDIR} && chmod 700 ${REMOTE_WORKDIR}" >/dev/null

cleanup_all() {
  remote "rm -rf ${REMOTE_WORKDIR}" >/dev/null 2>&1 || true
  wtp_ssh_cleanup
}
trap cleanup_all EXIT

# ═══════════════════════════════════════════════════════════════════════════
# 3. Prepare the server
# ═══════════════════════════════════════════════════════════════════════════

if [[ "$SKIP_SERVER" == "0" ]]; then
  heading "1/4  Preparing the server"

  info "Uploading the provisioning script…"
  push "${SCRIPT_DIR}/lib/remote-provision.sh" "${REMOTE_WORKDIR}/remote-provision.sh"

  # Answers travel in a 0600 file, never on a command line where they would
  # show up in the server's process list.
  ANSWERS_TMP="$(mktemp)"
  chmod 600 "$ANSWERS_TMP"
  cat > "$ANSWERS_TMP" <<ANSWERS
WHMCS_DOMAIN='${WHMCS_DOMAIN}'
WHMCS_ADMIN_EMAIL='${WHMCS_ADMIN_EMAIL}'
WHMCS_WEBROOT='${WHMCS_WEBROOT}'
WHMCS_PHP='${WHMCS_PHP}'
WHMCS_DB_NAME='${WHMCS_DB_NAME}'
WHMCS_DB_USER='${WHMCS_DB_USER}'
WHMCS_DB_PASSWORD='${WHMCS_DB_PASSWORD}'
WHMCS_ISSUE_TLS='${WHMCS_ISSUE_TLS}'
WHMCS_CONFIGURE_FIREWALL='${WHMCS_CONFIGURE_FIREWALL}'
WTP_STORAGE_PATH='${WTP_STORAGE_PATH}'
WTP_ENV_PATH='${WTP_ENV_PATH}'
ANSWERS

  push "$ANSWERS_TMP" "${REMOTE_WORKDIR}/answers.env"
  shred -u "$ANSWERS_TMP" 2>/dev/null || rm -f "$ANSWERS_TMP"

  if [[ "$WHMCS_ARCHIVE" != "skip" ]]; then
    if [[ "$WHMCS_ARCHIVE" == http* ]]; then
      remote "printf \"WHMCS_ARCHIVE_URL='%s'\n\" '${WHMCS_ARCHIVE}' >> ${REMOTE_WORKDIR}/answers.env" >/dev/null
      info "The server will download WHMCS."
    else
      info "Uploading WHMCS ($(du -h "$WHMCS_ARCHIVE" | cut -f1))…"
      push "$WHMCS_ARCHIVE" "${REMOTE_WORKDIR}/whmcs.zip"
      remote "printf \"WHMCS_ARCHIVE_FILE='%s'\n\" '${REMOTE_WORKDIR}/whmcs.zip' >> ${REMOTE_WORKDIR}/answers.env" >/dev/null
    fi
  fi

  echo
  info "Installing packages and configuring services."
  info "This takes 3–10 minutes depending on the server."
  echo

  remote_tty "chmod +x ${REMOTE_WORKDIR}/remote-provision.sh && \
              ${SUDO} ${REMOTE_WORKDIR}/remote-provision.sh ${REMOTE_WORKDIR}/answers.env" \
    || die "Server preparation failed. The output above says where.
  Nothing was left half-configured: re-run this script once the cause is fixed."

  success "Server prepared"
else
  heading "1/4  Preparing the server — skipped"
fi

# ═══════════════════════════════════════════════════════════════════════════
# 4. Build
# ═══════════════════════════════════════════════════════════════════════════

if [[ "$SKIP_DEPLOY" == "0" ]]; then
  heading "2/4  Building the provisioner"

  if [[ "$SKIP_TESTS" == "0" ]]; then
    info "Running the test suite…"
    if (cd "$REPO_ROOT" && php bin/run-tests.php >/dev/null 2>&1); then
      success "Tests pass"
    else
      error "The test suite is failing — refusing to deploy."
      (cd "$REPO_ROOT" && php bin/run-tests.php 2>&1 | tail -20)
      die "Fix the failures, or re-run with --skip-tests if you know why they fail."
    fi
  fi

  info "Assembling dist/…"
  (cd "$REPO_ROOT" && php bin/build.php >/dev/null) || die "Build failed."

  AGENT_SHA="$(php -r '
    $file = "'"${REPO_ROOT}"'/dist/checksums.json";
    $data = json_decode((string) file_get_contents($file), true);
    echo $data["agent_package"]["sha256"] ?? "";
  ')"
  success "Built — agent sha256 ${AGENT_SHA:0:16}…"

  # ═════════════════════════════════════════════════════════════════════════
  # 5. Deploy
  # ═════════════════════════════════════════════════════════════════════════

  heading "3/4  Deploying"

  info "Packing…"
  BUNDLE="$(mktemp -d)"
  tar -czf "${BUNDLE}/wtp-whmcs.tar.gz" -C "${REPO_ROOT}/dist/whmcs" .

  info "Uploading the module ($(du -h "${BUNDLE}/wtp-whmcs.tar.gz" | cut -f1))…"
  push "${BUNDLE}/wtp-whmcs.tar.gz" "${REMOTE_WORKDIR}/wtp-whmcs.tar.gz"

  info "Uploading the agent package…"
  push "${REPO_ROOT}/dist/agent/whmcs-theme-provisioner.zip" "${REMOTE_WORKDIR}/agent.zip"
  rm -rf "$BUNDLE"

  info "Installing…"
  remote "${SUDO} bash -s" <<REMOTE_DEPLOY || die "Deployment failed."
set -euo pipefail

WEBROOT='${WHMCS_WEBROOT}'
STORAGE='${WTP_STORAGE_PATH}'

[ -d "\$WEBROOT" ] || { echo "No web root at \$WEBROOT" >&2; exit 1; }

# The web user owns WHMCS; match it rather than guessing.
if [ -f "\$WEBROOT/init.php" ]; then
  WEB_USER=\$(stat -c '%U' "\$WEBROOT/init.php")
else
  WEB_USER=\$(stat -c '%U' "\$WEBROOT")
fi
[ -n "\$WEB_USER" ] && [ "\$WEB_USER" != "root" ] || WEB_USER=\$(id -u www-data >/dev/null 2>&1 && echo www-data || echo nginx)

tar -xzf '${REMOTE_WORKDIR}/wtp-whmcs.tar.gz' -C "\$WEBROOT"

install -D -m 0640 -o "\$WEB_USER" -g "\$WEB_USER" \
  '${REMOTE_WORKDIR}/agent.zip' "\$STORAGE/agent/whmcs-theme-provisioner.zip"

for path in \
  "\$WEBROOT/modules/addons/wpthemeprovisioner" \
  "\$WEBROOT/modules/servers/wpthemehost" \
  "\$WEBROOT/provisioning"
do
  [ -d "\$path" ] || continue
  chown -R "\$WEB_USER:\$WEB_USER" "\$path"
  find "\$path" -type d -exec chmod 0755 {} +
  find "\$path" -type f -exec chmod 0644 {} +
done

echo "installed as \$WEB_USER"
REMOTE_DEPLOY

  success "Deployed"
else
  heading "2/4  Building — skipped"
  heading "3/4  Deploying — skipped"
fi

# ═══════════════════════════════════════════════════════════════════════════
# 6. Verify
# ═══════════════════════════════════════════════════════════════════════════

heading "4/4  Verifying"

WEB_USER="$(remote_value "stat -c '%U' '${WHMCS_WEBROOT}' 2>/dev/null || echo www-data")"
SELFTEST_OUTPUT="$(remote "${SUDO} -u '${WEB_USER}' env WTP_ENV_FILE='${WTP_ENV_PATH}' \
  php '${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php' --selftest" 2>&1 | tr -d '\r' || true)"

echo "$SELFTEST_OUTPUT" | sed 's/^/  /'

SELFTEST_OK=0
echo "$SELFTEST_OUTPUT" | grep -q "All checks passed" && SELFTEST_OK=1

if [[ "$SELFTEST_OK" == "1" ]]; then
  success "The provisioner reports itself healthy."
else
  warn "Some checks did not pass."
  warn "Two of them are expected before you finish the WHMCS installer:"
  warn "  · 'database tables' — created when you activate the addon"
  warn "  · 'api base url'    — needs WHMCS's System URL to be set"
fi

# HTTP reachability, from here rather than from the server.
if command -v curl >/dev/null 2>&1; then
  HTTP_CODE="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "https://${WHMCS_DOMAIN}/" 2>/dev/null || echo 000)"
  case "$HTTP_CODE" in
    000) warn "https://${WHMCS_DOMAIN}/ is not reachable from here yet (DNS or firewall)." ;;
    200|301|302|403) success "https://${WHMCS_DOMAIN}/ answers (HTTP ${HTTP_CODE})" ;;
    *)   warn "https://${WHMCS_DOMAIN}/ answered HTTP ${HTTP_CODE}" ;;
  esac
fi

# ═══════════════════════════════════════════════════════════════════════════
# 7. Hand over
# ═══════════════════════════════════════════════════════════════════════════

ELAPSED=$(( $(date +%s) - STARTED_AT ))
CREDENTIALS_FILE="${REPO_ROOT}/infrastructure/credentials-${WHMCS_DOMAIN}.txt"

umask 077
cat > "$CREDENTIALS_FILE" <<CREDS
WHMCS deployment — ${WHMCS_DOMAIN}
Generated $(date -u '+%Y-%m-%d %H:%M:%S UTC') by deploy-all.sh

  Server              ${TARGET}:${VPS_SSH_PORT}
  Web root            ${WHMCS_WEBROOT}
  Installer URL       https://${WHMCS_DOMAIN}/install/install.php

  Database host       localhost
  Database name       ${WHMCS_DB_NAME}
  Database username   ${WHMCS_DB_USER}
  Database password   ${WHMCS_DB_PASSWORD}

  Encryption key      ${WTP_ENV_PATH} on the server (mode 0640)
                      Back this file up. Losing it makes every stored
                      credential unreadable and cannot be undone.

  Package storage     ${WTP_STORAGE_PATH}

This file contains secrets. It is gitignored. Move it somewhere safe and
delete it from here.
CREDS
chmod 600 "$CREDENTIALS_FILE"

heading "Done in $((ELAPSED / 60))m $((ELAPSED % 60))s"

cat <<NEXT
Four steps left. They need a human, so the script stops here.

  ${C_BOLD}1  Run the WHMCS installer${C_RESET}

     ${C_BLUE}https://${WHMCS_DOMAIN}/install/install.php${C_RESET}

       Database host      localhost
       Database name      ${WHMCS_DB_NAME}
       Database user      ${WHMCS_DB_USER}
       Database password  ${C_BOLD}${WHMCS_DB_PASSWORD}${C_RESET}

     It asks for your licence key and creates the first admin account.

  ${C_BOLD}2  Lock it down${C_RESET}

     ssh ${TARGET} '${SUDO} rm -rf ${WHMCS_WEBROOT}/install'
     ssh ${TARGET} '${SUDO} chmod 0400 ${WHMCS_WEBROOT}/configuration.php'

     Then rename ${WHMCS_WEBROOT}/admin to something private and set
     \$customadminpath in configuration.php to match.

  ${C_BOLD}3  Activate the provisioner${C_RESET}

     WHMCS admin → System Settings → Addon Modules
       → "WordPress Theme Provisioner" → Activate → set access control

     This creates its database tables.

  ${C_BOLD}4  Check it${C_RESET}

     ssh ${TARGET} \\
       "${SUDO} -u ${WEB_USER} php ${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php --selftest"

     Every line should read OK.

${C_BOLD}Then${C_RESET}
  · Add a cPanel server:  System Settings → Servers  (use a WHM API token)
  · Add a theme and a demo:  docs/theme-demo.md
  · Point a hosting product at "WordPress Hosting (Theme Provisioner)"
  · Enable selection for it in the addon's Products tab

${C_YELLOW}Credentials written to${C_RESET}
  ${CREDENTIALS_FILE}
  Mode 0600, gitignored. Move it somewhere safe and delete it from the repo.

NEXT
