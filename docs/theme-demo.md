# Adding themes and demos

Everything here is data. Adding a theme or a demo needs no code and no deploy.

---

## The model

```
Theme  ──┬── Version  (the package customers actually receive)
         │     · storage upload · remote https URL · wordpress.org slug
         │
         └── Demo     (what the site looks like afterwards)
               · Assets      content · widgets · customizer · elementor_kit · plugin
               · Required plugins
               · Import plan overrides
```

A theme is offered to customers only when it has **at least one active version**
and **at least one active demo**. Anything else would let someone buy a site
that cannot be built.

---

## Adding a theme

**Addon → Themes & demos → Add a theme.**

| Field | Notes |
|---|---|
| Name | Shown to the customer |
| Slug | **Must match the theme's directory name inside the zip.** The agent activates by this slug. |
| Description | Shown under the name; plain text |
| Preview image URL | Absolute http(s); 4:3 looks best |
| Homepage URL | Optional, for your own reference |
| Sort order | Lower first |

The slug is the one field worth being careful about. If the zip contains
`astra/style.css`, the slug is `astra`. A mismatch produces a
`theme_not_found` failure after a successful download, and the message says so.

### Adding a version

| Source | `source_ref` | Checksum |
|---|---|---|
| `storage` | Path relative to the storage root, e.g. `themes/astra-4.6.1.zip` | **Required** |
| `url` | Absolute `https://` URL | **Required** |
| `wporg` | The wordpress.org slug | Not applicable |

For `storage`, upload the file yourself and record its digest:

```bash
sudo install -m 0640 -o www-data -g www-data \
  astra-4.6.1.zip /var/lib/whmcs-wtp/packages/themes/astra-4.6.1.zip
sha256sum astra-4.6.1.zip
```

The checksum is not paperwork. The agent refuses any package whose bytes do not
match, and WHMCS refuses to issue a download grant when the file on disk stops
matching the catalogue — which is what catches a partial upload or a swapped
file before it reaches a customer.

Mark one version **default**; that is what new orders receive. Keeping older
versions active lets existing jobs finish on the version they started with.

---

## Adding a demo

**Theme → Demos → Add a demo.**

| Field | Notes |
|---|---|
| Name, slug | Slug is used for idempotency markers; keep it stable |
| Description | Shown on the order form |
| Preview image URL | The screenshot customers choose from |
| Live preview URL | Adds a "Preview" link |
| Required plugins | JSON, see below |
| Import plan overrides | JSON, see below |

### Assets

Upload the files the demo needs. The **role** decides which importer runs, and
the steps in the plan are derived from the assets that actually exist — a demo
with no widgets file simply has no widgets step.

| Role | File | Importer |
|---|---|---|
| `content` | WordPress WXR export (`.xml`, or a `.zip` containing one) | `content` |
| `widgets` | Widget JSON (Widget Importer & Exporter format) | `widgets` |
| `customizer` | Customizer export (`.dat`) | `customizer` |
| `elementor_kit` | Elementor kit (`.zip` or `.json`) | `elementor` |
| `plugin` | A plugin zip referenced from *Required plugins* | `plugins` |
| `media`, `other` | Not imported automatically | — |

Uploads accept `zip, xml, json, dat, wie, txt, csv` up to 512 MB. Zip archives
are inspected on upload for traversal, symlinks and executable content, and
rejected there rather than on a customer's site.

### Required plugins

```json
[
  { "source": "wporg", "slug": "elementor", "activate": true },
  { "source": "wporg", "slug": "contact-form-7", "version": "5.9.3", "activate": true },
  { "source": "asset", "asset_id": 91, "activate": true }
]
```

`asset_id` must belong to **this** demo — a demo cannot reference another's
asset, and the plan builder silently drops any that tries.

A plugin that fails to install is a warning, not a failure: the rest of the
demo still imports, and the operator sees exactly what was missed.

### Import plan overrides

Optional. Steps come from the assets; this block adjusts everything else.

```json
{
  "site": {
    "front_page": "Home",
    "blog_page": "Blog",
    "permalink_structure": "/%postname%/",
    "menus": { "primary": "Main Menu", "footer": "Footer Menu" },
    "discourage_search_engines": false
  },
  "options": {
    "source_site_url": "https://demo.example.com/agency",
    "replace_urls": true,
    "fetch_attachments": true,
    "delete_default_content": true
  },
  "step_options": {
    "content": { "chunk_size": 25, "fetch_attachments": true }
  },
  "options_to_set": {
    "my_theme_accent_colour": "#2f7de1"
  }
}
```

| Key | Effect |
|---|---|
| `site.front_page` | Page title (or slug) to set as the front page |
| `site.menus` | Theme menu location → menu name from the export |
| `options.source_site_url` | The demo site's URL, rewritten to the customer's |
| `options.fetch_attachments` | Download images referenced in the WXR. Slower, and much better-looking. |
| `options.delete_default_content` | Remove "Hello world!" and the sample page |
| `step_options.content.chunk_size` | Items per tick; lower on slow hosts |
| `options_to_set` | Flat scalar options written after the import |

`options_to_set` cannot write `siteurl`, `home`, `active_plugins`, user roles or
anything prefixed `wtp_`. A demo is content, not an administrator.

`source_site_url` is worth getting right. Without it, imported content keeps
pointing at your demo site — the single most common cause of "the images are
broken".

---

## Enabling it for a product

**Addon → Products.**

| Setting | Meaning |
|---|---|
| Theme selection | Show the picker on this product's order form |
| Required | Refuse checkout until a demo is chosen |
| Available themes | Leave empty to offer every active theme |
| Default theme / demo ID | Used for admin- and API-created orders, which have no order form |

The product itself must use the **WordPress Hosting (Theme Provisioner)**
module — see [module-configuration.md](module-configuration.md).

Set the defaults even when selection is required. An order placed through the
API, or by you on behalf of a customer, never sees the order form.

---

## Changing a live demo

| Change | Effect on jobs in flight |
|---|---|
| Editing the description or image | None; cosmetic |
| Adding an asset | Picked up by any job that has not fetched its plan |
| Replacing an asset file **without updating the checksum** | Grants start failing with `asset_unavailable` — do not do this |
| Replacing an asset properly (upload new, update, remove old) | Safe |
| Deactivating a demo | Jobs that have not fetched their plan fail with `demo_inactive` and are retryable once you reactivate or reassign |
| Deleting a demo | Refused while any service references it |

Deactivate rather than delete. The selection row is a customer's order history,
and deleting the demo would erase what they actually bought.

---

## Writing a new importer

The registry is a filter. Drop a plugin into the provisioned sites (or add it
to the agent package) and register:

```php
add_filter('wtp_importers', function (array $importers): array {
    $importers[] = new Acme_Forms_Importer();
    return $importers;
});

final class Acme_Forms_Importer implements Wtp_Importer
{
    public function key(): string
    {
        return 'acme_forms';           // the plan's "importer" value
    }

    public function supports(array $plan, array $step): bool
    {
        return isset($step['asset']) && class_exists('Acme_Forms');
    }

    public function run(Wtp_Import_Context $context): Wtp_Import_Result
    {
        if ($context->alreadyDone()) {
            return Wtp_Import_Result::done(['repeated' => true]);
        }

        $file = $context->downloader()->fetch($context->asset());
        $forms = json_decode((string) file_get_contents($file), true);

        $index = (int) $context->cursorValue('index', 0);
        foreach (array_slice($forms, $index) as $offset => $form) {
            if (!$context->hasTimeLeft(5.0)) {
                // Resume here on the next tick rather than risk a timeout.
                return Wtp_Import_Result::progress(['index' => $index + $offset]);
            }
            Acme_Forms::create($form);
        }

        $context->markDone('', ['forms' => count($forms)]);
        return Wtp_Import_Result::done(['forms' => count($forms)]);
    }
}
```

Two obligations, both enforced by the runner rather than by convention:

* **Resumable** — check `hasTimeLeft()` in any loop and return `progress()`
  with a cursor. The runner persists it and calls you again.
* **Idempotent** — use `alreadyDone()` / `markDone()`. Your importer will be
  re-run after a crash, a retry, or a site restore.

Then add `acme_forms` to `ImportPlan::IMPORTERS` (both copies are generated
from `shared/`, so edit `shared/Wtp/Shared/Plan/ImportPlan.php` and rebuild),
and reference it from a demo's plan.
