# Troubleshooting

Start here:

```bash
php <whmcs>/modules/addons/wpthemeprovisioner/cron/worker.php --selftest
```

It checks the six things that are wrong 90 % of the time. Then open the job:
**Addon → Jobs → the job**. The timeline shows which step failed, how many
times it was attempted, and the full event log with everything both sides
reported.

---

## Nothing happens after an order

**Symptom.** The job exists but stays at `pending`, or at 5 %, forever.

Almost always the cron entry.

```bash
sudo cat /etc/cron.d/whmcs-wtp
sudo systemctl status cron      # or crond
sudo -u www-data php <whmcs>/modules/addons/wpthemeprovisioner/cron/worker.php --verbose
```

If running it by hand advances the job, cron is not running it. Check the user
in the cron entry matches the web user, and that `WTP_ENV_FILE` is set there —
without it the worker cannot find the master key and exits.

---

## The automated WHMCS installation failed

The script drives WHMCS's own `bin/installer.php`. When it exits non-zero, the
cause is almost always one of four things.

| Symptom | Cause |
|---|---|
| A licence error | The key is wrong, expired, or **bound to another domain or IP**. A WHMCS licence is tied to both; reissue it for the new domain in the WHMCS members area. |
| A connection error during licensing | The server cannot reach WHMCS's licensing service over HTTPS. Check outbound 443 and DNS. |
| A database error | The credentials in `configuration.php` do not match the database the script created. |
| A permission error | `configuration.php` is not writable by the web user at install time. |

Nothing is left half-installed: fix the cause and re-run. The script detects an
existing installation and refuses to touch it, so a re-run is safe.

```bash
./infrastructure/deploy-all.sh --skip-server
```

You can also finish it in a browser at
`https://your-domain/install/install.php` — the database credentials are in
`infrastructure/credentials-<domain>.txt`.

If `bin/installer.php` does not exist, the release predates the command-line
installer; use the web installer.

---

## `WTP_MASTER_KEY is not set`

The environment file is missing, unreadable by the web user, or has no key.

```bash
sudo ls -l /etc/whmcs-wtp/wtp.env        # want: -rw-r----- root www-data
sudo grep -c MASTER_KEY /etc/whmcs-wtp/wtp.env
sudo -u www-data cat /etc/whmcs-wtp/wtp.env >/dev/null && echo readable
```

If it is not at the default path, PHP has to be told:

```ini
; php-fpm pool
env[WTP_ENV_FILE] = /srv/secrets/wtp.env
```

and in the cron entry. Restart php-fpm after changing a pool file.

Never generate a new key to make the error go away. Every stored credential is
encrypted under the old one.

---

## `account.create` fails

| Message | Cause |
|---|---|
| `cpanel_auth_failed` | Wrong WHM credentials, or a token without the needed ACLs |
| `whm_permission_denied` | The token lacks `create-acct` |
| `package_not_found` | The cPanel package in the product's module settings does not exist in WHM |
| `account_exists_conflict` | Adopted, not an error — the job continues |
| `network_timeout` / `connection_refused` | Port 2087 blocked, or WHM is down |

Press **Test Connection** on the server record; it distinguishes these.

Check the package name exactly as WHM spells it — packages are usually prefixed
with the reseller name (`reseller_starter`, not `starter`).

---

## `wordpress.download` or `wordpress.install` fails

The installer answers over HTTP, so its failures are legible.

| Message | Cause |
|---|---|
| `The installer returned an unreadable response` | Something else is answering that URL — usually a parking page, or a `.htaccess` that intercepts everything |
| `bootstrap_expired` | The installer expired before finishing. Retry from **Deploy installer**. |
| `zip_unavailable` | The hosting account's PHP has no `zip` extension |
| `wordpress_exists` | A WordPress this installer did not create is already there. Deliberate — it will not clobber a customer's site. |
| `checksum_mismatch` | The core archive did not match. With `wp_core_source = wordpress.org`, usually a proxy rewriting the download; switch to `mirror`. |
| `docroot_missing` | cPanel reported a document root that does not exist; check the account |

Reach the installer by hand to see its state:

```bash
# The filename is in the job payload; the run token is encrypted.
mysql -e "SELECT bootstrap_file FROM mod_wtp_jobs WHERE uuid='<uuid>'"
curl -sk "https://example.com/wtp-bootstrap-<hex>.php?action=status&token=<token>"
```

---

## The site never checks in

**Symptom.** `agent.install` completed, but `agent.handshake` stays pending and
the job eventually fails with `agent_not_ready`.

The agent runs when it is poked. If neither WHMCS nor a visitor can reach it,
nothing happens.

1. **Is the agent there?**
   ```bash
   ls -l /home/<user>/public_html/wp-content/mu-plugins/
   ```
   Expect `whmcs-theme-provisioner.php` and the directory beside it. WordPress
   only auto-loads `*.php` at the top level, which is what the loader file is
   for — if it is missing, the agent never runs.

2. **Can WHMCS reach the site?**
   ```bash
   curl -sk -X POST https://example.com/wp-admin/admin-ajax.php -d 'action=wtp_tick'
   ```
   `{"success":false,"error":"unauthorized"}` is the **right** answer — the
   endpoint is alive and rejecting an unsigned poke. A 404 means the agent is
   not loaded; a timeout means a firewall.

3. **Can the site reach WHMCS?**
   ```bash
   curl -s https://billing.example.com/provisioning/v1/health
   ```
   Run this *from the hosting server*. Outbound HTTPS from hosting accounts is
   the single most common blocker.

4. **Are the credentials there?**
   ```bash
   ls -l /home/<user>/public_html/wp-content/uploads/.wtp/credentials.php
   ```
   Expect `0600`. Missing means `agent.install` did not finish; retry from
   **Deploy installer**.

---

## `401 unauthorized` on every agent call

| Cause | Check |
|---|---|
| Clock skew | `timedatectl` on both machines. Beyond ±300 s every signature fails. |
| The secret was rotated | Rotating requires re-bootstrapping. Retry from **Deploy installer**. |
| The job is finished | A completed job answers `410`, not `401` |
| A proxy rewriting the path | The path is signed. Anything rewriting it invalidates the signature. |

The job's event log records the precise reason (`timestamp_out_of_window`,
`signature_mismatch`, …) even though the response deliberately does not.

---

## `replay_detected`

The same nonce was used twice. Normally an agent retrying a request it already
sent — harmless, and the retry logic recovers. If it is constant, something is
duplicating requests: a proxy with retry-on-timeout, or two agents sharing one
credential (a cloned site).

---

## Downloads fail

| Message | Cause |
|---|---|
| `403` on `/download` | Invalid or expired signature; the agent will request a new grant |
| `410 grant_expired` | The download took longer than 10 minutes. Raise `WTP_DOWNLOAD_TTL`. |
| `410 grant_exhausted` | More than 3 attempts. Usually a flaky connection; raise `WTP_DOWNLOAD_MAX_USES`. |
| `503 asset_unavailable` | The file is missing from storage, **or its bytes no longer match the catalogue** |
| `404` on a grant request | The handle is not in this job's plan |

For `asset_unavailable`, compare:

```bash
sha256sum /var/lib/whmcs-wtp/packages/themes/astra-4.6.1.zip
mysql -e "SELECT checksum_sha256 FROM mod_wtp_theme_versions WHERE id=44"
```

A mismatch means the file was replaced without updating the catalogue. Update
the checksum in the admin area, or restore the original file.

---

## The import "succeeds" but the site looks wrong

| Symptom | Cause | Fix |
|---|---|---|
| Images broken, links point at the demo site | `source_site_url` not set | Set it in the demo's import plan overrides |
| No navigation | The demo's menu was not attached | Set `site.menus`; the finaliser also auto-attaches a lone menu |
| Front page shows blog posts | `front_page` not set, or the page title does not match | Set `site.front_page` to the exact page title |
| No images at all | `fetch_attachments` is off | Turn it on. Slower, much better-looking. |
| Colours are the theme defaults | Elementor's CSS cache | The Elementor importer flushes it; check the plugin actually installed |
| "Hello world!" still there | `delete_default_content` set to false | Default is true |

---

## An import is very slow

Large WXR files import in chunks, one tick at a time.

```sql
SELECT step_key, status, progress, attempt, cursor
FROM mod_wtp_job_steps
WHERE job_id = (SELECT id FROM mod_wtp_jobs WHERE uuid = '<uuid>');
```

If `progress` is climbing, it is working. Raise the throughput per tick:

```json
{ "step_options": { "content": { "chunk_size": 100 } } }
```

and, if the host allows it, `policy.step_time_budget` (max 120 s). Both trade
against the host's `max_execution_time` — a step that dies mid-tick costs more
than it saves.

Attachment downloading is usually the bottleneck. A demo with 300 images will
take minutes no matter what.

---

## Duplicate provisioning

It should not be possible; four independent layers prevent it. If you see it:

```sql
SELECT id, uuid, status, created_at FROM mod_wtp_jobs
WHERE service_id = 555 ORDER BY id;
```

More than one non-terminal row for a service means `openFor()`'s locked read
did not hold — check that the table is InnoDB and the connection is not in
autocommit-only mode.

---

## Reading the logs

**Per job** — the addon's job screen, or:

```sql
SELECT created_at, source, level, step_key, code, message
FROM mod_wtp_job_events
WHERE job_id = (SELECT id FROM mod_wtp_jobs WHERE uuid = '<uuid>')
ORDER BY id;
```

Everything is redacted before storage, so this output is safe to paste into a
ticket.

**Module calls** — WHMCS's own **Utilities → Logs → Module Log**.

**PHP errors** — the web server error log. A `500` from the API carries a
`reference`; grep for it:

```bash
sudo grep 'WTP\]\[a1b2c3d4' /var/log/nginx/whmcs.error.log
```

**Verbose provisioning** — set `WTP_LOG_LEVEL=debug` while diagnosing, and put
it back afterwards. Debug logging is per-event, not per-request, and a large
import produces a lot of it.

---

## Getting back to a clean state

**Retry from a step** — the normal path. Nothing earlier is undone.

**Start over completely** — when the account itself is wrong:

1. Cancel the job (**Jobs → the job → Cancel**).
2. Terminate the service in WHMCS, which removes the cPanel account.
3. Recreate it (**Module Commands → Create**).

**Repair a site by hand** — the customer's site is theirs; nothing here
prevents you from fixing it directly. The agent is dormant once its job is
terminal, and holds no credentials.

---

## Escalating

Include:

* the job UUID (not the customer's domain)
* the step that failed and its error code
* the event log for that job (already redacted)
* `worker.php --selftest` output
* the `reference` from any `500`

That set identifies the problem without carrying any customer credential.
