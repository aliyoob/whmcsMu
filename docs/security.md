# Security model

This system takes a customer's payment, creates a server account, installs code
on it, and gives that code a credential pointing back at the billing system.
Every one of those is worth getting right.

---

## Trust boundaries

```
   TRUSTED                          SEMI-TRUSTED                 UNTRUSTED
   ───────                          ────────────                 ─────────
   WHMCS server                     cPanel/WHM                   The customer's
   · master key                     · we authenticate to it      WordPress site
   · package storage                · it is not our code         · runs our agent
   · job database                                                · customer has
   · the catalogue                  Demo packages                  full control
                                    · operator-supplied          · may be
   Operators                        · treated as hostile           compromised
   · authenticated admins             input anyway
```

The important line is the last one. **A provisioned site is untrusted.** The
customer has shell access to it. It may be compromised the day after it is
built. Every control below assumes an attacker holds a valid agent credential
for one site and asks: what can they reach?

The answer is: that site's own plan, and the packages in it. Nothing else.

---

## Controls

| Threat | Control | Where |
|---|---|---|
| Eavesdropping | TLS end to end; plain HTTP is `426` | `Authenticator::assertTransport` |
| Forged agent requests | HMAC-SHA256 over method, path, timestamp, nonce, body hash | `HmacSigner` |
| Replay | Nonce table with `UNIQUE(job_id, nonce)` + ±300 s window | `NonceStore` |
| Stolen credential, long tail | Per-job secret, auto-revoked at terminal state | `JobRepository::transition` |
| Bootstrap token theft | Single use, hash-only storage, expires with the installer | `consumeBootstrapToken` |
| Credential at rest | AES-256-GCM under an HKDF sub-key, master key outside the web root | `SecretBox` |
| Brute force | Token buckets per job and per IP; failures cost 6× | `RateLimiter` |
| SQL injection | 100 % parameter-bound; identifiers pattern-validated | `Db` |
| SSRF | Scheme allow-list, DNS resolution, RFC1918/link-local/CGNAT/ULA rejected, redirects re-validated | `UrlGuard` |
| ZIP slip | Absolute paths, `..`, symlinks, extension allow-lists, size and ratio caps, realpath prefix check | `SafeZip` |
| Arbitrary file write | Every write confined to a resolved base | `PathGuard` |
| Zip bombs | Entry count, total uncompressed size, per-entry ratio | `SafeZip::inspect` |
| Object injection | `unserialize(..., ['allowed_classes' => false])` everywhere | Customizer importer, URL rewriter |
| XXE | `LIBXML_NONET`, entity substitution **not** enabled | `ContentImporter::openReader` |
| Cross-tenant access | Assets authorised by reachability from *this job's* plan | `AssetController` |
| Link sharing | Signed URLs job-bound, use-counted, 10-minute TTL | `SignedUrl` + `GrantRepository` |
| Supply chain | Every artefact SHA-256 pinned in the catalogue, verified after download | `Downloader` |
| Log leakage | Redaction by key name and by shape, on the way in | `Redactor` |
| XSS in admin | Every output escaped at the point of output | `View::e` |
| XSS in the order form | Catalogue text never becomes markup; image URLs http(s)-only | `selector.js` |
| CSRF | WHMCS admin token on every state-changing POST | `AdminController::assertCsrf` |
| Privilege escalation via demo | Protected option list; a demo cannot write `siteurl`, roles or agent state | `OptionsImporter`, `CustomizerImporter` |

---

## Secrets

### What exists

| Secret | Lifetime | At rest | Who has it |
|---|---|---|---|
| `WTP_MASTER_KEY` | Permanent | `/etc/whmcs-wtp/wtp.env`, 0640, outside the web root | WHMCS only |
| Agent secret | 24 h, revoked at terminal state | AES-256-GCM in `mod_wtp_jobs` | WHMCS + one site |
| Bootstrap token | Installer lifetime, single use | `sha256` only | WHMCS + one site |
| Installer run token | Installer lifetime | AES-256-GCM in the job payload | WHMCS + one site |
| Download signing key | Derived, not stored | HKDF from master key + job uuid | WHMCS only |
| cPanel password | Per service | AES-256-GCM in the job payload | WHMCS + the panel |
| Site DB password | Per site | AES-256-GCM in the job payload | WHMCS + the site |
| WP admin password | Per site | AES-256-GCM in the job payload | WHMCS + the site |

### Rules

1. **Nothing secret is in this repository.** `.gitignore` covers `*.env`,
   `*.pem`, `*.key` and the answers file. There is no committed default key.
2. **Nothing secret is in the database in clear.** The job payload is a plain
   `LONGTEXT` that support staff and backups can read; every credential in it
   is an AES-256-GCM envelope.
3. **Nothing secret is in the addon configuration screen.** Operators cannot
   paste a key into a form that writes it to `tbladdonmodules`.
4. **Nothing secret reaches a log.** `Redactor` runs before storage, masking by
   key name (`password`, `token`, `secret`, `signature`, …) and by shape
   (`Authorization:` values, `sig=`/`token=` parameters, high-entropy blobs).
   SHA-256 digests are deliberately *not* masked: they are integrity values, not
   secrets, and hiding them would make debugging a checksum mismatch impossible.
   An end-to-end test asserts that no secret from a completed job appears
   anywhere in its event log.

### Rotating the master key

Rotating it makes every existing envelope unreadable. Jobs in flight cannot
continue and must be retried from `bootstrap.deploy`.

```bash
# 1. Wait until no job is active
mysql -e "SELECT id, uuid, status FROM mod_wtp_jobs
          WHERE status NOT IN ('completed','cancelled','failed')"

# 2. Rotate
sudo cp /etc/whmcs-wtp/wtp.env /root/wtp.env.$(date +%F)
sudo sed -i '/^WTP_MASTER_KEY=/d' /etc/whmcs-wtp/wtp.env
sudo php /path/to/repo/bin/wtp-keygen.php --write /etc/whmcs-wtp/wtp.env

# 3. Verify
php <whmcs>/modules/addons/wpthemeprovisioner/cron/worker.php --selftest
```

`wtp-keygen.php` refuses to overwrite an existing key, so step 2's `sed` is
deliberate and explicit rather than accidental.

### Rotating one agent secret

When a single site is suspected compromised: **Jobs → the job → Rotate agent
secret**, or the **Rotate agent secret** button on the service. The old secret
stops working immediately. The site must be re-bootstrapped to receive the new
one — which is the point.

---

## Archive handling

Both the WHMCS side and the agent extract archives supplied by an operator, and
a demo package is a plausible way for a bad actor to reach a customer's site.

`SafeZip` refuses, before writing anything:

* absolute paths, `..` in any segment, backslash traversal, null bytes
* UNIX symlink entries (`S_IFLNK` in the external attributes)
* files whose extension is not on the profile's allow-list
* double extensions like `shell.php.png`
* dotfiles other than a short known list
* more than 20 000 entries, more than 1 GiB uncompressed, more than 256 MiB in
  one entry, or a compression ratio above 200:1
* anything whose resolved path escapes the destination

Two profiles:

| Profile | Used for | `.php` |
|---|---|---|
| `CODE_EXTENSIONS` | Themes, plugins, the agent | allowed |
| `CONTENT_EXTENSIONS` | Demo content, widgets, customizer, kits | **rejected** |

A demo content package containing PHP is refused at upload time in the admin
area, and again on the site before extraction. Sixteen unit tests cover these
paths, each with a real hostile archive built at test time.

---

## The installer

The dropper is the most sensitive component: it runs before WordPress exists,
writes `wp-config.php`, and creates the administrator.

* Random filename (`wtp-bootstrap-<16 random bytes>.php`), so it is not
  discoverable.
* Its configuration — every credential — travels over the cPanel API (TLS to
  WHM), never over the public trigger channel.
* The trigger carries only a run token whose sole power is "advance this
  installation", which is what we want to happen anyway.
* HTTPS is preferred even when the certificate is not yet valid, so a passive
  observer does not see the run token.
* It refuses to touch a `wp-config.php` it did not write (`WTP_PROVISIONED`),
  so it cannot clobber an existing site.
* Hard limits: 40 invocations, and an absolute expiry.
* It deletes itself, its configuration and its state file when it finishes.
* Salts are generated locally with `random_int` — no outbound call to
  api.wordpress.org, which may be blocked and is a needless dependency at
  install time.
* `wp-config.php` is written `0640` with `DISALLOW_FILE_EDIT` and
  `WP_AUTO_UPDATE_CORE = minor`.

---

## The agent

* A must-use plugin: it cannot be deactivated from wp-admin, which is
  deliberate — provisioning must survive a curious customer.
* Credentials live in `wp-content/uploads/.wtp/credentials.php`, mode `0600`,
  beside a deny-all `.htaccess` and an `index.php`. Outside the plugin
  directory, so replacing the agent does not destroy them and a directory
  listing does not reveal them.
* The trigger endpoint (`admin-ajax.php?action=wtp_tick`) verifies the same
  HMAC scheme before doing anything. An unauthenticated poke would let anyone
  make a customer's site hammer the billing server.
* When the job finishes the agent deletes its own credentials and unschedules
  itself. WHMCS revokes its side at the same moment. After provisioning, the
  site holds nothing that can talk to WHMCS.

---

## What an attacker gets

**With a compromised provisioned site** (worst realistic case): the agent
secret for that job, until the job finishes. With it they can read that job's
plan — the theme and demo their own customer already chose — and download those
packages, which that customer already paid for. They cannot reach another job,
another customer, or the catalogue. They cannot write anything to WHMCS beyond
step statuses and log lines for their own job, and they cannot mark a step
failed that already succeeded.

**With the WHMCS database but not the master key**: no usable credential. Every
secret in it is an AES-256-GCM envelope keyed outside the database.

**With a stolen download link**: at most three fetches of one package within ten
minutes, and only while its job is live.

**With an intercepted request**: nothing. The nonce is already burned inside
the window, and outside it the timestamp check rejects it.

---

## Reporting

Security issues should go to your usual security contact, not a public tracker.
Please include the job UUID and the `reference` from any `500` response — both
are in the logs and neither identifies a customer.
