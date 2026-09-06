# The provisioning lifecycle

What happens between a customer paying and their site being ready, and what
happens when a step of it goes wrong.

---

## The two control loops

Nothing in this system depends on a long-lived HTTP request surviving. Work is
advanced by two independent loops, each doing one bounded step at a time.

**Loop A — the WHMCS worker.** A cron entry, once a minute. Owns everything
that needs server-side credentials: the cPanel account, the database, deploying
and driving the installer, detecting stalls, scheduling retries, and the final
verification.

**Loop B — the WordPress agent.** Owns everything that must happen inside
WordPress: the handshake, fetching the plan, downloading packages, and every
import.

They meet at the API. Loop A never runs an import; Loop B never holds a cPanel
credential.

---

## The steps

| # | Step | Loop | What it does | Timeout | Optional |
|---|---|---|---|---|---|
| 1 | `account.create` | A | WHM `createacct`, or adopts an existing account | 180 s | |
| 2 | `account.verify` | A | Confirms it exists, is not suspended, has room | 90 s | |
| 3 | `database.provision` | A | Database, user and grant | 120 s | |
| 4 | `bootstrap.deploy` | A | Uploads the installer and its configuration | 180 s | |
| 5 | `wordpress.download` | A → installer | Fetches and checksums WordPress core | 600 s | |
| 6 | `wordpress.install` | A → installer | Extracts, writes `wp-config.php`, runs `wp_install()` | 600 s | |
| 7 | `agent.install` | A → installer | Installs the agent, writes credentials, self-destructs | 300 s | |
| 8 | `agent.handshake` | B | Exchanges the bootstrap token for a signing secret | 600 s | |
| 9 | `plan.fetch` | B | Pulls the provisioning plan | 300 s | |
| 10 | `theme.install` | B | Downloads, verifies, extracts and activates the theme | 900 s | |
| 11 | `plugins.install` | B | Required plugins | 900 s | ✓ |
| 12 | `content.import` | B | WXR, chunked and resumable | 3600 s | ✓ |
| 13 | `widgets.import` | B | Widget JSON | 300 s | ✓ |
| 14 | `customizer.import` | B | `.dat` customizer export | 300 s | ✓ |
| 15 | `extras.import` | B | Elementor kit, declarative option writes | 900 s | ✓ |
| 16 | `site.finalize` | B | Front page, menus, permalinks, caches | 300 s | |
| 17 | `job.verify` | A | WHMCS fetches the site and checks it works | 180 s | |

**Optional** means: if it exhausts its attempts, it is skipped and the job
continues. A demo with a corrupt widget file still produces a working site with
a theme and content — which is the right outcome for a paying customer.

Adding a step means one row in `Wtp\Shared\Job\StepCatalog`; it then appears in
the admin timeline, the client progress panel and the retry machinery
automatically.

---

## Job states

```
                 ┌──────────────────────── retry (backoff) ────────────────┐
                 ▼                                                          │
pending ──► running ──► awaiting_agent ──► importing ──► verifying ──► completed
   │           │              │                │             │
   └───────────┴──────────────┴────────────────┴─────────────┴──► failed ──► pending
                                                              └──► needs_attention
```

* `pending` — queued, not started
* `running` — a WHMCS-owned step is executing
* `awaiting_agent` — waiting for the site to check in
* `importing` — the agent is working
* `verifying` — the agent says it is done; WHMCS is checking
* `completed` — verified (terminal)
* `failed` — a required step gave up; retryable
* `needs_attention` — a human is needed (quota, credentials, licensing)
* `cancelled` — terminal

`pending → awaiting_agent` is legal on purpose: the installer can finish and
the agent can hand shake inside one worker interval.

Transitions go through `JobStateMachine`, which throws on anything illegal
rather than silently corrupting state. A wrong transition would leave a
half-imported site with a green tick next to it.

---

## Idempotency

Four independent layers, because a duplicated WordPress installation is not a
recoverable error.

1. **Job creation.** `JobRepository::openFor()` does a locked read inside a
   transaction. A second `CreateAccount` for the same service reuses the open
   job. (A cPanel account that already exists is adopted, not re-created.)
2. **Step transitions.** `UNIQUE(job_id, step_key)`. A repeated `completed`
   returns the stored result with `"repeated": true`.
3. **Request level.** `Idempotency-Key` stores the first response and replays
   it byte-for-byte.
4. **Effect level.** Every importer writes a marker into `wp_options` keyed by
   theme, demo and step. Re-running an already-completed import is a no-op even
   if all of the above are bypassed — a site restored from a backup, for
   instance.

---

## Retries

Per-step attempt counters, exponential backoff with ±20 % jitter:

```
delay(attempt) = min(600, 15 × 2^(attempt-1)) ± 20 %
15s · 30s · 60s · 120s · 240s · 480s · 600s …
```

Jitter matters: without it, every job parked on a wordpress.org outage retries
in the same second when it recovers.

`ErrorClassifier` decides what a failure means:

| Class | Behaviour | Examples |
|---|---|---|
| `transient` | Retry with backoff | `network_timeout`, `http_503`, `cpanel_busy` |
| `permanent` | Fail immediately | `checksum_mismatch`, `theme_not_found`, `zip_slip` |
| `manual` | Park in `needs_attention` | `disk_quota_exceeded`, `cpanel_auth_failed`, `license_invalid` |

Unknown codes are treated as transient. The attempt cap, not the classifier, is
what eventually stops them — a new failure mode gets a few bounded attempts
rather than either an immediate abandon or an infinite loop.

---

## Stall recovery

A step marked `running` whose heartbeat is older than its timeout is stalled:
the process that owned it died. Every worker tick requeues such steps and
increments their attempt counter. This is safe precisely because every handler
and every importer is idempotent.

Agent-owned steps get an extra mechanism. Because a new site has no traffic and
therefore no WP-Cron, the worker **pokes** the agent — a signed request to
`admin-ajax.php` that runs one tick. This is how agent work starts at all, not
merely how it recovers. After a successful poke the worker re-reads the step
before judging staleness, so a poke that just advanced the step is never undone
by a timeout it has already answered.

---

## Recovering a stuck job

**Jobs → the job** in the addon shows a step-by-step timeline with attempt
counts, timings and the last error, plus the full event log.

| Action | Effect |
|---|---|
| **Retry this step** | Resets one step (attempts, cursor, error) and requeues |
| **Retry from here** | Resets that step and everything after it |
| **Retry from the first failure** | The common case, one click |
| **Rotate agent secret** | Invalidates the site's credential; requires re-bootstrapping |
| **Cancel job** | Terminal; revokes credentials and grants |

Earlier work is deliberately never undone. The cPanel account and the WordPress
installation are expensive and correct; a retry resumes, it does not rebuild.

The same actions are on the service page in WHMCS (**Retry provisioning**,
**Rotate agent secret**) for operators who live there rather than in the addon.

---

## Where selections come from

| Source | When | Recorded as |
|---|---|---|
| `client` | The customer chose on the order form | `client` |
| `default` | Admin or API order, product defaults used | `default` |
| `admin` | An operator set or changed it | `admin` |

The selection is validated three times: when the cart posts it, at checkout,
and again when the plan is built. Between the second and third, the theme could
have been withdrawn — which is exactly the case the third check catches.

Once provisioning completes, the selection is **locked** so a catalogue edit
cannot change what a running or finished import did.

---

## Timings

On a healthy cPanel server with a mirrored WordPress core:

| Phase | Typical |
|---|---|
| Account and database | 5–20 s |
| Installer deployment | 2–5 s |
| WordPress download and install | 20–60 s |
| Agent installation and handshake | 5–15 s |
| Theme and plugins | 15–60 s |
| Demo content | 30 s – 10 min, by size |
| Finalisation and verification | 5–15 s |

**Two to five minutes** end to end for a typical demo. The customer sees a live
progress panel on their service page throughout, so the wait is visible rather
than mysterious.
