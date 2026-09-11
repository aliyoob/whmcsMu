#!/usr/bin/env bash
#
#  install-module.sh — install the provisioner into an existing WHMCS
#
#  Use this when WHMCS is already installed and working, and you only want to
#  add the WordPress Theme Provisioner:
#
#      ./infrastructure/install-module.sh
#
#  It finds WHMCS on the server, reads its database settings out of
#  configuration.php, creates the package storage and the encryption key,
#  deploys the module, wires up the /provisioning/v1 route and the worker
#  cron, creates this module's tables, and verifies the result.
#
#  It never edits a WHMCS core file, and the only database objects it creates
#  are this module's own mod_wtp_* tables. Anything it changes is backed up to
#  /var/backups/wtp-module-<timestamp>/ first. Safe to run repeatedly.
#
#  Options
#      --host HOST       server address (asked for if omitted)
#      --user USER       SSH user (default root)
#      --port PORT       SSH port (default 22)
#      --key PATH        use an SSH key instead of a password
#      --webroot PATH    where WHMCS is, if auto-detection fails
#      --url URL         the URL WHMCS is served on, e.g. https://billing.example.com
#      --storage PATH    package storage (default /var/lib/whmcs-wtp/packages)
#      --env PATH        encryption key file (default /etc/whmcs-wtp/wtp.env)
#      --check           diagnose only; change nothing
#      --skip-tests      do not run the test suite before building
#      --config FILE     read the answers from a file
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"
# shellcheck source=lib/ssh-auth.sh
source "${SCRIPT_DIR}/lib/ssh-auth.sh"

CHECK_ONLY=n
SKIP_TESTS=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config)   # shellcheck disable=SC1090
                source "${2:?}"; shift 2 ;;
    --host)     VPS_HOST="${2:?}"; shift 2 ;;
    --user)     VPS_SSH_USER="${2:?}"; shift 2 ;;
    --port)     VPS_SSH_PORT="${2:?}"; shift 2 ;;
    --key)      VPS_SSH_KEY="${2:?}"; VPS_AUTH_METHOD=key; shift 2 ;;
    --webroot)  WHMCS_WEBROOT="${2:?}"; shift 2 ;;
    --url)      WTP_API_BASE_URL="${2:?}"; shift 2 ;;
    --storage)  WTP_STORAGE_PATH="${2:?}"; shift 2 ;;
    --env)      WTP_ENV_PATH="${2:?}"; shift 2 ;;
    --check)    CHECK_ONLY=y; shift ;;
    --skip-tests) SKIP_TESTS=1; shift ;;
    --help|-h)  awk 'NR>1 && /^#/ { sub(/^#[ ]?/, ""); print; next } NR>1 { exit }' "${BASH_SOURCE[0]}"; exit 0 ;;
    *)          die "Unknown option: $1 (try --help)" ;;
  esac
done

trap wtp_ssh_cleanup EXIT

cat <<'BANNER'

  WordPress Theme Provisioner
  Install into an existing WHMCS

BANNER

# ---------------------------------------------------------------------------

heading "Checking your machine"
require_command ssh "Install OpenSSH."
require_command scp "Install OpenSSH."
require_command tar "Install tar."
require_command php "Install PHP 7.4 or newer to build the module."
success "php, ssh, scp, tar"

heading "Server"

ask VPS_HOST     "Server address (where WHMCS runs)"
ask VPS_SSH_PORT "SSH port" "22"
ask VPS_SSH_USER "SSH username" "root"

if [[ -z "${VPS_AUTH_METHOD:-}" ]]; then
  echo
  echo "    1) Password"
  echo "    2) SSH key"
  echo
  ask VPS_AUTH_CHOICE "How should I sign in?" "1"
  [[ "$VPS_AUTH_CHOICE" == "2" ]] && VPS_AUTH_METHOD="key" || VPS_AUTH_METHOD="password"
fi

if [[ "$VPS_AUTH_METHOD" == "key" ]]; then
  ask VPS_SSH_KEY "Path to your private key" "${HOME}/.ssh/id_ed25519"
  VPS_SSH_KEY="${VPS_SSH_KEY/#\~/$HOME}"
  [[ -f "$VPS_SSH_KEY" ]] || die "No key at ${VPS_SSH_KEY}"
else
  ask_secret VPS_SSH_PASSWORD "Password for ${VPS_SSH_USER}@${VPS_HOST}"
  [[ -n "$VPS_SSH_PASSWORD" ]] || die "The password cannot be empty."
fi

wtp_ssh_init

SSH_OPTS=(-p "${VPS_SSH_PORT}" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20)
SCP_OPTS=(-P "${VPS_SSH_PORT}" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20)
if [[ "$VPS_AUTH_METHOD" == "key" ]]; then
  SSH_OPTS+=(-i "$VPS_SSH_KEY" -o IdentitiesOnly=yes)
  SCP_OPTS+=(-i "$VPS_SSH_KEY" -o IdentitiesOnly=yes)
else
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
contains "$(remote_out 'echo wtp-connection-ok' || true)" 'wtp-connection-ok' \
  || die "Could not sign in to ${TARGET}:${VPS_SSH_PORT}."
success "Signed in to ${VPS_HOST}"

REMOTE_OS="$(remote_value 'source /etc/os-release && echo "${PRETTY_NAME}"' || true)"
info "${REMOTE_OS:-unknown system}"

if [[ "$VPS_SSH_USER" == "root" ]]; then
  SUDO=""
elif contains "$(remote_out 'sudo -n true 2>/dev/null && echo wtp-sudo-ok' || true)" 'wtp-sudo-ok'; then
  SUDO="sudo -n"
else
  die "The '${VPS_SSH_USER}' account cannot become root without a prompt."
fi

# ---------------------------------------------------------------------------

heading "WHMCS"

# Find it before asking, so the question has a sensible default.
if [[ -z "${WHMCS_WEBROOT:-}" ]]; then
  DETECTED="$(remote_value '
    for c in /var/www/whmcs /var/www/html/whmcs /var/www/html \
             /home/*/public_html/whmcs /home/*/public_html \
             /usr/share/nginx/html/whmcs /srv/www/whmcs; do
      if [ -f "$c/init.php" ] && [ -d "$c/modules" ]; then echo "$c"; break; fi
    done' || true)"

  if [[ -n "$DETECTED" ]]; then
    success "Found WHMCS at ${DETECTED}"
    ask WHMCS_WEBROOT "WHMCS document root" "$DETECTED"
  else
    warn "Could not find WHMCS in the usual places."
    ask WHMCS_WEBROOT "WHMCS document root"
  fi
fi

contains "$(remote_out "test -f '${WHMCS_WEBROOT}/init.php' && echo yes" || true)" yes \
  || die "No init.php in ${WHMCS_WEBROOT} — that is not a WHMCS installation."

# WHMCS keeps its System URL in the database, which we cannot read before we
# know the credentials — so ask, and validate what comes back.
ask WTP_API_BASE_URL "URL WHMCS is served on (e.g. https://billing.example.com)"
WTP_API_BASE_URL="${WTP_API_BASE_URL%/}"

case "$WTP_API_BASE_URL" in
  https://*) : ;;
  http://*)  warn "That is plain HTTP. The agent API refuses to run without TLS"
             warn "unless you set WTP_ENFORCE_HTTPS=0, which you should not do in production." ;;
  *)         die "The URL must start with http:// or https://" ;;
esac

heading "Paths"
ask WTP_STORAGE_PATH "Where to keep theme and demo packages" "/var/lib/whmcs-wtp/packages"
ask WTP_ENV_PATH     "Where to keep the encryption key"      "/etc/whmcs-wtp/wtp.env"

# ---------------------------------------------------------------------------

heading "Plan"
cat <<PLAN
  Server         ${TARGET}:${VPS_SSH_PORT}
                 ${REMOTE_OS:-unknown}
  WHMCS          ${WHMCS_WEBROOT}
  URL            ${WTP_API_BASE_URL}
  Storage        ${WTP_STORAGE_PATH}
  Encryption key ${WTP_ENV_PATH}
  Mode           $([[ "$CHECK_ONLY" == "y" ]] && echo "check only — nothing will change" || echo "install")
PLAN

if [[ "${WTP_NONINTERACTIVE:-0}" != "1" ]]; then
  echo
  read -r -p "  Start? [y/N]: " CONFIRM
  is_yes "$CONFIRM" || die "Cancelled."
fi

REMOTE_WORKDIR="/tmp/wtp-module-$(random_token 10)"
remote "mkdir -p ${REMOTE_WORKDIR} && chmod 700 ${REMOTE_WORKDIR}" >/dev/null
trap 'remote "rm -rf ${REMOTE_WORKDIR}" >/dev/null 2>&1 || true; wtp_ssh_cleanup' EXIT

# ---------------------------------------------------------------------------

if [[ "$CHECK_ONLY" != "y" ]]; then
  heading "1/2  Building"

  if [[ "$SKIP_TESTS" == "0" ]]; then
    info "Running the test suite…"
    if (cd "$REPO_ROOT" && php bin/run-tests.php >/dev/null 2>&1); then
      success "Tests pass"
    else
      error "The test suite is failing — refusing to install."
      (cd "$REPO_ROOT" && php bin/run-tests.php 2>&1 | tail -20)
      die "Fix them, or re-run with --skip-tests if you know why."
    fi
  fi

  info "Assembling dist/…"
  (cd "$REPO_ROOT" && php bin/build.php >/dev/null) || die "Build failed."

  AGENT_SHA="$(php -r '
    $d = json_decode((string) file_get_contents("'"${REPO_ROOT}"'/dist/checksums.json"), true);
    echo $d["agent_package"]["sha256"] ?? "";
  ')"
  success "Built — agent sha256 ${AGENT_SHA:0:16}…"

  info "Uploading…"
  BUNDLE="$(mktemp -d)"
  TAR_OPTS=()
  tar --no-xattrs -cf /dev/null -T /dev/null 2>/dev/null && TAR_OPTS+=(--no-xattrs)
  COPYFILE_DISABLE=1 tar "${TAR_OPTS[@]}" -czf "${BUNDLE}/wtp-whmcs.tar.gz" -C "${REPO_ROOT}/dist/whmcs" .

  push "${BUNDLE}/wtp-whmcs.tar.gz" "${REMOTE_WORKDIR}/wtp-whmcs.tar.gz"
  push "${REPO_ROOT}/dist/agent/whmcs-theme-provisioner.zip" "${REMOTE_WORKDIR}/agent.zip"
  rm -rf "$BUNDLE"
  success "Uploaded"
fi

heading "$([[ "$CHECK_ONLY" == "y" ]] && echo "Checking" || echo "2/2  Installing")"

ANSWERS_LOCAL="$(mktemp)"
chmod 600 "$ANSWERS_LOCAL"
cat > "$ANSWERS_LOCAL" <<ANSWERS
WHMCS_WEBROOT='${WHMCS_WEBROOT}'
WTP_API_BASE_URL='${WTP_API_BASE_URL}'
WTP_STORAGE_PATH='${WTP_STORAGE_PATH}'
WTP_ENV_PATH='${WTP_ENV_PATH}'
WTP_CHECK_ONLY='${CHECK_ONLY}'
ANSWERS

push "$ANSWERS_LOCAL" "${REMOTE_WORKDIR}/answers.env"
rm -f "$ANSWERS_LOCAL"
push "${SCRIPT_DIR}/lib/install-module-remote.sh" "${REMOTE_WORKDIR}/install-module-remote.sh"

REMOTE_OUTPUT="$(mktemp)"
set +e
remote_tty "chmod 700 '${REMOTE_WORKDIR}/install-module-remote.sh' && \
            ${SUDO} bash '${REMOTE_WORKDIR}/install-module-remote.sh' '${REMOTE_WORKDIR}/answers.env'" \
  2>&1 | tee "$REMOTE_OUTPUT"
REMOTE_STATUS=${PIPESTATUS[0]}
set -e

ADMIN_PATH="$(sed -n 's/^WTP_ADMIN_PATH=//p' "$REMOTE_OUTPUT" | tr -d '\r' | tail -1)"
WEB_USER="$(sed -n 's/^WTP_WEB_USER=//p' "$REMOTE_OUTPUT" | tr -d '\r' | tail -1)"
SELFTEST_PASSED=0
contains "$(cat "$REMOTE_OUTPUT")" "All checks passed" && SELFTEST_PASSED=1
rm -f "$REMOTE_OUTPUT"

[[ "$REMOTE_STATUS" -eq 0 ]] || die "Installation failed — the output above says where."

if [[ "$CHECK_ONLY" == "y" ]]; then
  heading "Check complete"
  echo "Nothing was changed. Re-run without --check to install."
  exit 0
fi

# ---------------------------------------------------------------------------

heading "Installed"

cat <<NEXT
The module is on the server. Three steps left, all inside WHMCS.

  ${C_BOLD}1  Activate the addon${C_RESET}

     ${C_BLUE}${WTP_API_BASE_URL}/${ADMIN_PATH:-admin}/${C_RESET}
     System Settings → Addon Modules → "WordPress Theme Provisioner"
       → Activate → tick the admin roles that should see it

     WHMCS has no supported way to activate an addon from outside its admin
     area, so this one stays manual. Its tables already exist, so it is instant.

  ${C_BOLD}2  Add your cPanel server${C_RESET}

     System Settings → Servers → Add New Server

       Type          cPanel
       Hostname      your WHM hostname
       IP Address    fill this in — it is what lets provisioning work before
                     a customer's DNS has propagated
       Username      root
       Access Hash   a WHM API token, not the root password
       Secure        on

     Make the token in WHM → Development → Manage API Tokens, with
     create-acct, suspend-acct, passwd, list-accts and cPanel API access.

     Then press Test Connection. It checks WHM *and* this module, and says
     plainly which part is wrong.

  ${C_BOLD}3  Add a theme and a demo, then wire up a product${C_RESET}

     Addons → WordPress Theme Provisioner → Themes & demos
     See docs/theme-demo.md. The theme slug must match the directory name
     inside the zip — that is the one field worth double-checking.

     Then point a hosting product at "WordPress Hosting (Theme Provisioner)"
     and enable selection for it in the addon's Products tab.

${C_BOLD}Verify at any time${C_RESET}
  ./infrastructure/install-module.sh --host ${VPS_HOST} --check

$(if [[ "$SELFTEST_PASSED" == "1" ]]; then
    printf '%s' "${C_GREEN}The self-test already passes.${C_RESET}"
  else
    printf '%s' "${C_YELLOW}Some self-test checks did not pass — 'database tables' clears once you
activate the addon, and 'api base url' once WHMCS's System URL is set.${C_RESET}"
  fi)

${C_YELLOW}Back up ${WTP_ENV_PATH}${C_RESET}
  Losing that key makes every stored credential unreadable, permanently.

NEXT
