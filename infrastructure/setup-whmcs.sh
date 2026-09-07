#!/usr/bin/env bash
#
# setup-whmcs.sh — prepare a VPS for WHMCS and the WordPress Theme Provisioner.
#
# Runs on YOUR machine. It collects the connection details, copies a
# provisioning script to the server, and executes it there with sudo.
#
#   ./infrastructure/setup-whmcs.sh
#   ./infrastructure/setup-whmcs.sh --config infrastructure/answers.env
#   ./infrastructure/setup-whmcs.sh --check          # connectivity only
#   ./infrastructure/setup-whmcs.sh --deploy-only    # skip server prep
#
# What it does on the server:
#   nginx · PHP-FPM with every extension WHMCS needs · ionCube Loader ·
#   MariaDB · certbot · firewall · WHMCS-tuned php.ini · cron ·
#   the provisioner's storage, environment file and worker
#
# What it deliberately does NOT do:
#   run the WHMCS web installer. That step needs your licence key and sets the
#   admin password, and neither belongs in a shell script or in this
#   repository. The script finishes by printing the URL to open.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

MODE="full"
CONFIG_FILE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config)      CONFIG_FILE="${2:-}"; shift 2 ;;
    --config=*)    CONFIG_FILE="${1#*=}"; shift ;;
    --check)       MODE="check"; shift ;;
    --deploy-only) MODE="deploy"; shift ;;
    --yes|-y)      WTP_NONINTERACTIVE=1; shift ;;
    --help|-h)
      awk 'NR>1 && /^#/ { sub(/^#[ ]?/, ""); print; next } NR>1 { exit }' "${BASH_SOURCE[0]}"
      exit 0
      ;;
    *) die "Unknown option: $1 (try --help)" ;;
  esac
done

if [[ -n "$CONFIG_FILE" ]]; then
  [[ -f "$CONFIG_FILE" ]] || die "No such config file: $CONFIG_FILE"
  # shellcheck disable=SC1090
  source "$CONFIG_FILE"
  info "Loaded answers from ${CONFIG_FILE}"
fi

require_command ssh "Install OpenSSH."
require_command scp "Install OpenSSH."

# ---------------------------------------------------------------------------
# 1. Connection details
# ---------------------------------------------------------------------------

heading "Server connection"

ask VPS_HOST      "VPS hostname or IP address"
ask VPS_SSH_PORT  "SSH port" "22"
ask VPS_SSH_USER  "SSH username" "root"

if [[ -z "${VPS_AUTH_METHOD:-}" ]]; then
  echo
  echo "  How should I authenticate?"
  echo "    1) SSH key (recommended)"
  echo "    2) Password"
  ask VPS_AUTH_CHOICE "Choose" "1"
  case "$VPS_AUTH_CHOICE" in
    2) VPS_AUTH_METHOD="password" ;;
    *) VPS_AUTH_METHOD="key" ;;
  esac
fi

SSH_OPTS=(-p "${VPS_SSH_PORT}" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)
SCP_OPTS=(-P "${VPS_SSH_PORT}" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)
SSH_PREFIX=()

if [[ "$VPS_AUTH_METHOD" == "key" ]]; then
  ask VPS_SSH_KEY "Path to your private key" "${HOME}/.ssh/id_rsa"
  VPS_SSH_KEY="${VPS_SSH_KEY/#\~/$HOME}"
  [[ -f "$VPS_SSH_KEY" ]] || die "No key at ${VPS_SSH_KEY}"
  SSH_OPTS+=(-i "$VPS_SSH_KEY" -o IdentitiesOnly=yes)
  SCP_OPTS+=(-i "$VPS_SSH_KEY" -o IdentitiesOnly=yes)
else
  require_command sshpass "On macOS: brew install hudochenkov/sshpass/sshpass. Using an SSH key avoids this entirely."
  ask_secret VPS_SSH_PASSWORD "SSH password"
  export SSHPASS="$VPS_SSH_PASSWORD"
  SSH_PREFIX=(sshpass -e)
fi

remote() {
  "${SSH_PREFIX[@]}" ssh "${SSH_OPTS[@]}" "${VPS_SSH_USER}@${VPS_HOST}" "$@"
}
remote_copy() {
  "${SSH_PREFIX[@]}" scp "${SCP_OPTS[@]}" "$1" "${VPS_SSH_USER}@${VPS_HOST}:$2"
}

info "Testing the connection…"
if ! remote 'echo ok' >/dev/null 2>&1; then
  die "Cannot reach ${VPS_SSH_USER}@${VPS_HOST}:${VPS_SSH_PORT}. Check the address, port and credentials."
fi
success "Connected to ${VPS_HOST}"

REMOTE_OS="$(remote 'source /etc/os-release 2>/dev/null && echo "${ID}:${VERSION_ID}" || echo unknown')"
REMOTE_ARCH="$(remote 'uname -m')"
info "Detected ${REMOTE_OS} on ${REMOTE_ARCH}"

case "${REMOTE_OS%%:*}" in
  ubuntu|debian|almalinux|rocky|rhel|centos) : ;;
  *) warn "Untested distribution '${REMOTE_OS}'. The script will try the closest match and stop if a package is missing." ;;
esac

# Establish how we become root, once, rather than guessing per command.
if [[ "$VPS_SSH_USER" == "root" ]]; then
  SUDO=""
elif remote 'sudo -n true' >/dev/null 2>&1; then
  SUDO="sudo -n"
else
  warn "The ${VPS_SSH_USER} account needs passwordless sudo, or run this as root."
  ask_yes_no VPS_SUDO_PASSWORD_PROMPT "Try sudo with a password prompt?" "y"
  [[ "$VPS_SUDO_PASSWORD_PROMPT" == "y" ]] || die "Cannot escalate privileges on the server."
  SUDO="sudo"
fi

if [[ "$MODE" == "check" ]]; then
  heading "Connectivity check"
  remote "${SUDO} true" >/dev/null 2>&1 && success "Privilege escalation works" || die "sudo failed"
  success "Everything needed to run the full setup is in place."
  exit 0
fi

# ---------------------------------------------------------------------------
# 2. What to build
# ---------------------------------------------------------------------------

heading "WHMCS installation"

ask WHMCS_DOMAIN "Domain for WHMCS (e.g. billing.example.com)"
is_hostname "$WHMCS_DOMAIN" || die "'${WHMCS_DOMAIN}' does not look like a hostname."

ask WHMCS_ADMIN_EMAIL "Administrator email (for TLS certificates and alerts)"
[[ "$WHMCS_ADMIN_EMAIL" == *@* ]] || die "'${WHMCS_ADMIN_EMAIL}' does not look like an email address."

ask WHMCS_WEBROOT   "Document root on the server" "/var/www/whmcs"
ask WHMCS_PHP       "PHP version (WHMCS 8.13 supports 8.1, 8.2 and 8.3)" "8.2"
case "$WHMCS_PHP" in
  8.1|8.2|8.3) : ;;
  *) die "PHP ${WHMCS_PHP} is not supported by WHMCS 8.13. Choose 8.1, 8.2 or 8.3." ;;
esac

ask WHMCS_DB_NAME "Database name" "whmcs"
ask WHMCS_DB_USER "Database username" "whmcs"
if [[ -z "${WHMCS_DB_PASSWORD:-}" ]]; then
  WHMCS_DB_PASSWORD="$(random_token 28)"
  log "Generated a database password (shown once at the end)."
fi

ask_yes_no WHMCS_ISSUE_TLS "Obtain a Let's Encrypt certificate for ${WHMCS_DOMAIN}?" "y"
if [[ "$WHMCS_ISSUE_TLS" == "y" ]]; then
  warn "${WHMCS_DOMAIN} must already point at ${VPS_HOST} for this to succeed."
fi
ask_yes_no WHMCS_CONFIGURE_FIREWALL "Configure the firewall (allow 22, 80, 443)?" "y"

heading "WHMCS source"
echo "  WHMCS is licensed software, so its archive cannot be downloaded automatically."
echo "  Download it from your WHMCS members area first."
echo
ask WHMCS_ARCHIVE "Path to whmcs.zip on this machine (or a URL, or 'skip')" "skip"

if [[ "$WHMCS_ARCHIVE" != "skip" && "$WHMCS_ARCHIVE" != http* ]]; then
  WHMCS_ARCHIVE="${WHMCS_ARCHIVE/#\~/$HOME}"
  [[ -f "$WHMCS_ARCHIVE" ]] || die "No archive at ${WHMCS_ARCHIVE}"
fi

heading "Theme provisioner"
ask WTP_STORAGE_PATH "Package storage directory (outside the web root)" "/var/lib/whmcs-wtp/packages"
ask WTP_ENV_PATH     "Environment file for secrets" "/etc/whmcs-wtp/wtp.env"
ask_yes_no WTP_DEPLOY_CODE "Deploy the provisioner from this repository now?" "y"

# ---------------------------------------------------------------------------
# 3. Confirm
# ---------------------------------------------------------------------------

heading "Ready to build"
cat <<SUMMARY
  Server        ${VPS_SSH_USER}@${VPS_HOST}:${VPS_SSH_PORT} (${REMOTE_OS})
  Domain        ${WHMCS_DOMAIN}
  Web root      ${WHMCS_WEBROOT}
  PHP           ${WHMCS_PHP} (+ ionCube Loader)
  Database      ${WHMCS_DB_NAME} / ${WHMCS_DB_USER}
  TLS           $([[ "$WHMCS_ISSUE_TLS" == "y" ]] && echo "Let's Encrypt" || echo "self-signed placeholder")
  Firewall      $([[ "$WHMCS_CONFIGURE_FIREWALL" == "y" ]] && echo "configured" || echo "left alone")
  WHMCS archive ${WHMCS_ARCHIVE}
  Storage       ${WTP_STORAGE_PATH}
  Secrets       ${WTP_ENV_PATH}
SUMMARY

if [[ "${WTP_NONINTERACTIVE:-0}" != "1" ]]; then
  echo
  read -r -p "  Proceed? [y/N]: " CONFIRM
  [[ "${CONFIRM,,}" == "y" || "${CONFIRM,,}" == "yes" ]] || die "Cancelled."
fi

# ---------------------------------------------------------------------------
# 4. Provision the server
# ---------------------------------------------------------------------------

REMOTE_WORKDIR="/tmp/wtp-setup-$(random_token 8)"
remote "mkdir -p ${REMOTE_WORKDIR} && chmod 700 ${REMOTE_WORKDIR}"

cleanup_remote() {
  remote "rm -rf ${REMOTE_WORKDIR}" >/dev/null 2>&1 || true
}
trap cleanup_remote EXIT

if [[ "$MODE" == "full" ]]; then
  heading "Preparing the server"

  info "Uploading the provisioning script…"
  remote_copy "${SCRIPT_DIR}/lib/remote-provision.sh" "${REMOTE_WORKDIR}/remote-provision.sh"

  # Answers travel in a file with mode 0600 rather than on the command line,
  # where they would be visible in the server's process list.
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

  remote_copy "$ANSWERS_TMP" "${REMOTE_WORKDIR}/answers.env"
  rm -f "$ANSWERS_TMP"

  if [[ "$WHMCS_ARCHIVE" != "skip" ]]; then
    if [[ "$WHMCS_ARCHIVE" == http* ]]; then
      info "The server will download the WHMCS archive."
      remote "echo \"WHMCS_ARCHIVE_URL='${WHMCS_ARCHIVE}'\" >> ${REMOTE_WORKDIR}/answers.env"
    else
      info "Uploading the WHMCS archive ($(du -h "$WHMCS_ARCHIVE" | cut -f1))…"
      remote_copy "$WHMCS_ARCHIVE" "${REMOTE_WORKDIR}/whmcs.zip"
      remote "echo \"WHMCS_ARCHIVE_FILE='${REMOTE_WORKDIR}/whmcs.zip'\" >> ${REMOTE_WORKDIR}/answers.env"
    fi
  fi

  info "Running the server preparation (this takes a few minutes)…"
  echo

  # -t so apt/dnf progress and any sudo prompt reach the operator.
  "${SSH_PREFIX[@]}" ssh -t "${SSH_OPTS[@]}" "${VPS_SSH_USER}@${VPS_HOST}" \
    "chmod +x ${REMOTE_WORKDIR}/remote-provision.sh && ${SUDO} ${REMOTE_WORKDIR}/remote-provision.sh ${REMOTE_WORKDIR}/answers.env"

  success "Server prepared."
fi

# ---------------------------------------------------------------------------
# 5. Deploy the provisioner
# ---------------------------------------------------------------------------

if [[ "$WTP_DEPLOY_CODE" == "y" ]]; then
  heading "Deploying the provisioner"

  require_command php "Install PHP to build the distribution."
  info "Building dist/…"
  (cd "$REPO_ROOT" && php bin/build.php >/dev/null)

  info "Uploading the WHMCS module tree…"
  ARCHIVE_TMP="$(mktemp -d)"
  tar -czf "${ARCHIVE_TMP}/wtp-whmcs.tar.gz" -C "${REPO_ROOT}/dist/whmcs" .
  remote_copy "${ARCHIVE_TMP}/wtp-whmcs.tar.gz" "${REMOTE_WORKDIR}/wtp-whmcs.tar.gz"
  remote_copy "${REPO_ROOT}/dist/agent/whmcs-theme-provisioner.zip" "${REMOTE_WORKDIR}/agent.zip"
  rm -rf "$ARCHIVE_TMP"

  remote "${SUDO} bash -s" <<REMOTE_DEPLOY
set -euo pipefail
tar -xzf ${REMOTE_WORKDIR}/wtp-whmcs.tar.gz -C '${WHMCS_WEBROOT}'
install -D -m 0640 ${REMOTE_WORKDIR}/agent.zip '${WTP_STORAGE_PATH}/agent/whmcs-theme-provisioner.zip'

WEB_USER=\$(stat -c '%U' '${WHMCS_WEBROOT}' 2>/dev/null || echo www-data)
chown -R "\${WEB_USER}:\${WEB_USER}" '${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner' 2>/dev/null || true
chown -R "\${WEB_USER}:\${WEB_USER}" '${WHMCS_WEBROOT}/modules/servers/wpthemehost' 2>/dev/null || true
chown -R "\${WEB_USER}:\${WEB_USER}" '${WHMCS_WEBROOT}/provisioning' 2>/dev/null || true
chown -R "\${WEB_USER}:\${WEB_USER}" '${WTP_STORAGE_PATH}' 2>/dev/null || true
find '${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner' -type f -exec chmod 0644 {} \;
find '${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner' -type d -exec chmod 0755 {} \;
chmod 0750 '${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php'
REMOTE_DEPLOY

  success "Provisioner deployed."
fi

# ---------------------------------------------------------------------------
# 6. What to do next
# ---------------------------------------------------------------------------

MASTER_KEY_PRESENT="$(remote "${SUDO} grep -c '^WTP_MASTER_KEY=' '${WTP_ENV_PATH}' 2>/dev/null || echo 0")"

heading "Done"

cat <<NEXT
The server is prepared. These steps need a human, so the script stops here.

  ${C_BOLD}1. Run the WHMCS installer${C_RESET}
     Open  https://${WHMCS_DOMAIN}/install/install.php
     Database host      localhost
     Database name      ${WHMCS_DB_NAME}
     Database user      ${WHMCS_DB_USER}
     Database password  ${WHMCS_DB_PASSWORD}

     It asks for your licence key and creates the first admin account —
     neither belongs in a script or in this repository.

  ${C_BOLD}2. Harden the installation${C_RESET} (the script prepared everything but the rename)
     ssh ${VPS_SSH_USER}@${VPS_HOST} '${SUDO} rm -rf ${WHMCS_WEBROOT}/install'
     Rename ${WHMCS_WEBROOT}/admin to something private and set
     \$customadminpath in configuration.php to match.

  ${C_BOLD}3. Activate the provisioner${C_RESET}
     WHMCS admin → System Settings → Addon Modules →
       "WordPress Theme Provisioner" → Activate, then set access control.

  ${C_BOLD}4. Verify${C_RESET}
     ssh ${VPS_SSH_USER}@${VPS_HOST} \\
       'php ${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php --selftest'

  ${C_BOLD}5. Add your themes and demos${C_RESET}
     See docs/theme-demo.md, then enable selection per product under
     the addon's "Products" tab.

Secrets
  Database password    ${WHMCS_DB_PASSWORD}
  Encryption key       $([[ "$MASTER_KEY_PRESENT" == "0" ]] && echo "NOT SET — run bin/wtp-keygen.php" || echo "generated in ${WTP_ENV_PATH} (mode 0600)")

  ${C_YELLOW}Record the database password now — it is not stored anywhere else,
  and this script will not print it again.${C_RESET}
NEXT
