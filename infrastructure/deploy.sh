#!/usr/bin/env bash
#
# deploy.sh — push a new build of the provisioner to an existing WHMCS server.
#
#   ./infrastructure/deploy.sh --host billing.example.com --webroot /var/www/whmcs
#   ./infrastructure/deploy.sh --config infrastructure/answers.env
#   ./infrastructure/deploy.sh ... --dry-run
#
# Only this module's files are touched. WHMCS itself, its configuration, the
# database and the environment file are never modified.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

DRY_RUN=0
SKIP_TESTS=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --config)     # shellcheck disable=SC1090
                  source "${2:?}"; shift 2 ;;
    --host)       VPS_HOST="${2:?}"; shift 2 ;;
    --user)       VPS_SSH_USER="${2:?}"; shift 2 ;;
    --port)       VPS_SSH_PORT="${2:?}"; shift 2 ;;
    --key)        VPS_SSH_KEY="${2:?}"; shift 2 ;;
    --webroot)    WHMCS_WEBROOT="${2:?}"; shift 2 ;;
    --storage)    WTP_STORAGE_PATH="${2:?}"; shift 2 ;;
    --dry-run)    DRY_RUN=1; shift ;;
    --skip-tests) SKIP_TESTS=1; shift ;;
    --help|-h)    sed -n '2,14p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)            die "Unknown option: $1" ;;
  esac
done

ask VPS_HOST         "VPS hostname or IP"
ask VPS_SSH_USER     "SSH username" "root"
ask VPS_SSH_PORT     "SSH port" "22"
ask WHMCS_WEBROOT    "WHMCS document root" "/var/www/whmcs"
ask WTP_STORAGE_PATH "Package storage path" "/var/lib/whmcs-wtp/packages"

require_command ssh "Install OpenSSH."
require_command rsync "Install rsync on this machine."
require_command php "Install PHP to build the distribution."

SSH_OPTS=(-p "${VPS_SSH_PORT}" -o StrictHostKeyChecking=accept-new)
if [[ -n "${VPS_SSH_KEY:-}" ]]; then
  VPS_SSH_KEY="${VPS_SSH_KEY/#\~/$HOME}"
  SSH_OPTS+=(-i "$VPS_SSH_KEY" -o IdentitiesOnly=yes)
fi

remote() { ssh "${SSH_OPTS[@]}" "${VPS_SSH_USER}@${VPS_HOST}" "$@"; }

SUDO=""
[[ "$VPS_SSH_USER" != "root" ]] && SUDO="sudo -n"

# ---------------------------------------------------------------------------

heading "Pre-flight"

if [[ "$SKIP_TESTS" == "0" ]]; then
  info "Running the test suite…"
  if ! (cd "$REPO_ROOT" && php bin/run-tests.php >/dev/null 2>&1); then
    error "Tests are failing. Fix them, or re-run with --skip-tests if you know why."
    (cd "$REPO_ROOT" && php bin/run-tests.php 2>&1 | tail -20)
    exit 1
  fi
  success "Tests pass"
fi

info "Building dist/…"
(cd "$REPO_ROOT" && php bin/build.php >/dev/null)

AGENT_SHA="$(php -r 'echo json_decode(file_get_contents("'"${REPO_ROOT}"'/dist/checksums.json"), true)["agent_package"]["sha256"];')"
success "Built (agent sha256 ${AGENT_SHA:0:16}…)"

remote "test -f '${WHMCS_WEBROOT}/init.php'" \
  || die "No WHMCS at ${WHMCS_WEBROOT} on ${VPS_HOST}"

# ---------------------------------------------------------------------------

heading "Deploying"

RSYNC_FLAGS=(-az --delete --omit-dir-times --no-perms)
[[ "$DRY_RUN" == "1" ]] && RSYNC_FLAGS+=(--dry-run --itemize-changes)

RSYNC_SSH="ssh ${SSH_OPTS[*]}"

for component in \
  "modules/addons/wpthemeprovisioner" \
  "modules/servers/wpthemehost" \
  "provisioning"
do
  info "→ ${component}"
  rsync "${RSYNC_FLAGS[@]}" -e "$RSYNC_SSH" \
    "${REPO_ROOT}/dist/whmcs/${component}/" \
    "${VPS_SSH_USER}@${VPS_HOST}:${WHMCS_WEBROOT}/${component}/"
done

if [[ "$DRY_RUN" == "1" ]]; then
  warn "Dry run: nothing was changed on the server."
  exit 0
fi

info "→ agent package"
rsync -az -e "$RSYNC_SSH" \
  "${REPO_ROOT}/dist/agent/whmcs-theme-provisioner.zip" \
  "${VPS_SSH_USER}@${VPS_HOST}:/tmp/wtp-agent.zip"

remote "${SUDO} bash -s" <<REMOTE
set -euo pipefail
WEB_USER=\$(stat -c '%U' '${WHMCS_WEBROOT}/init.php')
install -D -m 0640 -o "\${WEB_USER}" -g "\${WEB_USER}" \
  /tmp/wtp-agent.zip '${WTP_STORAGE_PATH}/agent/whmcs-theme-provisioner.zip'
rm -f /tmp/wtp-agent.zip

chown -R "\${WEB_USER}:\${WEB_USER}" \
  '${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner' \
  '${WHMCS_WEBROOT}/modules/servers/wpthemehost' \
  '${WHMCS_WEBROOT}/provisioning'
find '${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner' -type d -exec chmod 0755 {} \;
find '${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner' -type f -exec chmod 0644 {} \;
REMOTE

success "Deployed"

# ---------------------------------------------------------------------------

heading "Verifying"

if remote "${SUDO} -u \$(stat -c '%U' '${WHMCS_WEBROOT}/init.php') php '${WHMCS_WEBROOT}/modules/addons/wpthemeprovisioner/cron/worker.php' --selftest"; then
  success "The provisioner reports itself healthy."
else
  warn "The self-test reported problems — see the output above."
  warn "The most common causes are a missing WTP_MASTER_KEY and an unactivated addon."
fi

echo
echo "If the schema changed, open the addon's Settings tab and run the pending"
echo "migrations, or deactivate and reactivate the addon."
