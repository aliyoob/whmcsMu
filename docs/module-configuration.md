# Configuration

Three places hold configuration, and the split is deliberate.

| Where | What | Why there |
|---|---|---|
| `/etc/whmcs-wtp/wtp.env` | Secrets and infrastructure paths | Outside the web root, outside the database, outside git |
| Addon configuration screen | Operator-facing defaults | Where an operator expects to find them |
| Product configuration | Per-product behaviour | WHMCS's own model |

---

## The environment file

Read by the addon, the worker and the API endpoint. Found at
`/etc/whmcs-wtp/wtp.env`, or wherever `WTP_ENV_FILE` points.

Precedence: **environment file → real environment variable → `mod_wtp_settings`
→ built-in default.** Secrets are only ever read from the first two.

### Secrets

| Variable | Notes |
|---|---|
| `WTP_MASTER_KEY` | **Required.** AES-256-GCM master key, base64url. Generate with `php bin/wtp-keygen.php`. Losing it makes every stored credential unreadable. |

### Paths

| Variable | Default | |
|---|---|---|
| `WTP_STORAGE_PATH` | `/var/lib/whmcs-wtp/packages` | Must be outside the web root |
| `WTP_AGENT_PACKAGE` | `agent/whmcs-theme-provisioner.zip` | Relative to the storage root |
| `WTP_BOOTSTRAP_TEMPLATE` | (shipped) | Override only to test a modified installer |

### API

| Variable | Default | |
|---|---|---|
| `WTP_API_BASE_URL` | WHMCS system URL | Must be reachable over HTTPS **from your hosting servers** |
| `WTP_API_PATH` | `/provisioning/v1` | Change only alongside the web server route |
| `WTP_ENFORCE_HTTPS` | `1` | Set to `0` only in a lab. It disables transport security. |
| `WTP_CLOCK_SKEW` | `300` | Signature validity window, seconds |
| `WTP_TRUSTED_PROXIES` | (none) | Comma-separated. Only then is `X-Forwarded-For` believed. |

### Lifetimes

| Variable | Default | |
|---|---|---|
| `WTP_BOOTSTRAP_TTL` | `1800` | Also sets the installer's own lifetime (×4, minimum 1 h) |
| `WTP_SECRET_TTL` | `86400` | Agent secret lifetime |
| `WTP_DOWNLOAD_TTL` | `600` | Signed download URL lifetime |
| `WTP_DOWNLOAD_MAX_USES` | `3` | Uses per grant |
| `WTP_NONCE_TTL` | `900` | Replay window |
| `WTP_IDEMPOTENCY_TTL` | `86400` | How long a stored response is replayed |

### Worker

| Variable | Default | |
|---|---|---|
| `WTP_WORKER_BATCH` | `10` | Jobs per tick |
| `WTP_MAX_JOB_ATTEMPTS` | `5` | Job-level attempt cap |
| `WTP_HTTP_TIMEOUT` | `30` | Outbound request timeout |
| `WTP_HTTP_CONNECT_TIMEOUT` | `10` | |

### Rate limits

| Variable | Default | |
|---|---|---|
| `WTP_RATE_LIMIT_API` | `300` | API calls per job per window |
| `WTP_RATE_LIMIT_WINDOW` | `300` | Window, seconds |
| `WTP_RATE_LIMIT_AUTH_FAILURES` | `10` | Auth failures per IP per window |

### WordPress

| Variable | Default | |
|---|---|---|
| `WTP_WP_CORE_SOURCE` | `mirror` | `mirror` caches the archive locally and serves it over a signed link; `wordpress.org` makes each account download it directly |
| `WTP_WP_VERSION` | `latest` | Or an explicit `6.5.2` |

`mirror` is the default for three reasons: many hosting accounts have
restricted outbound access, wordpress.org publishes a `.sha1` rather than the
sha256 used everywhere else here, and a hundred provisionings a day should not
be a hundred downloads.

### Logging

| Variable | Default | |
|---|---|---|
| `WTP_LOG_LEVEL` | `info` | `debug` while setting up a new theme |
| `WTP_LOG_RETENTION_DAYS` | `60` | Event log retention |

### Example

```ini
WTP_MASTER_KEY=xK9mP2vL8nQ4wR7tY1uI3oP5aS6dF0gH2jK4lZ8xC1v
WTP_STORAGE_PATH=/var/lib/whmcs-wtp/packages
WTP_API_BASE_URL=https://billing.example.com
WTP_ENFORCE_HTTPS=1
WTP_WP_CORE_SOURCE=mirror
WTP_WP_VERSION=latest
WTP_LOG_LEVEL=info
WTP_LOG_RETENTION_DAYS=90
```

---

## The addon configuration screen

**System Settings → Addon Modules → WordPress Theme Provisioner → Configure.**

| Field | |
|---|---|
| Package storage path | Mirrored into `mod_wtp_settings` so the worker and the API can read it |
| Agent API base URL | Leave empty to use the WHMCS system URL |
| WordPress core source | `mirror` or `wordpress.org` |
| WordPress version | `latest` or explicit |
| Log level | |
| Log retention (days) | |

No secret can be entered here, by design: an operator should not be able to
paste a key into a form that writes it to `tbladdonmodules`.

---

## The server (WHM)

**System Settings → Servers → Add New Server.**

| Field | |
|---|---|
| Hostname | The WHM hostname |
| IP address | Used to pin connections while DNS propagates. **Set it.** |
| Server type | cPanel |
| Username | `root`, or a reseller with the required ACLs |
| Access hash / API token | **A WHM API token is strongly preferred** over the root password |
| Secure | On (port 2087) |

Creating a token in WHM: **Development → Manage API Tokens → Generate Token.**
It needs `create-acct`, `suspend-acct`, `passwd`, `list-accts` and cPanel API
access. Paste it into the **Access Hash** field.

A token is revocable, scopable and auditable; a root password is none of those.

---

## The product

**System Settings → Products/Services → the product → Module Settings.**

Module: **WordPress Hosting (Theme Provisioner)**.

| Option | Meaning |
|---|---|
| cPanel package | The WHM package name to create accounts with |
| Install WordPress | Off means "create the account only" — the WordPress steps are marked skipped and the job closes cleanly |
| Site URL scheme | `https` unless your platform does not issue certificates automatically |
| WordPress locale | e.g. `en_GB`, `fa_IR`. Empty for the WordPress default. |
| Show WordPress login to client | Displays the generated administrator credentials on the service page |

Then, in the addon's **Products** tab, enable theme selection for this product
and set its defaults.

### Verifying

Press **Test Connection** on the server. It checks WHM *and* the addon, and
tells you plainly which part is wrong:

```
Connected to WHM 110.0.15, but the agent package is missing from storage
(agent/whmcs-theme-provisioner.zip).
```

---

## Cron

```
* * * * * www-data WTP_ENV_FILE=/etc/whmcs-wtp/wtp.env /usr/bin/php -q \
  /var/www/whmcs/modules/addons/wpthemeprovisioner/cron/worker.php >/dev/null 2>&1
```

Without it, jobs are created and never advance. It is the single most common
cause of "provisioning is stuck at 5 %".

Options:

```bash
php cron/worker.php --selftest          # check the configuration, exit non-zero on a problem
php cron/worker.php --verbose           # one tick, narrated
php cron/worker.php --job=<uuid>        # advance one job
php cron/worker.php --loop=300 --verbose  # tick for five minutes; useful while debugging
php cron/worker.php --limit=1           # one job per tick
```

Overlapping runs are safe: the worker takes a lock and exits if another holds it.

---

## Client area

The customer's progress panel comes from the provisioning module's
`templates/clientarea.tpl` and needs no template changes. To restyle it, copy
that file into your order form template's overrides.

The order-form picker is injected by hooks and needs no template edits either.
It anchors on `form#frmProduct` and several fallbacks, which covers the stock
`standard_cart` and `universal_slider` forms. For a heavily customised order
form, add an explicit anchor:

```smarty
{* in your configureproduct.tpl, wherever the picker should appear *}
<div id="wtp-picker-anchor"></div>
```
