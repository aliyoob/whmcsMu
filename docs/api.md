# Provisioning API (v1)

The only channel between a provisioned WordPress site and WHMCS. WordPress
never touches the WHMCS database.

**Base URL** `https://<whmcs>/provisioning/v1`
**Version header** `X-WTP-Api: 1.0` on every response
**Content type** `application/json; charset=utf-8`

The path segment `v1` is the contract. Additive changes bump the minor version
in the header; a breaking change creates `/v2` while `/v1` keeps serving the
agents already in the field.

---

## Authentication

### Bootstrap (one shot)

Used by `POST /handshake` and nothing else.

```http
Authorization: WTP-Bootstrap <job-uuid>.<token>
```

The token is 32 random bytes, base64url. WHMCS stores only
`sha256(token)`, compares with `hash_equals`, and marks it used inside the same
transaction that validates it — so it works exactly once. It expires with the
installer that carried it.

### Agent HMAC

Every other authenticated call.

```http
X-WTP-Job:       11111111-2222-4333-8444-555555555555
X-WTP-Timestamp: 1735689600
X-WTP-Nonce:     3f7a91c2e5b84d06a1f3c9e7b2d4508a
X-WTP-Signature: v1=<hex hmac-sha256>
```

Canonical string — the exact bytes both sides sign:

```
WTP1\n{METHOD}\n{PATH}\n{TIMESTAMP}\n{NONCE}\n{sha256_hex(raw body)}
```

* `METHOD` uppercase.
* `PATH` the **full** request path (`/provisioning/v1/steps/theme.install`),
  query string excluded.
* Body hash is of the raw bytes; for a GET that is `sha256("")`.

```php
$canonical = "WTP1\nPOST\n/provisioning/v1/steps/theme.install\n"
    . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
$signature = 'v1=' . hash_hmac('sha256', $canonical, $secret);
```

Rules the server enforces:

| | |
|---|---|
| Clock skew | ±300 s (`WTP_CLOCK_SKEW`) |
| Nonce | 16–64 hex chars, unique per job for 900 s |
| Transport | HTTPS only; plain HTTP is `426` |
| Secret lifetime | 24 h by default, and **revoked the moment the job reaches a terminal state** |

Every authentication failure returns the same opaque `401 unauthorized`. The
real reason goes to the job's event log, where an operator can see it and an
attacker cannot.

### Download URLs

`GET /download` authenticates by URL signature instead, because the agent hands
the link to WordPress's own downloader, which will not sign anything.

```
sig = HMAC-SHA256(job_download_key, "WTPDL1\n{job}\n{grant}\n{exp}")
```

The signing key is derived from the master key and the job uuid, so it exists
before the agent has a secret and survives a secret rotation. Authority comes
from the grant row, not the link: job-bound, use-counted, short-lived.

---

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/health` | none | Liveness and contract version |
| `POST` | `/handshake` | bootstrap | Exchange the installer's token for a signing secret |
| `GET` | `/job` | hmac | The provisioning plan |
| `POST` | `/steps/{key}` | hmac | Report a step transition |
| `POST` | `/events` | hmac | Batched log lines |
| `POST` | `/assets/{handle}/grant` | hmac | Mint a signed download URL |
| `GET` | `/download` | url sig | Stream a package |
| `POST` | `/complete` | hmac | Report the import finished |
| `POST` | `/fail` | hmac | Report a terminal failure |

---

### `GET /health`

```json
{
  "success": true,
  "api": "1.0",
  "checks": { "database": "ok", "secrets": "ok", "transport": "https" }
}
```

`503` when the database is unreachable or the master key is missing. It reports
nothing an anonymous caller should not know — no counts, no versions, no
configuration.

---

### `POST /handshake`

```json
{ "site_url": "https://example.com", "wp_version": "6.5.2",
  "agent_version": "1.0.0", "php_version": "8.2.20" }
```

```json
{
  "success": true,
  "job": { "id": "1111…", "domain": "example.com", "status": "awaiting_agent" },
  "credentials": {
    "secret": "…",
    "algorithm": "hmac-sha256",
    "scheme": "WTP1",
    "expires_at": "2025-01-02T00:00:00+00:00"
  },
  "endpoints": { "base": "…", "job": "…", "steps": "…/steps/", "events": "…",
                 "grant": "…/assets/{asset_id}/grant", "complete": "…", "fail": "…" },
  "policy": { "clock_skew": 300, "poll_interval": 15 }
}
```

`credentials.secret` is returned **once**. Losing it means the site must be
re-bootstrapped by an operator, which is the intended failure mode.

`401` — bad, used or expired token. `410` — the job is already finished.

---

### `GET /job`

```json
{
  "success": true,
  "job": { "id": "1111…", "status": "awaiting_agent", "phase": "plan.fetch",
           "domain": "example.com", "site_url": "https://example.com" },
  "plan": { "…": "see below" },
  "plan_fingerprint": "9f2c…",
  "outstanding_steps": [
    { "key": "theme.install", "status": "pending", "attempt": 0,
      "max_attempts": 5, "progress": 0, "cursor": null }
  ]
}
```

Rebuilt from the live catalogue on every call, never cached — so a checksum an
operator corrects takes effect immediately, and a retrying agent never receives
a stale digest.

`422` with `theme_inactive`, `demo_not_found`, `plan_invalid` … when the
selection is no longer serviceable. The message is safe to show a customer; the
`error` code is for machines.

---

### `POST /steps/{key}`

`{key}` must be an agent-owned step (`403` otherwise) that exists (`404`).

```json
{ "status": "running" }
{ "status": "progress",  "progress": 45, "cursor": { "index": 320 } }
{ "status": "completed", "meta": { "posts": 128 } }
{ "status": "skipped",   "reason": "this demo ships no widgets" }
{ "status": "failed",    "error_code": "checksum_mismatch",
                         "error_message": "…" }
```

Send `Idempotency-Key: <8–128 chars>` on anything you might retry. The first
response is stored and replayed byte-for-byte; reusing a key with a *different*
body is `409 idempotency_key_reuse`.

A repeated `completed` on an already-completed step is `200` with
`"repeated": true`. A `failed` on one is `409 step_already_terminal` — a step
that succeeded cannot be retro-actively failed.

The response to `failed` tells the agent what WHMCS decided:

```json
{ "success": true,
  "step": { "key": "content.import", "status": "pending" },
  "retry": { "scheduled": true, "after_seconds": 30, "attempt": 2 } }
```

`retry.scheduled: false` with `step.status: "skipped"` means the step was
optional and has been given up on; the job continues. With
`step.status: "failed"` it means the job has stopped and needs an operator.

---

### `POST /events`

```json
{ "events": [
  { "level": "info", "message": "Installed theme", "step": "theme.install",
    "context": { "version": "4.6.1" } },
  { "level": "warning", "message": "…", "code": "plugin_activate_failed" }
] }
```

Up to 50 per call; the rest are dropped and counted. Everything is redacted and
size-capped before storage. `202 Accepted`.

---

### `POST /assets/{handle}/grant`

`{handle}` is an opaque plan reference: `tv:44` (theme version) or `da:91`
(demo asset).

```json
{
  "success": true,
  "grant": { "id": "512", "url": "https://…/download?job=…&grant=512&exp=…&sig=…",
             "expires_in": 600, "max_uses": 3 },
  "asset": { "handle": "tv:44", "filename": "astra-4.6.1.zip",
             "sha256": "…", "size_bytes": 2418176 }
}
```

Authorisation is by *reachability*: the handle must appear in the plan this job
would be served right now. An agent therefore cannot fetch a package belonging
to a theme its customer did not buy, even holding a valid signing secret. A
handle outside the plan is `404`, not `403` — an agent has no business learning
that the asset exists.

`503 asset_unavailable` means the file is missing from storage, or its bytes no
longer match the catalogue's checksum. Both are logged at `critical`.

---

### `GET /download`

`?job=&grant=&exp=&sig=` — see *Download URLs* above.

```
200 OK
Content-Type: application/octet-stream
Content-Length: 2418176
Content-Disposition: attachment; filename="astra-4.6.1.zip"
X-WTP-Sha256: 3b7c…
```

Verify `X-WTP-Sha256` against the plan's value before touching the bytes. The
agent does; so should anything else.

`403` invalid or expired signature · `410 grant_expired` / `grant_exhausted` /
`grant_revoked` · `429` too many downloads for this job.

---

### `POST /complete`

```json
{ "site_url": "https://example.com", "admin_url": "https://example.com/wp-admin/",
  "theme": "astra", "demo": "agency", "summary": { "steps": ["…"] } }
```

Does **not** finish the job. It moves it to `verifying`, and WHMCS then fetches
the site itself before telling the customer it is ready — an agent claiming
success is evidence, not proof. Optional steps the agent never reached are
marked skipped; a required gap keeps the job open.

---

### `POST /fail`

```json
{ "error_code": "theme_broken", "error_message": "…",
  "step": "theme.install", "diagnostics": { "php": "8.2.20", "wp": "6.5.2" } }
```

The job moves to `failed`, or to `needs_attention` when the code classifies as
requiring a human (quota, credentials, licensing). Either way it stays
retryable from the admin area.

---

## The provisioning plan

Self-describing, and the only thing the agent knows about WHMCS. It carries no
WHMCS identifiers the agent could act on, no secrets, and no server paths —
only asset handles to exchange for signed URLs, and declarative instructions.

```json
{
  "version": 1,
  "theme": {
    "id": 10, "slug": "astra", "name": "Astra", "version": "4.6.1",
    "package": { "kind": "asset", "asset_id": "tv:44", "sha256": "…",
                 "size_bytes": 2418176, "filename": "astra-4.6.1.zip" }
  },
  "demo": { "id": 25, "slug": "agency", "name": "Agency" },
  "plugins": [
    { "kind": "wporg", "slug": "elementor", "version": "", "activate": true }
  ],
  "steps": [
    { "key": "content.import", "importer": "content",
      "asset": { "kind": "asset", "asset_id": "da:88", "sha256": "…",
                 "filename": "content.xml" },
      "options": { "fetch_attachments": true, "chunk_size": 40 },
      "optional": false }
  ],
  "site": { "title": "Acme", "tagline": "", "language": "en_US",
            "timezone": "UTC", "permalink_structure": "/%postname%/",
            "front_page": "Home", "blog_page": "",
            "menus": { "primary": "Main Menu" },
            "discourage_search_engines": false },
  "options": { "replace_urls": true,
               "source_site_url": "https://demo.example.com",
               "fetch_attachments": false, "delete_default_content": true },
  "policy": { "max_download_bytes": 268435456, "step_time_budget": 20,
              "http_timeout": 30 }
}
```

### Package kinds

| `kind` | Fields | Notes |
|---|---|---|
| `asset` | `asset_id`, `sha256`, `size_bytes`, `filename` | Exchanged for a signed URL. `sha256` mandatory. |
| `url` | `url`, `sha256`, `size_bytes`, `filename` | Absolute **https** only. `sha256` mandatory. |
| `wporg` | `slug`, `version` | Resolved through WordPress's own repository API. |

### Importers

`theme` · `plugins` · `content` · `widgets` · `customizer` · `elementor` ·
`options` · `finalize`. Anything else is rejected by the validator on both
sides.

### Validation

`Wtp\Shared\Plan\ImportPlan` validates the document **on both sides** — WHMCS
before storing or serving it, the agent on receipt. Filenames are basenamed and
must not carry an executable extension; option maps must be flat scalars;
permalink structures and locales are pattern-matched. A compromised or simply
buggy WHMCS cannot make the agent write outside its own site.

---

## Errors

```json
{ "success": false, "error": "grant_expired",
  "message": "This download link has expired",
  "meta": { "retry_after": 42 } }
```

| Status | Meaning |
|---|---|
| `400` | Malformed request or JSON |
| `401` | Authentication failed, or a replayed nonce |
| `403` | Authenticated but not permitted |
| `404` | No such route, step, or reachable asset |
| `405` | Wrong method |
| `409` | Conflicting state (terminal step, idempotency key reuse) |
| `410` | Gone: job finished, grant expired, exhausted or revoked |
| `422` | The request was understood but cannot be served (bad plan, unknown status) |
| `426` | HTTPS required |
| `429` | Rate limited — honour `Retry-After` |
| `500` | Internal error; the response carries a `reference` matching the server log |
| `503` | A dependency is unavailable (storage, database) |

A `500` never leaks a stack trace or a path. The `reference` is a short hash of
the failure, and the full detail is in the web server's error log under the
same reference.

---

## Rate limits

Token buckets, per job and per address:

| Bucket | Default |
|---|---|
| API calls per job | 300 / 300 s |
| Authentication failures per IP | 10 / 300 s (each failure costs 6 tokens) |
| Downloads per job | 60 / 300 s |

Tunable with `WTP_RATE_LIMIT_API`, `WTP_RATE_LIMIT_WINDOW`,
`WTP_RATE_LIMIT_AUTH_FAILURES`.

---

## Writing another client

Everything needed is in `shared/Wtp/Shared/Http/` — `HmacSigner`,
`CanonicalRequest` and `SignedUrl` — and is dependency-free PHP 7.4. Port those
three and the protocol follows. `tests/fakes/FakeAgent.php` is a complete
working client, in about 300 lines, that the test suite runs against the real
router on every commit.
