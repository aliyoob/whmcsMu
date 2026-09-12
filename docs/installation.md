# Installation

Two paths: a scripted build of a fresh VPS, or a manual install onto a WHMCS
server you already run. Both end in the same place.

---

## Path A — a fresh VPS

### 1. Prepare the server

From your own machine, in a checkout of this repository:

```bash
./infrastructure/deploy-all.sh
```

(`setup-whmcs.sh` is the same server-preparation work on its own, for when you
want that without the rest.)

It asks for the VPS address, SSH credentials, the domain WHMCS will run on, and
where to put things, then installs and configures:

* nginx with a WHMCS vhost, HTTP→HTTPS redirect and the `/provisioning/v1` route
* PHP-FPM (8.1, 8.2 or 8.3) with every extension WHMCS requires
* the **ionCube Loader** — WHMCS will not start without it
* MariaDB, with a database and user for WHMCS
* certbot and a Let's Encrypt certificate (a self-signed placeholder until DNS
  resolves)
* ufw or firewalld
* cron entries for WHMCS's own tasks and this module's worker
* `/var/lib/whmcs-wtp/packages` for theme and demo packages
* `/etc/whmcs-wtp/wtp.env` (0640, outside the web root) with a freshly
  generated encryption key

Useful flags:

```bash
./infrastructure/setup-whmcs.sh --check                              # connectivity only
./infrastructure/setup-whmcs.sh --config infrastructure/answers.env  # unattended
./infrastructure/setup-whmcs.sh --deploy-only                        # skip server prep
```

The script is idempotent. Anything it replaces is copied to
`/var/backups/wtp-setup-<timestamp>/` first.

### 2. Install WHMCS

If you gave the script your licence key, this is already done — it drives
WHMCS's own documented non-interactive installer:

```
php -f bin/installer.php -- -i -n -c   < configuration JSON on stdin
```

That is the supported command-line path, not a scripted click-through of the
web wizard, so it keeps working across WHMCS releases. The configuration
reaches the installer on stdin from a `0600` file, because WHMCS's own
documentation warns against putting credentials on a command line.

The script then hardens the installation for you:

* removes `install/`
* renames `admin/` to `admin-<random>` and writes `$customadminpath`
* sets `configuration.php` to `0400`
* sets the System URL
* pre-creates this module's tables, so activation is instant

**Without a licence key** the script stops here and prints what you need.
Open `https://your-domain/install/install.php` and use the database
credentials it gave you. Then harden it by hand:

```bash
ssh you@server 'sudo rm -rf /var/www/whmcs/install'
ssh you@server 'sudo chmod 0400 /var/www/whmcs/configuration.php'
```

and rename `/var/www/whmcs/admin` to something unguessable, setting
`$customadminpath` in `configuration.php` to match.

**Replacing an existing installation?** A web root that has files but no
`init.php` is a broken or foreign copy. Extracting on top of it would leave two
copies interleaved, so the deployment refuses and tells you to use:

```bash
./infrastructure/deploy-all.sh --skip-server --reset-whmcs
```

That moves the existing web root to `/var/backups/whmcs-webroot-<timestamp>/`
and extracts your archive into a clean directory. Nothing is deleted.

**No archive or licence yet?** Answer `skip` at the archive prompt. The server
is still prepared completely — only WHMCS itself is left out. Come back with:

```bash
./infrastructure/deploy-all.sh --skip-server
```

and supply the archive and key then. It puts the files in place and installs,
in seconds rather than minutes, because everything else is already there. An
existing installation is detected and left alone, so re-running is always safe.

### 3. Activate the addon

**System Settings → Addon Modules → WordPress Theme Provisioner → Activate.**

Activation creates the `mod_wtp_*` tables. Grant access to the admin roles that
should see it.

### 4. Verify

```bash
ssh you@server \
  'php /var/www/whmcs/modules/addons/wpthemeprovisioner/cron/worker.php --selftest'
```

Every line should read `OK`:

```
  database tables              OK    all present
  master key                   OK    present and usable
  package storage              OK    /var/lib/whmcs-wtp/packages
  agent package                OK    agent/whmcs-theme-provisioner.zip (82134 bytes)
  installer template           OK    wtp-bootstrap.php
  api base url                 OK    https://billing.example.com/provisioning/v1
  pending jobs                 OK    0 due now
```

Anything else is covered in [troubleshooting.md](troubleshooting.md).

---

## Path B — an existing WHMCS installation

The quickest route is the dedicated installer:

```bash
./infrastructure/install-module.sh
```

It asks for the server and signs in, then does the rest itself: finds WHMCS,
reads its database settings out of `configuration.php`, creates the package
storage and the encryption key, tells PHP where to find that key, deploys the
module, makes sure `/provisioning/v1` is routed, installs the worker cron,
creates this module's tables and runs the self-test.

It never edits a WHMCS core file, and the only database objects it creates are
this module's own `mod_wtp_*` tables. Everything it changes is backed up to
`/var/backups/wtp-module-<timestamp>/` first, and it is safe to run repeatedly.

```bash
./infrastructure/install-module.sh --check          # diagnose only
./infrastructure/install-module.sh --webroot /home/billing/public_html
```

Three things remain, all inside WHMCS: activating the addon, adding a cPanel
server, and adding a theme and demo. They are covered at the end of Path A.

### When WHMCS itself runs on cPanel

The installer detects this and changes four things, because a cPanel account is
not a plain server:

| | Plain server | cPanel account |
|---|---|---|
| Encryption key | `/etc/whmcs-wtp/wtp.env` | `<home>/.whmcs-wtp/wtp.env` |
| Package storage | `/var/lib/whmcs-wtp/packages` | `<home>/.whmcs-wtp/packages` |
| How PHP finds the key | `env[WTP_ENV_FILE]` in the php-fpm pool | `env-path.php` in the module directory |
| Worker cron | `/etc/cron.d/whmcs-wtp` | the account's own crontab |
| `/provisioning/v1` | added to the nginx vhost | the shipped `.htaccess` |

The reasons, in the same order:

- **Paths move into the home directory** because CloudLinux's CageFS, and plain
  `open_basedir`, hide everything outside it from the account's PHP. A key the
  web process cannot read is a key that does not exist. The cost is that both
  now count against the account's disk quota — and, usefully, both are included
  in its cPanel backups.
- **`env-path.php`** is a file in the module directory containing the absolute
  path of the environment file and nothing else. cPanel *generates* its php-fpm
  pool files, so a line we appended would be discarded the next time the panel
  rebuilt them — leaving an installation that worked on the day and broke weeks
  later for no visible reason. The pointer file survives rebuilds, needs no
  service restart, holds no secret, and the `.php` extension means a direct HTTP
  request for it executes and prints nothing.
- **The account's crontab** is what cPanel manages, shows, backs up and
  restores. An `/etc/cron.d` entry would be invisible in the panel.
- **The vhost is left alone** for the same reason as the pool files. The shipped
  `provisioning/v1/.htaccess` is the supported way in, and needs `AllowOverride`
  to be anything but `None` plus `mod_rewrite` built in EasyApache 4.

It also runs the account's *own* PHP — read from the vhost's `phpversion`, so
`ea-php83` and CloudLinux's `alt-php83` both resolve correctly — rather than
`/usr/local/bin/php`, which is the system default and regularly a different
version with different extensions.

Finally it asks the question that actually matters, empirically: it runs PHP
*as the account* and checks that it can read the key and the storage directory.
`open_basedir`, CageFS and ordinary permissions all fail in the same way, and
this catches all three before the first order does.

### Doing it by hand

If you would rather not run a script against a live billing system, the same
work, step by step:

### 1. Build

```bash
php bin/build.php
```

This produces `dist/whmcs/` (the module tree) and
`dist/agent/whmcs-theme-provisioner.zip` (the agent package), and reports the
agent's SHA-256.

### 2. Copy the files

```
dist/whmcs/modules/addons/wpthemeprovisioner/   →  <whmcs>/modules/addons/wpthemeprovisioner/
dist/whmcs/modules/servers/wpthemehost/         →  <whmcs>/modules/servers/wpthemehost/
dist/whmcs/provisioning/                        →  <whmcs>/provisioning/
```

The order-form hooks ship inside the addon as
`modules/addons/wpthemeprovisioner/hooks.php`, which WHMCS loads automatically
for any active addon — there is nothing to place in `includes/hooks`.

Or let the deploy script do it:

```bash
./infrastructure/deploy.sh --host billing.example.com --webroot /var/www/whmcs
```

Ownership should match the rest of WHMCS; directories `0755`, files `0644`.

### 3. Create the storage directory

Outside the web root:

```bash
sudo mkdir -p /var/lib/whmcs-wtp/packages/{agent,demos,themes,core}
sudo chown -R www-data:www-data /var/lib/whmcs-wtp
sudo chmod -R 0750 /var/lib/whmcs-wtp
```

Install the agent package into it:

```bash
sudo install -m 0640 -o www-data -g www-data \
  dist/agent/whmcs-theme-provisioner.zip \
  /var/lib/whmcs-wtp/packages/agent/whmcs-theme-provisioner.zip
```

### 4. Create the environment file

```bash
sudo mkdir -p /etc/whmcs-wtp
sudo php bin/wtp-keygen.php --write /etc/whmcs-wtp/wtp.env
sudo chown root:www-data /etc/whmcs-wtp/wtp.env
sudo chmod 0640 /etc/whmcs-wtp/wtp.env
```

Then add the rest:

```ini
WTP_MASTER_KEY=…            # written by wtp-keygen
WTP_STORAGE_PATH=/var/lib/whmcs-wtp/packages
WTP_API_BASE_URL=https://billing.example.com
WTP_ENFORCE_HTTPS=1
WTP_LOG_LEVEL=info
```

PHP must be able to find it. Either put it at the default
`/etc/whmcs-wtp/wtp.env`, or set `WTP_ENV_FILE`:

```ini
; php-fpm pool (e.g. /etc/php/8.2/fpm/pool.d/www.conf)
env[WTP_ENV_FILE] = /etc/whmcs-wtp/wtp.env
```

and in the crontab used for the worker:

```
WTP_ENV_FILE=/etc/whmcs-wtp/wtp.env
```

### 5. Route the API

The endpoint lives at `<whmcs>/provisioning/v1/index.php` and needs everything
under `/provisioning/v1` routed to it.

**nginx**

```nginx
location /provisioning/v1 {
    try_files $uri /provisioning/v1/index.php$is_args$args;
}
```

**Apache** — the shipped `.htaccess` handles it, provided `AllowOverride` is on.

Check it:

```bash
curl -s https://billing.example.com/provisioning/v1/health | jq
```

```json
{ "success": true, "api": "1.0",
  "checks": { "database": "ok", "secrets": "ok", "transport": "https" } }
```

### 6. Install the cron entry

The worker must run every minute. Without it, jobs are created and never
advance.

```
* * * * * www-data WTP_ENV_FILE=/etc/whmcs-wtp/wtp.env /usr/bin/php -q \
  /var/www/whmcs/modules/addons/wpthemeprovisioner/cron/worker.php >/dev/null 2>&1
```

Overlapping runs are safe — the worker takes a lock and every step is
idempotent.

### 7. Create this module's tables

Either activate the addon in WHMCS, or do it from the command line:

```bash
sudo -u www-data WTP_ENV_FILE=/etc/whmcs-wtp/wtp.env \
  php <whmcs>/modules/addons/wpthemeprovisioner/cron/worker.php --migrate
```

Both run the same migrations, both are idempotent. Doing it up front just makes
activation instant.

### 8. Activate and verify

As in Path A, steps 3 and 4.

---

## Upgrading

```bash
git pull
php bin/run-tests.php
./infrastructure/deploy.sh --host billing.example.com
```

If the schema changed, open the addon's **Settings** tab and press **Run
pending migrations** (or deactivate and reactivate the addon). Migrations are
additive and recorded, so running them twice is a no-op.

The agent package is versioned by content: existing sites keep the agent they
have, and new provisionings get the new one. There is no upgrade path for an
agent on a finished site, and none is needed — it has already done its job and
revoked its own credentials.

---

## Uninstalling

Deactivating the addon deliberately leaves the tables and the stored packages
alone: live customer sites were provisioned from that data and it is the only
record of what was installed where.

To remove it completely, after confirming no service depends on it:

```sql
DROP TABLE mod_wtp_download_grants, mod_wtp_rate_limits, mod_wtp_idempotency,
           mod_wtp_api_nonces, mod_wtp_job_events, mod_wtp_job_steps,
           mod_wtp_jobs, mod_wtp_service_selection, mod_wtp_product_settings,
           mod_wtp_demo_assets, mod_wtp_demos, mod_wtp_theme_versions,
           mod_wtp_themes, mod_wtp_settings;
```

```bash
sudo rm -rf /var/lib/whmcs-wtp /etc/whmcs-wtp
sudo rm -rf /var/www/whmcs/modules/addons/wpthemeprovisioner \
            /var/www/whmcs/modules/servers/wpthemehost \
            /var/www/whmcs/provisioning
sudo rm -f /etc/cron.d/whmcs-wtp
```

Already-provisioned WordPress sites keep working. Their agents are dormant and
hold no live credentials.
