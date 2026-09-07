# WHMCS WordPress Theme Provisioner

Lets a customer choose a WordPress theme and demo while buying hosting, then
builds the site for them — cPanel account, WordPress, theme, plugins and demo
content — with no operator involvement.

```
Customer picks a plan, a theme and a demo
        │
        ▼
WHMCS order ──► provisioning job ──► cPanel account ──► database
                                            │
                                            ▼
                            installer ──► WordPress ──► agent
                                                          │
                                    ┌─────────────────────┘
                                    ▼
                    agent ⇄ WHMCS provisioning API (HMAC, versioned)
                                    │
                                    ▼
                    theme · plugins · content · widgets · customizer
                                    │
                                    ▼
                            WHMCS verifies the site ──► done
```

---

## What is here

| Path | What it is |
|---|---|
| `whmcs/modules/addons/wpthemeprovisioner/` | The addon: catalogue, job queue, agent API, admin UI, worker, order-form hooks |
| `whmcs/modules/servers/wpthemehost/` | The provisioning module for the hosting product |
| `whmcs/provisioning/v1/` | The public agent API entry point |
| `wordpress/mu-plugins/whmcs-theme-provisioner/` | The agent that runs inside each provisioned site |
| `bootstrap/wtp-bootstrap.php` | The single-file installer that turns a bare account into WordPress |
| `shared/` | Code used by **both** sides: signing, safe extraction, redaction, the job state machine |
| `database/migrations/` | Schema |
| `infrastructure/` | Server provisioning and deployment |
| `tests/` | Unit, integration and end-to-end suites |
| `docs/` | Everything below |

## Documentation

| Document | Read it when |
|---|---|
| [architecture.md](docs/architecture.md) | You want to know why it is built this way. Includes eight ADRs. |
| [installation.md](docs/installation.md) | Setting it up for the first time |
| [module-configuration.md](docs/module-configuration.md) | Configuring the product and the addon |
| [theme-demo.md](docs/theme-demo.md) | Adding or changing themes and demos |
| [provisioning.md](docs/provisioning.md) | Understanding the job lifecycle and the retry model |
| [api.md](docs/api.md) | Writing against, or debugging, the agent API |
| [agent.md](docs/agent.md) | Working on the WordPress side |
| [security.md](docs/security.md) | Reviewing the threat model, or rotating a key |
| [troubleshooting.md](docs/troubleshooting.md) | Something is stuck |
| [quickstart-fa.md](docs/quickstart-fa.md) | راهنمای کامل راه‌اندازی به فارسی |

## Quick start

```bash
# 1. Everything: prepares the server, installs WHMCS's dependencies,
#    deploys this module and verifies the result. Asks for the server
#    password; takes 3–10 minutes.
./infrastructure/deploy-all.sh

# 2. Finish the WHMCS web installer by hand (licence key, admin account)

# 3. Activate "WordPress Theme Provisioner" in WHMCS, then verify
ssh you@server 'php /var/www/whmcs/modules/addons/wpthemeprovisioner/cron/worker.php --selftest'
```

`deploy-all.sh` is the one-command path. `setup-whmcs.sh` (server only) and
`deploy.sh` (module only) are the same work split in two, for when you want
one without the other.

Then add a theme and a demo (see [theme-demo.md](docs/theme-demo.md)), point a
hosting product at the `wpthemehost` module, and enable selection for it under
the addon's **Products** tab.

## Development

```bash
php bin/run-tests.php              # unit + integration
php bin/run-tests.php e2e          # the full pipeline and the failure scenarios
php bin/run-tests.php --filter=SafeZip
php bin/build.php                  # assemble dist/
./infrastructure/deploy.sh --host billing.example.com
```

The test suite has no dependencies — no composer, no PHPUnit — so it runs on a
production box after a deploy, which is exactly where it is most useful.

`shared/` is the single source of truth for anything both sides need to agree
on. `bin/build.php` copies it into both distributions; never edit the copies.

## Requirements

| | |
|---|---|
| WHMCS | 8.11+ (developed against 8.13) |
| PHP (WHMCS server) | 8.1, 8.2 or 8.3, with ionCube Loader |
| PHP extensions | curl, gd, json, mbstring, openssl, pdo_mysql, xml, zip, intl, bcmath |
| Database | MySQL 5.7+ / MariaDB 10.5+ |
| Control panel | cPanel/WHM with API access (a WHM API token, or root credentials) |
| Hosting accounts | PHP 7.4+ with `zip` and `curl`, and outbound HTTPS to the WHMCS server |

## Licence

Proprietary. See your agreement.
