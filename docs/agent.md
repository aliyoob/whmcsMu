# The WordPress agent

A must-use plugin installed into every provisioned site. It runs the import and
reports progress, then removes its own credentials and goes quiet.

```
wp-content/mu-plugins/
├── whmcs-theme-provisioner.php        loader (2 KB)
└── whmcs-theme-provisioner/
    ├── whmcs-theme-provisioner.php    entry point
    ├── includes/                      config · state · lock · logger · scheduler · runner · plugin
    ├── api/                           signed client
    ├── importer/                      registry, interface, and the importers
    ├── security/                      downloader, safe extraction
    └── shared/                        generated from shared/ — do not edit
```

The loader exists because WordPress only auto-loads `*.php` at the top level of
`mu-plugins`. Being must-use is deliberate: it cannot be deactivated from
wp-admin, so provisioning survives a curious customer.

---

## What it costs a finished site

One option read. `Wtp_Plugin::boot()` returns immediately when the site was
never provisioned by us or when the job is over. No hooks that matter are
registered, nothing is constructed, no HTTP call is made. An agent that kept
costing the customer performance after finishing would be a defect.

---

## How it gets to run

A brand-new site has no visitors, so WP-Cron never fires. Three triggers, in
order of importance:

1. **WHMCS pokes it.** A signed request to `admin-ajax.php?action=wtp_tick`.
   This is how agent work *starts*, not merely how it recovers.
2. **Self-dispatch.** After a tick with work left, the agent fires a
   non-blocking request to itself, so a long import proceeds at its own pace
   instead of one step per WHMCS poll.
3. **WP-Cron and page views.** A one-minute schedule, plus an at-most-once-a-
   minute nudge on `shutdown` from any page view — both backstops for when
   WHMCS cannot reach the site but the world can.

The trigger endpoint verifies the same HMAC the agent uses outbound. An
unauthenticated poke would let anyone make a customer's site hammer WHMCS.

---

## A tick

```
lock (MySQL GET_LOCK, or an option lease)
  ├─ handshake   exchange the bootstrap token for a signing secret
  ├─ plan        fetch and re-validate the plan
  ├─ run         one bounded slice of the next outstanding step
  └─ complete    report, forget the credentials, unschedule
unlock · flush the log buffer · dispatch self if work remains
```

Bounded by `policy.step_time_budget` (20 s default). An importer that runs out
of budget returns a cursor and is called again — a 5 000-post WXR never
collides with the host's `max_execution_time`.

Reported step keys are grouped so the customer sees a sensible checklist rather
than every plan step:

| Reported step | Importers |
|---|---|
| `theme.install` | `theme` |
| `plugins.install` | `plugins` |
| `content.import` | `content` |
| `widgets.import` | `widgets` |
| `customizer.import` | `customizer` |
| `extras.import` | `elementor`, `options` |
| `site.finalize` | `finalize` |

---

## State

One `wp_options` row, `wtp_agent_state`, not autoloaded:

```php
[
  'phase'     => 'run',                      // handshake|plan|run|done|failed
  'plan'      => [...],                      // the validated plan
  'completed' => ['theme.install', ...],
  'cursor_content.import' => ['phase' => 'items', 'index' => 320],
  'attempts_content.import' => 2,
]
```

One row means a tick reads and writes once, and `delete_option` is a complete
reset. Deleting it makes the agent start over from the handshake — which will
fail, because the bootstrap token is single-use. Retry from **Deploy
installer** instead.

Per-import idempotency markers live in separate `wtp_done_*` options keyed by
theme, demo and step. They are what makes a re-run a no-op even on a site
restored from a backup.

---

## Locking

MySQL `GET_LOCK` where available: genuinely atomic, and released when the
connection dies — which matters when PHP is killed mid-import. Otherwise an
option lease acquired through `add_option`'s uniqueness, with an expiry so a
dead holder cannot block forever.

---

## Failure handling

The agent does not decide its own retries. It reports a failure and does what
WHMCS's response tells it, because WHMCS knows the attempt budget and the error
classification. See [provisioning.md](provisioning.md#retries).

Every reported transition carries an idempotency key derived from the step and
its attempt, so a response lost to a timeout is replayed rather than
re-executed.

---

## Diagnosing an agent

```bash
# What does it think it is doing?
wp option get wtp_agent_state --format=json | jq

# Is it scheduled?
wp cron event list | grep wtp

# Force a tick (WP-CLI runs as the site user, so no signature is needed)
wp eval 'Wtp_Plugin::instance()->runner()->tick();'

# What has it done before?
wp option list --search='wtp_done_*'
```

With `WP_DEBUG` on, every log line also goes to `wp-content/debug.log`,
redacted exactly as the copies sent to WHMCS are.

---

## Removing it

Once a job is terminal the agent is inert and holds no credentials, so there is
usually no reason to remove it. If you want to anyway:

```bash
rm -rf wp-content/mu-plugins/whmcs-theme-provisioner \
       wp-content/mu-plugins/whmcs-theme-provisioner.php \
       wp-content/uploads/.wtp
wp option delete wtp_agent_state
wp option delete wtp_agent_secret
```

The imported content stays. It is the customer's site.
