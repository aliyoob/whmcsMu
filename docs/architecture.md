# Architecture — WHMCS WordPress Theme/Demo Provisioner (WTP)

Version: 1.0.0 · Status: authoritative design document.
Every significant architectural decision is recorded here as an ADR (see §14).

---

## 1. Problem statement

When a customer buys a hosting plan in WHMCS they must additionally choose a **WordPress
theme** and one of that theme's **demos**. After payment and provisioning, the following
must happen with **no manual operator involvement**:

```
cPanel account -> WordPress install -> MU plugin -> WHMCS API -> theme/demo plan -> import -> verified
```

The system must be idempotent, retryable, observable, secure, and extensible.

---

## 2. High-level component map

```
┌───────────────────────────────────────────────────────────────────────────────┐
│ WHMCS SERVER                                                                  │
│                                                                               │
│  templates/  ──hooks──►  Addon module  ◄──►  MySQL (mod_wtp_* tables)         │
│  (order form)            wpthemeprovisioner        catalog / jobs / audit     │
│        ▲                    │      ▲                                          │
│        │ AJAX               │      │ localAPI                                 │
│        │                    │      │                                          │
│  Client selection UI        │   Server module  ──WHM API 1 / cPanel API──►    │
│  (theme + demo picker)      │   wpthemehost                                   │
│                             │                                                 │
│                             ├── cron/worker.php   (queue, retries, timeouts)  │
│                             └── admin UI (catalog, jobs, retry, logs)         │
│                                                                               │
│  provisioning/v1/index.php  ◄──── HTTPS + HMAC ──────────────┐                │
│  (public agent API, versioned, no session)                   │                │
└──────────────────────────────────────────────────────────────┼────────────────┘
                     │ WHM API 1 (TLS, API token)              │
                     ▼                                         │
┌──────────────────────────────────────────────────────────────┼────────────────┐
│ cPanel SERVER                                                │                │
│  createacct / suspend / unsuspend / removeacct / changepackage                │
│  Mysql::create_database | create_user | set_privileges_on_database            │
│  Fileman::upload_files (UAPI)  +  API2 Fileman::fileop op=extract             │
│                                                              │                │
│   /home/<user>/public_html/                                  │                │
│     wtp-bootstrap-<rand>.php   (single-purpose dropper, self-deleting)        │
│     wp-config.php, wp-admin/, wp-includes/                   │                │
│     wp-content/mu-plugins/whmcs-theme-provisioner.php  (loader)               │
│     wp-content/mu-plugins/whmcs-theme-provisioner/      (agent) ──────────────┘
│     wp-content/uploads/.wtp/credentials.php  (0600, deny-all)                 │
└───────────────────────────────────────────────────────────────────────────────┘
```

Two independent control loops drive the job forward. Neither depends on a long-lived
HTTP request:

* **Loop A — WHMCS cron worker** (`cron/worker.php`, every minute). Owns steps that
  require server-side credentials: cPanel account, database, dropper upload, dropper
  step-driving, timeout detection, retries, verification.
* **Loop B — WordPress agent** (MU plugin, self-dispatching loopback + WP-Cron fallback).
  Owns steps that must run inside WordPress: handshake, plan fetch, downloads, theme
  and plugin installation, content/widget/customizer/Elementor import, finalisation.

Every step in both loops is bounded (target < 20 s) and resumable.

---

## 3. Data ownership boundaries

| Concern                            | Owner                     | Storage |
|------------------------------------|---------------------------|---------|
| Theme / demo catalogue             | Addon module              | `mod_wtp_themes`, `mod_wtp_theme_versions`, `mod_wtp_demos`, `mod_wtp_demo_assets` |
| Which products offer selection     | Addon module              | `mod_wtp_product_settings` |
| Customer's choice for a service    | Addon module (+ optional mirror into WHMCS custom fields) | `mod_wtp_service_selection` |
| Provisioning job & step state      | Addon module              | `mod_wtp_jobs`, `mod_wtp_job_steps`, `mod_wtp_job_events` |
| Replay / idempotency / rate limits | Addon module              | `mod_wtp_api_nonces`, `mod_wtp_idempotency`, `mod_wtp_rate_limits` |
| Download authorisation             | Addon module              | `mod_wtp_download_grants` |
| Hosting account, package, billing  | **WHMCS core** (untouched)| `tblhosting`, `tblorders`, … (read-only for us) |
| Site content                       | WordPress                 | site DB |

**Rule:** WordPress never touches the WHMCS database. The only channel is the versioned
HTTPS API (§7). The MU plugin knows nothing about WHMCS table names, product ids or
internal semantics — it consumes a self-describing *provisioning plan* document.

---

## 4. Selection capture (order form → service)

WHMCS's cart is session-driven and its order forms are template files we must not fork.
The chosen mechanism, in order of reliability:

1. **Render.** `ClientAreaHeadOutput` / `ClientAreaFooterOutput` hooks inject a CSS/JS
   bundle only on `cart` pages for products that have `mod_wtp_product_settings.enabled`.
   The JS mounts a two-stage picker (theme grid → demo grid) into the product
   configuration form, using a list of fallback anchors so it works with the stock
   `standard_cart` and `universal_slider` order forms. A documented
   `{include file="wtp/selector.tpl"}` snippet is provided for forked templates.
2. **Carry.** The picker writes two hidden inputs into `form#frmProduct`:
   `wtp_theme_id`, `wtp_demo_id`. No prices or names are trusted from the client.
3. **Validate + stage.** `ShoppingCartValidateProductUpdate` receives the raw REQUEST,
   re-resolves both ids against the catalogue (active theme, active demo, demo belongs
   to theme, theme allowed for this product), and stages the tuple in
   `$_SESSION['wtp']['staged']` keyed by cart index **and** by `(pid, domain)`.
4. **Gate.** `ShoppingCartValidateCheckout` refuses checkout when a product with
   `selection_required = 1` has no valid staged selection.
5. **Bind.** `AfterShoppingCartCheckout` receives `OrderID` and `ServiceIDs` (in cart
   order). Each service is bound to its staged tuple by index, then **cross-checked**
   against the service's `packageid` and `domain`; on mismatch we fall back to matching
   by `(pid, domain)`, and if that is still ambiguous we fail loudly into
   `mod_wtp_job_events` rather than guessing. The result is a row in
   `mod_wtp_service_selection`.
6. **Defaults.** Admin-created orders and API orders have no session. `AfterModuleCreate`
   / the provisioning module's `CreateAccount` fall back to the product's
   `default_theme_id` / `default_demo_id`, recorded with `source = 'default'`.

The customer never supplies anything but two integers, and both are re-validated
server-side twice (cart and provisioning).

---

## 5. Provisioning module (`modules/servers/wpthemehost`)

Implements the standard WHMCS lifecycle:

| Function | Behaviour |
|---|---|
| `wpthemehost_MetaData` | Display name, api version, requires server |
| `wpthemehost_ConfigOptions` | cPanel package name, WP version policy, admin email policy, auto-SSL, plan template |
| `wpthemehost_CreateAccount` | Creates the cPanel account **and** enqueues a provisioning job; returns immediately |
| `wpthemehost_SuspendAccount` / `UnsuspendAccount` / `TerminateAccount` | WHM `suspendacct` / `unsuspendacct` / `removeacct`; terminate also cancels any live job and revokes its secret |
| `wpthemehost_ChangePackage` | WHM `changepackage` |
| `wpthemehost_ChangePassword` | WHM `passwd` |
| `wpthemehost_TestConnection` | WHM `version` + permission probe |
| `wpthemehost_AdminServicesTabFields` | Live provisioning status panel + retry/resume buttons |
| `wpthemehost_ClientArea` | Customer-facing progress panel |
| `wpthemehost_AdminCustomButtonArray` | Retry import, re-run verification, rotate agent secret, cancel job |

`CreateAccount` is deliberately **thin**: create the account, persist the job, return
`success`. All long work happens in the cron worker. WHMCS's module call timeout can
therefore never leave a half-provisioned site.

---

## 6. Job model

### 6.1 Identity

Every provisioning attempt gets `mod_wtp_jobs.uuid` (128-bit, URL-safe). This is the
`provisioning_job_id` referenced everywhere: logs, API calls, download grants, and the
MU plugin's local state. A service has at most one **active** job (partial unique index
emulated by a guard in `JobRepository::openFor()`), plus a history of terminal jobs.

### 6.2 Step sequence

| # | step key | loop | description |
|---|---|---|---|
| 1 | `account.create`      | A | WHM `createacct` |
| 2 | `account.verify`      | A | account exists, docroot known, PHP version acceptable |
| 3 | `database.provision`  | A | DB + DB user + grants via cPanel API |
| 4 | `bootstrap.deploy`    | A | upload dropper + its config |
| 5 | `wordpress.download`  | A→dropper | fetch + checksum WP core |
| 6 | `wordpress.install`   | A→dropper | extract, wp-config, `wp_install()` |
| 7 | `agent.install`       | A→dropper | download + install MU plugin, write credentials, self-destruct |
| 8 | `agent.handshake`     | B | exchange bootstrap token for job secret |
| 9 | `plan.fetch`          | B | pull provisioning plan |
|10 | `theme.install`       | B | download + safe-extract + activate theme |
|11 | `plugins.install`     | B | required plugins (wp.org + signed packages) |
|12 | `content.import`      | B | WXR, chunked and resumable |
|13 | `widgets.import`      | B | widget JSON |
|14 | `customizer.import`   | B | `.dat` customizer export |
|15 | `extras.import`       | B | Elementor kit / demo-specific importers |
|16 | `site.finalize`       | B | menus, homepage, permalinks, search-engine visibility, cleanup |
|17 | `job.verify`          | A | WHMCS fetches the site and asserts success signals |

### 6.3 State machine

```
                 ┌──────────────────────────── retry (backoff, attempt < max) ─┐
                 ▼                                                             │
pending ──► running ──► awaiting_agent ──► importing ──► verifying ──► completed
   │           │              │                │             │
   └───────────┴──────────────┴────────────────┴─────────────┴──► failed ──► cancelled
```

* `status` is the job-level state; `phase` is the current step key.
* Steps carry their own `status` ∈ `pending | running | completed | failed | skipped`
  plus `attempt`, `started_at`, `completed_at`, `error_code`, `error_message`.
* Transitions are validated by `Wtp\Shared\Job\JobStateMachine` — an illegal transition
  throws rather than silently corrupting state. This class is pure and unit-tested.
* Human-facing labels (`Hosting created`, `WordPress installed`, `Importing demo`, …)
  are derived from `(status, phase)` by `StatusPresenter`, so the vocabulary can change
  without touching stored data.

### 6.4 Idempotency

Four independent layers:

1. **Job creation** — `JobRepository::openFor(serviceId)` is a `SELECT … FOR UPDATE`
   inside a transaction; a second `CreateAccount` for the same service reuses the open
   job instead of creating a second one.
2. **Step transitions** — `UNIQUE(job_id, step_key)`; `completed` is terminal, a repeat
   report is accepted and returns the stored result (HTTP 200, `"repeated": true`).
3. **Request level** — mutating API calls accept `Idempotency-Key`. The first response
   is stored in `mod_wtp_idempotency` and replayed byte-for-byte for retries.
4. **Effect level** — every importer records a marker (`wtp_imported_<demo>_<step>`) in
   WordPress options; re-running an already-completed import is a no-op even if all of
   the above are bypassed (e.g. the site is restored from a backup).

### 6.5 Retry & recovery

* Per-step `max_attempts` (default 5) with exponential backoff + jitter:
  `delay = min(600, 15 * 2^(attempt-1)) ± 20 %`.
* Errors are classified by `ErrorClassifier` into `transient` (retry),
  `permanent` (fail immediately), and `manual` (park in `needs_attention`).
* The worker detects **stalled** steps: `running` with `heartbeat_at` older than the
  step's `timeout_seconds` → reset to `pending`, `attempt++`.
* Terminal failure keeps the account and the WordPress install intact and surfaces a
  **Retry** button in both the admin services tab and the addon's Jobs screen. Retry
  resets only the failed step and everything after it.

---

## 7. Agent API (`/provisioning/v1`)

Deployed as a small router at the WHMCS document root (`provisioning/v1/index.php`)
which boots WHMCS via `init.php` and delegates to `Wtp\Addon\Api\Router`. It never
starts a client session, never reads cookies and never emits HTML.

### 7.1 Authentication

Two schemes, both over TLS only (`servedOverSsl` is enforced; plain HTTP is rejected
with `426`).

**A. Bootstrap (one-shot, 30 min TTL)** — used only by `POST /handshake`.

```
Authorization: WTP-Bootstrap <job-uuid>.<token>
```
`token` is 32 random bytes, base64url. Only `hash('sha256', token)` is stored. Verified
with `hash_equals`, marked used inside a transaction, and unusable afterwards.

**B. Agent HMAC (per-job secret)** — every other authenticated call.

```
X-WTP-Job:       <job-uuid>
X-WTP-Timestamp: <unix seconds>
X-WTP-Nonce:     <32 hex chars>
X-WTP-Signature: v1=<hex hmac-sha256>
```

Canonical string:

```
WTP1\n{METHOD}\n{PATH}\n{TIMESTAMP}\n{NONCE}\n{sha256_hex(raw body)}
```

* Secret: 32 random bytes generated at handshake, returned **once**, stored in WHMCS
  encrypted with AES-256-GCM under a master key held outside the web root.
* Clock skew window: ±300 s. Nonce stored in `mod_wtp_api_nonces` with
  `UNIQUE(job_id, nonce)` and TTL 900 s → replay is impossible inside the window and
  the timestamp check closes it outside.
* The secret is **automatically revoked** when the job reaches a terminal state, so a
  compromised site cannot query WHMCS after provisioning finishes.
* Failed authentications are rate-limited per job and per IP (token bucket in
  `mod_wtp_rate_limits`); 10 failures/5 min → 429 with `Retry-After`.

### 7.2 Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET`  | `/v1/health`               | none      | liveness, API version |
| `POST` | `/v1/handshake`            | bootstrap | exchange token → job secret |
| `GET`  | `/v1/job`                  | hmac      | the provisioning plan |
| `POST` | `/v1/steps/{key}`          | hmac      | report step start/progress/finish |
| `POST` | `/v1/events`               | hmac      | batched structured log lines |
| `POST` | `/v1/assets/{id}/grant`    | hmac      | mint a signed, single-use download URL |
| `GET`  | `/v1/download`             | url sig   | stream a package |
| `POST` | `/v1/complete`             | hmac      | finish job, revoke secret |
| `POST` | `/v1/fail`                 | hmac      | terminal failure with diagnostics |

Full request/response schemas: [`docs/api.md`](api.md).

### 7.3 Versioning

The path segment `v1` is the contract version. The MU plugin sends
`X-WTP-Agent: whmcs-theme-provisioner/<semver>` and the server sends back
`X-WTP-Api: 1.<minor>`. Additive changes bump the minor; breaking changes create
`/v2` while `/v1` keeps serving old agents. `Router::VERSION_MAP` pins each version to
its own controller set so v1 behaviour is frozen by tests.

---

## 8. Package delivery

Import artefacts (theme zips, WXR, widget JSON, customizer `.dat`, Elementor kits) are
stored **outside the web root** in `storage_path` (default
`/var/lib/whmcs-wtp/packages/<sha256[0:2]>/<sha256>`), content-addressed by SHA-256.

```
MU plugin ──POST /v1/assets/{id}/grant──► WHMCS
                                            │ create mod_wtp_download_grants row
                                            │ (job-bound, max_uses=3, ttl=600s)
                                            ▼
          ◄── { url, expires_at, sha256, size_bytes, filename } ──
MU plugin ──GET /v1/download?job&grant&exp&sig──► WHMCS streams the file
          verify size cap → verify sha256 → safe-extract → import
```

The URL signature is `HMAC(job_secret, "WTPDL1\n{job}\n{grant}\n{exp}")`, so possession
of the link alone is useless once it expires or its uses are consumed, and a link
minted for job A cannot be replayed against job B.

**Rejected alternative:** pushing files from WHMCS into WordPress. That would require
WHMCS to hold credentials for every site and to keep an outbound connection open for
the duration of a 200 MB transfer. Pull + signed URL keeps WHMCS stateless per transfer
and lets the agent retry a failed download without WHMCS involvement.

---

## 9. WordPress installation

No dependency on Softaculous or WP Toolkit (neither is guaranteed to exist and both add
a licensing/API surface we do not control). Instead a **single-purpose dropper**:

1. WHMCS uploads `wtp-bootstrap-<16 random bytes>.php` plus a 0600 JSON config to the
   account's document root through the cPanel Fileman API.
2. The WHMCS worker drives it one bounded step per request, authenticating with a run
   token and pinning the connection to the server IP with `CURLOPT_RESOLVE` (DNS may
   not have propagated yet).
3. Steps: download core (checksum-verified) → safe extract → generate `wp-config.php`
   with locally generated 64-byte salts and a hardened footer (`DISALLOW_FILE_EDIT`,
   `AUTOMATIC_UPDATER_DISABLED=false`, `WP_AUTO_UPDATE_CORE=minor`) → `wp_install()` →
   fetch and install the MU plugin over a signed URL → write
   `wp-content/uploads/.wtp/credentials.php` (0600 + `deny from all` + `index.php`) →
   `unlink()` itself, its config and its state file.
4. The dropper refuses to run if `wp-config.php` already exists with a different site,
   caps itself at 40 invocations, and hard-expires 2 hours after creation.

File permissions applied: dirs `0755`, files `0644`, `wp-config.php` `0640`,
credentials `0600`, owner = the cPanel account (created by that account's own PHP
process, so ownership is correct by construction).

---

## 10. MU plugin (the agent)

`wp-content/mu-plugins/` only auto-loads `*.php` files at its top level, so we ship a
2 KB loader (`whmcs-theme-provisioner.php`) that requires the package directory. It
cannot be deactivated from wp-admin, which is exactly what we want.

```
Runner (one bounded step per tick)
  ├── Lock          MySQL GET_LOCK + option guard, prevents concurrent ticks
  ├── State         wp_options-backed, versioned, single autoloaded row
  ├── Scheduler     non-blocking loopback POST + WP-Cron 1-minute fallback
  ├── ApiClient     HMAC signer, retry w/ backoff, jittered, hard timeout
  └── ImporterRegistry
        ├── ThemeInstaller       (signed zip → safe extract → activate)
        ├── PluginInstaller      (wp.org slug or signed zip)
        ├── ContentImporter      (WXR, chunked, resumable cursor)
        ├── WidgetImporter       (JSON)
        ├── CustomizerImporter   (.dat)
        ├── ElementorImporter    (kit + URL rewrite)
        └── Finalizer            (menus, front page, permalinks, cleanup)
```

Importers implement `Wtp_Importer_Interface`:

```php
public function key(): string;
public function supports(array $plan): bool;
public function run(Wtp_Import_Context $ctx, array $state): Wtp_Import_Result;
```

`Wtp_Import_Result` carries `done|continue|fail` plus an opaque cursor, so a large WXR
is imported across many ticks without ever exceeding `max_execution_time`. Registering a
new importer is one `add_filter('wtp_importers', …)` call — no core edits.

---

## 11. Security model (summary; full detail in [`docs/security.md`](security.md))

| Threat | Control |
|---|---|
| Eavesdropping | TLS enforced end to end; plain HTTP rejected at the router |
| Forged agent requests | HMAC-SHA256 over method, path, timestamp, nonce and body hash |
| Replay | nonce table + ±300 s timestamp window + single-use bootstrap token |
| Credential lifetime | per-job secret, auto-revoked at terminal state, rotatable from admin |
| Secret at rest | AES-256-GCM, master key in `/etc/whmcs-wtp/wtp.env` (0600, outside web root), never in git |
| Brute force / abuse | token-bucket rate limits per job, per IP, per endpoint |
| SQL injection | 100 % parameter-bound queries via Capsule / PDO; no string-built SQL |
| SSRF | `UrlGuard` allow-lists schemes and hosts and blocks RFC1918/link-local/loopback after DNS resolution, with redirect re-validation |
| ZIP slip / path traversal | `SafeZip` rejects absolute paths, `..`, symlinks, non-allow-listed extensions, over-size and over-count archives, and validates the extracted realpath prefix |
| Arbitrary file write | every write is confined to a resolved base directory by `PathGuard` |
| RCE via import | no `eval`, no `unserialize` of remote data, no PHP files extracted from content packages |
| CSRF | admin actions require WHMCS's admin token; client AJAX requires the WHMCS client token |
| Log leakage | `Redactor` masks tokens/passwords/secrets by key name and by high-entropy pattern before anything is persisted |
| Supply chain | every artefact is SHA-256 pinned in the catalogue and verified after download |

---

## 12. Observability

* `mod_wtp_job_events` is an append-only, structured, redacted event log
  (`level`, `code`, `message`, `context` JSON) queryable per job and per step.
* Every event also goes to WHMCS's own module log via `logModuleCall()` for the
  provisioning module's actions, so operators see it where they expect it.
* The addon's **Jobs** screen shows a per-job timeline with per-step timings, attempt
  counts and the last error, plus **Retry step**, **Retry from here**, **Cancel** and
  **Rotate secret**.
* A `GET /v1/health` endpoint plus a `worker --selftest` mode support external
  monitoring.

---

## 13. Extensibility

* **New theme / demo:** rows in the catalogue + artefacts on disk. No code.
* **New artefact kind:** add a role to `mod_wtp_demo_assets` and an importer class.
* **New importer:** implement the interface, register via filter.
* **New control panel:** `Wtp\Shared\Contracts\HostingPanel` is implemented by
  `CpanelPanel`; a Plesk or DirectAdmin driver is an additional class plus a config
  option. Nothing above the driver knows about cPanel.
* **New API version:** add `v2` controllers; `v1` stays frozen and regression-tested.

---

## 14. Architecture decision records

**ADR-001 — Own cPanel provisioning rather than chaining WHMCS's `cpanel` module.**
WHMCS cannot chain two server modules on one product. Re-implementing the five WHM
calls we need is ~200 lines and gives us the account-creation result (docroot, IP, user)
that the rest of the pipeline requires. *Consequence:* we own the correctness of
`createacct` parameters; covered by integration tests against a fake WHM.

**ADR-002 — Dropper + core cPanel APIs instead of Softaculous / WP Toolkit.**
Neither is universally installed and both change their API independently of us. The
dropper needs only `Fileman::upload_files`, API2 `Fileman::fileop op=extract` and
`Mysql::*`, which are present on every cPanel server. *Consequence:* we maintain a small
installer; mitigated by keeping it to one file with no dependencies and unit-testing its
pure parts.

**ADR-003 — Catalogue in addon-owned tables, not WHMCS configurable options.**
Configurable options are priced, produce a combinatorial explosion for N themes × M demos,
and cannot carry preview images or import plans. *Consequence:* the order form needs
custom UI (§4).

**ADR-004 — Selection stored in an addon table keyed by `service_id`.**
Survives package changes, is visible to admins, and does not depend on the customer's
session after checkout. Optionally mirrored into WHMCS custom fields for operators who
want it in the native service view. *Consequence:* the provisioning module depends on the
addon module being installed; enforced by a version check in `TestConnection`.

**ADR-005 — Pull-based artefact delivery with signed, single-use URLs.**
See §8. *Consequence:* WHMCS must be reachable from the hosting server; documented as a
requirement, and `job.verify` reports it clearly when it is not.

**ADR-006 — Two cooperating control loops, no long-lived HTTP requests.**
Satisfies the "long operations must not depend on an HTTP request" rule and makes every
failure resumable. *Consequence:* provisioning is eventually-consistent; the UI shows
live progress so this is not surprising to the customer.

**ADR-007 — Per-job HMAC secrets that auto-revoke.**
Domain names are guessable and a shared API key would make every site a skeleton key.
A per-job secret with automatic revocation bounds the blast radius of a compromised
customer site to that site's own already-known data.

**ADR-008 — `shared/` is the single source of truth for code used on both sides.**
Signing, safe-zip, redaction and the state machine must behave identically in WHMCS and
WordPress. They are written once (PHP 7.4-compatible, zero dependencies) and copied into
both distributions by `bin/build.php`, so drift is impossible.
