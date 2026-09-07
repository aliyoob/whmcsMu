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
#  Given a licence key it also installs WHMCS itself, through WHMCS's own
#  documented non-interactive installer. Without one it stops at the point
#  where you would open the web installer, and prints the details for it.
#
#  Options
#      --config FILE     load the answers from a file instead of asking
#      --yes             accept every default, never prompt
#      --skip-server     the server is already prepared; deploy the module only
#      --skip-deploy     prepare the server only
#      --skip-tests      do not run the test suite before deploying
#      --skip-whmcs      do not run the WHMCS installer, even with a licence key
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
SKIP_WHMCS_INSTALL=0
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
    --skip-whmcs)  SKIP_WHMCS_INSTALL=1; shift ;;
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

# Run a locally-written script on the server.
#
# Not a heredoc piped into ssh: under the expect strategy that heredoc becomes
# *expect's* stdin and the remote shell receives nothing at all. Uploading the
# script also keeps every credential out of the server's process list, which a
# command line would not.
#
#   run_remote_script <local-file> [extra files to upload alongside it...]
run_remote_script() {
  local script="$1"; shift
  local name="wtp-$(random_token 8).sh"

  push "$script" "${REMOTE_WORKDIR}/${name}"

  local extra
  for extra in "$@"; do
    push "$extra" "${REMOTE_WORKDIR}/$(basename "$extra")"
  done

  remote_tty "chmod 700 '${REMOTE_WORKDIR}/${name}' && ${SUDO} bash '${REMOTE_WORKDIR}/${name}';               status=\$?; rm -f '${REMOTE_WORKDIR}/${name}'; exit \$status"
}

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
echo "  automatically. Get whmcs.zip from your members area first:"
echo "    https://www.whmcs.com/members/  →  Downloads  →  Latest Version"
echo
echo "  Then give the path to it here. Dragging the file into this terminal"
echo "  pastes its path for you."
echo
echo "  Or press Enter to skip: the server is prepared completely and you come"
echo "  back later with:  ./infrastructure/deploy-all.sh --skip-server"
echo
ask WHMCS_ARCHIVE "Path to whmcs.zip on this machine (or a URL, or 'skip')" "skip"

# Dragging a file into a terminal wraps the path in quotes and escapes spaces.
WHMCS_ARCHIVE="$(printf '%s' "$WHMCS_ARCHIVE" | sed "s/^['\"]//; s/['\"]$//; s/\\\\ / /g")"

if [[ "$WHMCS_ARCHIVE" != "skip" && "$WHMCS_ARCHIVE" != http* ]]; then
  WHMCS_ARCHIVE="${WHMCS_ARCHIVE/#\~/$HOME}"

  if [[ ! -f "$WHMCS_ARCHIVE" ]]; then
    die "No file at ${WHMCS_ARCHIVE}

  Check the path, or press Enter at that prompt to skip WHMCS for now and
  prepare the server anyway."
  fi
  if [[ "$WHMCS_ARCHIVE" != *.zip ]]; then
    warn "That does not end in .zip — make sure it is the WHMCS archive."
  fi
fi

# ---------------------------------------------------------------------------

heading "WHMCS installation"

if [[ "$SKIP_WHMCS_INSTALL" == "1" || "$WHMCS_ARCHIVE" == "skip" ]]; then
  WHMCS_AUTO_INSTALL="n"
  [[ "$WHMCS_ARCHIVE" == "skip" ]] && note_skip="no WHMCS archive was supplied" || note_skip="--skip-whmcs"
  log "Skipping the automated installation (${note_skip})."
else
  echo "  WHMCS ships a non-interactive installer. If you have your licence key"
  echo "  I can run it and hand you a finished, hardened installation."
  echo "  Without a key, you finish it in a browser instead — that is fine too."
  echo
  ask_yes_no WHMCS_AUTO_INSTALL "Install WHMCS automatically?" "y"
fi

if [[ "$WHMCS_AUTO_INSTALL" == "y" ]]; then
  ask_secret WHMCS_LICENSE "WHMCS licence key"
  if [[ -z "$WHMCS_LICENSE" ]]; then
    warn "No licence key — falling back to the web installer."
    WHMCS_AUTO_INSTALL="n"
  fi
fi

if [[ "$WHMCS_AUTO_INSTALL" == "y" ]]; then
  ask WHMCS_ADMIN_USER "WHMCS administrator username" "admin"

  if [[ -z "${WHMCS_ADMIN_PASSWORD:-}" ]]; then
    if [[ "${WTP_NONINTERACTIVE:-0}" == "1" ]]; then
      WHMCS_ADMIN_PASSWORD="$(random_token 20)"
    else
      ask_secret WHMCS_ADMIN_PASSWORD "Administrator password (blank to generate one)"
    fi
  fi
  if [[ -z "$WHMCS_ADMIN_PASSWORD" ]]; then
    WHMCS_ADMIN_PASSWORD="$(random_token 20)"
    log "Generated an administrator password — shown at the end."
  elif [[ "${#WHMCS_ADMIN_PASSWORD}" -lt 8 ]]; then
    die "WHMCS requires an administrator password of at least 8 characters."
  fi

  ask_yes_no WHMCS_RENAME_ADMIN "Rename the admin directory to something unguessable?" "y"
fi

# ---------------------------------------------------------------------------

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
  Installation   $([[ "$WHMCS_AUTO_INSTALL" == "y" ]] \
                    && echo "automatic (licence supplied), admin user '${WHMCS_ADMIN_USER}'" \
                    || echo "manual — finish it in a browser")

  Packages       ${WTP_STORAGE_PATH}
  Encryption key ${WTP_ENV_PATH}

  Steps          $([[ "$SKIP_SERVER" == "1" ]] && echo "skip server prep" || echo "prepare server")\
 · $([[ "$SKIP_DEPLOY" == "1" ]] && echo "skip deploy" || echo "deploy provisioner")\
$([[ "$WHMCS_AUTO_INSTALL" == "y" ]] && echo " · install WHMCS" || true) · verify
PLAN

if [[ "$DRY_RUN" == "1" ]]; then
  echo
  warn "Dry run — stopping here. Nothing was changed."
  exit 0
fi

if [[ "${WTP_NONINTERACTIVE:-0}" != "1" ]]; then
  echo
  read -r -p "  Start? [y/N]: " CONFIRM
  is_yes "$CONFIRM" || die "Cancelled."
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
  heading "1/5  Preparing the server"

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
  heading "1/5  Preparing the server — skipped"

  if [[ "$WHMCS_ARCHIVE" != "skip" ]] \
     && ! remote_out "test -f '${WHMCS_WEBROOT}/init.php' && echo present" | grep -q present; then
    info "The web root is empty — putting the WHMCS files in place."

    EXTRACT_ANSWERS="$(mktemp)"
    chmod 600 "$EXTRACT_ANSWERS"
    printf "WHMCS_WEBROOT='%s'\n" "$WHMCS_WEBROOT" > "$EXTRACT_ANSWERS"

    if [[ "$WHMCS_ARCHIVE" == http* ]]; then
      printf "WHMCS_ARCHIVE_URL='%s'\n" "$WHMCS_ARCHIVE" >> "$EXTRACT_ANSWERS"
    else
      info "Uploading WHMCS ($(du -h "$WHMCS_ARCHIVE" | cut -f1))…"
      push "$WHMCS_ARCHIVE" "${REMOTE_WORKDIR}/whmcs.zip"
      printf "WHMCS_ARCHIVE_FILE='%s'\n" "${REMOTE_WORKDIR}/whmcs.zip" >> "$EXTRACT_ANSWERS"
    fi

    push "$EXTRACT_ANSWERS" "${REMOTE_WORKDIR}/extract.env"
    rm -f "$EXTRACT_ANSWERS"

    EXTRACT_RUNNER="$(mktemp)"
    printf "set -euo pipefail\nbash '%s/extract-whmcs.sh' '%s/extract.env'\n" \
      "$REMOTE_WORKDIR" "$REMOTE_WORKDIR" > "$EXTRACT_RUNNER"

    run_remote_script "$EXTRACT_RUNNER" "${SCRIPT_DIR}/lib/extract-whmcs.sh" \
      || { rm -f "$EXTRACT_RUNNER"; die "Could not put the WHMCS files in place."; }
    rm -f "$EXTRACT_RUNNER"
    success "WHMCS files in place"
  fi
fi

# ═══════════════════════════════════════════════════════════════════════════
# 4. Build
# ═══════════════════════════════════════════════════════════════════════════

if [[ "$SKIP_DEPLOY" == "0" ]]; then
  heading "2/5  Building the provisioner"

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

  heading "3/5  Deploying"

  info "Packing…"
  BUNDLE="$(mktemp -d)"
  tar -czf "${BUNDLE}/wtp-whmcs.tar.gz" -C "${REPO_ROOT}/dist/whmcs" .

  info "Uploading the module ($(du -h "${BUNDLE}/wtp-whmcs.tar.gz" | cut -f1))…"
  push "${BUNDLE}/wtp-whmcs.tar.gz" "${REMOTE_WORKDIR}/wtp-whmcs.tar.gz"

  info "Uploading the agent package…"
  push "${REPO_ROOT}/dist/agent/whmcs-theme-provisioner.zip" "${REMOTE_WORKDIR}/agent.zip"
  rm -rf "$BUNDLE"

  info "Installing…"
  DEPLOY_SCRIPT="$(mktemp)"
  cat > "$DEPLOY_SCRIPT" <<REMOTE_DEPLOY
set -euo pipefail

WEBROOT='${WHMCS_WEBROOT}'
STORAGE='${WTP_STORAGE_PATH}'
WORKDIR='${REMOTE_WORKDIR}'

[ -d "\$WEBROOT" ] || { echo "No web root at \$WEBROOT" >&2; exit 1; }

# The web user owns WHMCS; match it rather than guessing.
if [ -f "\$WEBROOT/init.php" ]; then
  WEB_USER=\$(stat -c '%U' "\$WEBROOT/init.php")
else
  WEB_USER=\$(stat -c '%U' "\$WEBROOT")
fi
if [ -z "\$WEB_USER" ] || [ "\$WEB_USER" = "root" ]; then
  WEB_USER=\$(id -u www-data >/dev/null 2>&1 && echo www-data || echo nginx)
fi

tar -xzf "\$WORKDIR/wtp-whmcs.tar.gz" -C "\$WEBROOT"

install -D -m 0640 -o "\$WEB_USER" -g "\$WEB_USER" \
  "\$WORKDIR/agent.zip" "\$STORAGE/agent/whmcs-theme-provisioner.zip"

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

  run_remote_script "$DEPLOY_SCRIPT" || { rm -f "$DEPLOY_SCRIPT"; die "Deployment failed."; }
  rm -f "$DEPLOY_SCRIPT"

  success "Deployed"
else
  heading "2/5  Building — skipped"
  heading "3/5  Deploying — skipped"
fi

# ═══════════════════════════════════════════════════════════════════════════
# 6. Install WHMCS
# ═══════════════════════════════════════════════════════════════════════════

WHMCS_ADMIN_PATH="admin"
WHMCS_INSTALLED=0

if [[ "$WHMCS_AUTO_INSTALL" == "y" ]]; then
  heading "4/5  Installing WHMCS"

  # Everything sensitive travels in a 0600 file that the remote script sources
  # and then deletes — never on a command line, which WHMCS's own
  # documentation explicitly warns against.
  INSTALL_ANSWERS="$(mktemp)"
  chmod 600 "$INSTALL_ANSWERS"
  cat > "$INSTALL_ANSWERS" <<ANSWERS
WHMCS_WEBROOT='${WHMCS_WEBROOT}'
WHMCS_DOMAIN='${WHMCS_DOMAIN}'
WHMCS_PHP='${WHMCS_PHP}'
WHMCS_LICENSE='${WHMCS_LICENSE}'
WHMCS_ADMIN_USER='${WHMCS_ADMIN_USER}'
WHMCS_ADMIN_PASSWORD='${WHMCS_ADMIN_PASSWORD}'
WHMCS_DB_HOST='localhost'
WHMCS_DB_NAME='${WHMCS_DB_NAME}'
WHMCS_DB_USER='${WHMCS_DB_USER}'
WHMCS_DB_PASSWORD='${WHMCS_DB_PASSWORD}'
WHMCS_SYSTEM_URL='https://${WHMCS_DOMAIN}/'
WHMCS_RENAME_ADMIN='${WHMCS_RENAME_ADMIN}'
WTP_ENV_PATH='${WTP_ENV_PATH}'
WTP_CREATE_SCHEMA='y'
ANSWERS

  ANSWERS_REMOTE_NAME="whmcs-install-$(random_token 8).env"
  push "$INSTALL_ANSWERS" "${REMOTE_WORKDIR}/${ANSWERS_REMOTE_NAME}"
  shred -u "$INSTALL_ANSWERS" 2>/dev/null || rm -f "$INSTALL_ANSWERS"
  remote "chmod 600 '${REMOTE_WORKDIR}/${ANSWERS_REMOTE_NAME}'" >/dev/null

  INSTALL_RUNNER="$(mktemp)"
  cat > "$INSTALL_RUNNER" <<RUNNER
set -euo pipefail
bash '${REMOTE_WORKDIR}/install-whmcs.sh' '${REMOTE_WORKDIR}/${ANSWERS_REMOTE_NAME}'
status=\$?
shred -u '${REMOTE_WORKDIR}/${ANSWERS_REMOTE_NAME}' 2>/dev/null || rm -f '${REMOTE_WORKDIR}/${ANSWERS_REMOTE_NAME}'
exit \$status
RUNNER

  if run_remote_script "$INSTALL_RUNNER" "${SCRIPT_DIR}/lib/install-whmcs.sh"; then
    WHMCS_INSTALLED=1
    success "WHMCS installed"
  else
    rm -f "$INSTALL_RUNNER"
    remote "shred -u '${REMOTE_WORKDIR}/${ANSWERS_REMOTE_NAME}' 2>/dev/null || rm -f '${REMOTE_WORKDIR}/${ANSWERS_REMOTE_NAME}'" >/dev/null 2>&1 || true
    error "The WHMCS installation did not complete — see the output above."
    warn "Everything else is in place. You can finish it in a browser at"
    warn "  https://${WHMCS_DOMAIN}/install/install.php"
    warn "or re-run this script once the cause is fixed."
    WHMCS_AUTO_INSTALL="failed"
  fi
  rm -f "$INSTALL_RUNNER"

  if [[ "$WHMCS_INSTALLED" == "1" ]]; then
    # The remote script renames the admin directory; recover the new name so
    # the summary can point at the right URL.
    DISCOVERED="$(remote_value "ls -1d '${WHMCS_WEBROOT}'/admin* 2>/dev/null | head -1 | xargs -r basename")"
    [[ -n "$DISCOVERED" ]] && WHMCS_ADMIN_PATH="$DISCOVERED"
  fi
else
  heading "4/5  Installing WHMCS — skipped"
fi

# ═══════════════════════════════════════════════════════════════════════════
# 7. Verify
# ═══════════════════════════════════════════════════════════════════════════

heading "5/5  Verifying"

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
{
  cat <<CREDS
WHMCS deployment — ${WHMCS_DOMAIN}
Generated $(date -u '+%Y-%m-%d %H:%M:%S UTC') by deploy-all.sh

  Server              ${TARGET}:${VPS_SSH_PORT}
  Web root            ${WHMCS_WEBROOT}

  Database host       localhost
  Database name       ${WHMCS_DB_NAME}
  Database username   ${WHMCS_DB_USER}
  Database password   ${WHMCS_DB_PASSWORD}
CREDS

  if [[ "$WHMCS_INSTALLED" == "1" ]]; then
    cat <<CREDS

  Admin URL           https://${WHMCS_DOMAIN}/${WHMCS_ADMIN_PATH}/
  Admin username      ${WHMCS_ADMIN_USER}
  Admin password      ${WHMCS_ADMIN_PASSWORD}
CREDS
  else
    cat <<CREDS

  Installer URL       https://${WHMCS_DOMAIN}/install/install.php
CREDS
  fi

  cat <<CREDS

  Encryption key      ${WTP_ENV_PATH} on the server (mode 0640)
                      Back this file up. Losing it makes every stored
                      credential unreadable and cannot be undone.

  Package storage     ${WTP_STORAGE_PATH}

This file contains secrets. It is gitignored. Move it somewhere safe and
delete it from here.
CREDS
} > "$CREDENTIALS_FILE"
chmod 600 "$CREDENTIALS_FILE"

heading "Done in $((ELAPSED / 60))m $((ELAPSED % 60))s"

if [[ "$WHMCS_INSTALLED" == "1" ]]; then

cat <<NEXT
WHMCS is installed, hardened and ready.

  ${C_BOLD}Sign in${C_RESET}

     ${C_BLUE}https://${WHMCS_DOMAIN}/${WHMCS_ADMIN_PATH}/${C_RESET}

       Username  ${C_BOLD}${WHMCS_ADMIN_USER}${C_RESET}
       Password  ${C_BOLD}${WHMCS_ADMIN_PASSWORD}${C_RESET}

     Already done for you: the install directory removed, configuration.php
     set to 0400, the admin directory renamed, and the System URL configured.

  ${C_BOLD}One click left${C_RESET}

     System Settings → Addon Modules → "WordPress Theme Provisioner"
       → Activate → tick the admin roles that should see it

     WHMCS has no supported way to activate an addon from outside its admin
     area, so this one stays manual. Its tables already exist, so activation
     is instant.

  ${C_BOLD}Then${C_RESET}

     1. Add your cPanel server
        System Settings → Servers → Add New Server
        Use a ${C_BOLD}WHM API token${C_RESET}, not the root password, and fill in the
        IP address — it is what lets provisioning work before DNS propagates.

     2. Add a theme and a demo
        Addons → WordPress Theme Provisioner → Themes & demos
        See docs/theme-demo.md — the theme slug must match the directory
        name inside the zip.

     3. Point a hosting product at "WordPress Hosting (Theme Provisioner)"
        and enable selection for it in the addon's Products tab.

     4. Place a test order and watch it in Addons → … → Jobs.

  ${C_BOLD}Verify any time${C_RESET}

     ssh ${TARGET} \\
       "${SUDO} -u ${WEB_USER} php ${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php --selftest"

NEXT

else

cat <<NEXT
The server is ready. WHMCS itself still needs installing.

  ${C_BOLD}1  Run the WHMCS installer${C_RESET}

     ${C_BLUE}https://${WHMCS_DOMAIN}/install/install.php${C_RESET}

       Database host      localhost
       Database name      ${WHMCS_DB_NAME}
       Database user      ${WHMCS_DB_USER}
       Database password  ${C_BOLD}${WHMCS_DB_PASSWORD}${C_RESET}

     Or re-run this script with your licence key to have it done for you:

       ./infrastructure/deploy-all.sh --skip-server

  ${C_BOLD}2  Lock it down${C_RESET}

     ssh ${TARGET} '${SUDO} rm -rf ${WHMCS_WEBROOT}/install'
     ssh ${TARGET} '${SUDO} chmod 0400 ${WHMCS_WEBROOT}/configuration.php'

     Then rename ${WHMCS_WEBROOT}/admin to something private and set
     \$customadminpath in configuration.php to match.

  ${C_BOLD}3  Activate the provisioner${C_RESET}

     WHMCS admin → System Settings → Addon Modules
       → "WordPress Theme Provisioner" → Activate → set access control

  ${C_BOLD}4  Check it${C_RESET}

     ssh ${TARGET} \\
       "${SUDO} -u ${WEB_USER} php ${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php --selftest"

${C_BOLD}Then${C_RESET}
  · Add a cPanel server:  System Settings → Servers  (use a WHM API token)
  · Add a theme and a demo:  docs/theme-demo.md
  · Point a hosting product at "WordPress Hosting (Theme Provisioner)"
  · Enable selection for it in the addon's Products tab

NEXT

fi

cat <<TAIL
${C_YELLOW}Credentials written to${C_RESET}
  ${CREDENTIALS_FILE}
  Mode 0600, gitignored. Move it somewhere safe and delete it from the repo.

TAIL
