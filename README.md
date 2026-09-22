# RankRoute for Craft CMS

The Craft side of RankRoute, Laméco's SEO content pipeline. One plugin replacing
`lameco/craft-entry-optimizer` and `lameco/craft-seo-import`, plus the text flow used by the
RankRoute backend (ADR 0003).

## Requirements

- PHP 8.2 or later
- Craft CMS 5.8.0 or later
- SEOmatic (optional; required for the bulk meta endpoint)
- CKEditor or Redactor (optional; their fields become `html` items in the text flow)

## Installation

```bash
composer require lameco/craft-rankroute
php craft plugin/install rankroute
```

Set the API key in `.env`:

```dotenv
RANKROUTE_API_KEY=your-secret-key
```

## Authentication

Every endpoint except `status` requires the shared API key from `RANKROUTE_API_KEY`,
passed as a Bearer token:

```
Authorization: Bearer your-secret-key
```

There is no Craft CP-session fallback and no settings UI: a logged-in CP user without the
header is still unauthenticated (ADR 0001).

| Situation | Response |
|---|---|
| `RANKROUTE_API_KEY` unset or empty | `401` — `API key not configured` |
| Header missing, not `Bearer `-prefixed, or key mismatch | `401` — `Authentication required` |
| `rankroute/optimizer/status` | public, no header needed |

## Endpoints

All paths are Craft action paths, so they are prefixed with `/actions/`. The legacy
aliases are the action paths of the two plugins RankRoute replaces; they dispatch to the
same controller actions and honour the same `RANKROUTE_API_KEY` — never the old env vars
(ADR 0002).

| Path | Method | Legacy alias | Params / body |
|---|---|---|---|
| `rankroute/optimizer/status` | GET | `_craft-entry-optimizer/optimized-entry` | — (public) |
| `rankroute/optimizer/export` | GET | `_craft-entry-optimizer/optimized-entry/export` | `id` **or** `slug` (query) |
| `rankroute/optimizer/import` | POST | `_craft-entry-optimizer/optimized-entry/import` | raw JSON export document |
| `rankroute/seo/import` | POST | `_craft-seo-import/api/import` | raw JSON bulk meta items |
| `rankroute/text/export` | GET | — | `url`, **or** `id` with optional `siteId` (query) |
| `rankroute/text/import` | POST | — | JSON `{elementId, siteId, fingerprint, items}` |
| `rankroute/text/create` | POST | — | JSON `{sourceElementId, siteId, fingerprint, slug, items}` |
| `rankroute/text/verify` | GET | — | `draftId`, optional `siteId` (query) |

**Legacy aliases are removed in 0.1.0.** Removing them is a breaking change and waits for
every site in `docs/migration.md` to be ticked off.

### Status

`GET /actions/rankroute/optimizer/status` — the one endpoint that needs no key.

```json
{
  "plugin": "RankRoute",
  "version": "0.0.1",
  "status": "active",
  "endpoints": {
    "export": "rankroute/optimizer/export",
    "import": "rankroute/optimizer/import",
    "seoImport": "rankroute/seo/import",
    "textExport": "rankroute/text/export",
    "textImport": "rankroute/text/import",
    "textVerify": "rankroute/text/verify",
    "textCreate": "rankroute/text/create"
  }
}
```

### Export

`GET /actions/rankroute/optimizer/export`

Exports one element as an export document. Any element type with a field layout works —
entries, Commerce products, categories — because the path is resolved through Craft's
element index.

Query parameters, one of which is required (`400` when both are missing):

- `id` (int) — element ID
- `slug` (string) — element URI, optionally with a site base-path prefix (`nl/projecten`)

The response is always a one-element array:

```json
[
  {
    "metadata": {
      "id": 123,
      "siteId": 1
    },
    "title": "Page Title",
    "bodyContent": "<p>Rich text content...</p>",
    "pageBuilder": [
      {
        "type": "contentBlock",
        "title": "Block Title",
        "content": "<p>Block content</p>",
        "backgroundColor": {
          "__handler": "DropdownFieldHandler",
          "__value": { "value": "green", "label": "Green" }
        }
      }
    ],
    "seo": { "seoTitle": "...", "seoDescription": "..." }
  }
]
```

### Import

`POST /actions/rankroute/optimizer/import`

Send the edited export document as the raw request body. Only fields whose value differs
from the element's current value are written, and they are written to a **draft** — the
plugin never publishes. No changed fields, no draft.

**Changes detected:**

```json
{
  "success": true,
  "message": "Draft created successfully with 2 updated field(s)",
  "draftId": 456,
  "entryId": 123,
  "cpEditUrl": "/admin/entries/section-handle/123/draft/456",
  "updatedFields": ["bodyContent", "pageBuilder"]
}
```

**No changes:**

```json
{
  "success": true,
  "message": "No changes detected",
  "entryId": 123,
  "updatedFields": []
}
```

**Failure** (still `200`, with `success: false`):

```json
{
  "success": false,
  "message": "Import failed",
  "errors": { "bodyContent": ["..."] }
}
```

An empty body is a `400`. `entryId` is named that way even for non-entry elements, because
n8n reads that key.

### Bulk SEO meta import

`POST /actions/rankroute/seo/import`

Writes SEOmatic's meta title and/or description onto the **live** element found for each
`url`; nothing is drafted or reviewed. Requires SEOmatic to be installed and enabled —
otherwise `400` — `SEOmatic is not installed`.

Three body shapes are accepted, all carrying the same items:

```json
[{ "url": "https://example.com/nl/projecten", "meta_title": "Title", "meta_description": "Description" }]
```

```json
{ "results": [{ "url": "...", "meta_title": "...", "meta_description": "..." }] }
```

```json
[{ "results": [{ "url": "...", "meta_title": "...", "meta_description": "..." }] }]
```

One of `meta_title` / `meta_description` suffices; the other is left untouched. A body
that is empty, invalid JSON, or does not normalise to a non-empty list is a `400`.

**Response:**

```json
{
  "success": true,
  "updated": 2,
  "total": 4,
  "skipped": [
    { "url": null, "reason": "No url provided" },
    { "url": "https://example.com/nl/onbekend", "uri": "nl/onbekend", "reason": "No entry found" }
  ]
}
```

`total` counts every item in the normalised payload, skipped ones included. The skip
reasons are:

| `reason` | Meaning |
|---|---|
| `No url provided` | The item has no `url` (reported, not silently dropped) |
| `No meta_title or meta_description provided` | Both are empty, so there is nothing to write |
| `No entry found` | No element owns that URI on the resolved site; the entry also carries `uri` |
| `No SEOmatic field found on this entry` | The element's field layout has no SEOmatic field |
| `Failed to save entry` | Craft refused the save (validation) |

## Text flow

The text flow optimises text without being able to touch anything else (ADR 0003). Craft
extracts the text items, the RankRoute backend rewrites only the strings, and Craft writes
the changed strings into a draft on the untouched structure. The endpoints above are not
changed by it; n8n keeps using them.

Errors outside import validation answer `{"error": "…"}` with their status: `400` bad
input, `401` auth (`API key not configured` / `Authentication required`), `404` element or
draft not found, `500` unexpected failure. A `500` never carries the exception detail:
the body is `{"error": "Internal error (ref 1a2b3c4d)."}` and the detail is in the Craft
log under that reference.

### Text export

`GET /actions/rankroute/text/export?url=<url or path>`
or `GET /actions/rankroute/text/export?id=<elementId>&siteId=<siteId>`

`url` is resolved like the other flows: a full URL only matches the sites whose base URL has
its host (so a language on its own domain is found), then the longest site base-path prefix
wins. Without `siteId`,
`id` is looked up in the primary site. By `id`, only top-level entries, categories and
Commerce products are found; a nested entry, asset or any other element id answers `404`.

```json
{
  "element": {
    "id": 51,
    "siteId": 1,
    "type": "craft\\elements\\Entry",
    "url": "https://example.com/applications",
    "cpEditUrl": "https://example.com/admin/entries/pages/51"
  },
  "fingerprint": "sha256:9f2c…",
  "items": [
    { "id": "title", "type": "plain", "value": "Applications", "maxLength": 255 },
    { "id": "seo.seoTitle", "type": "plain", "value": "Applications | Example", "maxLength": null },
    { "id": "intro", "type": "plain", "value": "A short introduction", "maxLength": 160 },
    { "id": "pageBuilder[3].content", "type": "html", "value": "<p>Read <a href=\"/about\">about us</a>.</p>", "maxLength": null },
    { "id": "pageBuilder[6].items[1].title", "type": "plain", "value": "Step two", "maxLength": 255 }
  ]
}
```

- **Address** (`id`): `title`, `seo.seoTitle`, `seo.seoDescription`, a field handle, or
  `<matrixHandle>[<n>].` steps in front of one of those. `n` is 0-based over all nested
  entries of that field in sort order, disabled ones included.
- **`type`**: `plain` for PlainText, editable native titles and SEOmatic meta; `html` for
  CKEditor and Redactor.
- **`maxLength`**: 255 for native titles, the PlainText `charLimit` when set, else `null`.

A value becomes an item only when it is non-empty, not a URL, e-mail address or number,
contains no Twig (`{{`, `{%`), is not a plain value that already looks like markup (`<`
followed by a letter, `/`, `!` or `?`, which could never pass `html_in_plain`), its field
handle does not match `excludeFields`, its value is not shared with another site of the
element (translation method "not translatable", or a site group, language or custom key
another site of the element has too; Craft would write it into every such site, the
SEOmatic field and native titles included, unless `textFlow.exportSharedText` is on), and
every nested entry on its path is enabled, has
no type matching `excludeEntryTypes` and belongs to its owner (a nested entry shared from
another element is left alone). Links,
assets, relations, options, Lightswitch, Table, forms, Matrix structure, other SEOmatic
settings and text inside CKEditor nested entries are never items and never written.

### Text import

`POST /actions/rankroute/text/import`

```json
{
  "elementId": 51,
  "siteId": 1,
  "fingerprint": "sha256:9f2c…",
  "items": [
    { "id": "title", "value": "Applications for every site" },
    { "id": "pageBuilder[3].content", "value": "<p>Learn <a href=\"/about\">who we are</a>.</p>" }
  ]
}
```

`url` may replace `elementId`/`siteId`. `items` must contain **every** id from the export,
changed or not. Validation is all-or-nothing and runs before anything is written, in this
order: element exists (`404`), `idempotencyKey` (`422`), replay (below), fingerprint
(`409`), the items (`422`, one error per item, the first rule it breaks in the table
order below), then nested entry ownership (`422`).

**`idempotencyKey`** (optional): 1-64 characters of `A-Z a-z 0-9 . _ : -`, anything else
answers `422 invalid_idempotency_key`. The draft an import creates stores it in its notes
(`rankroute:<key>`, then a JSON line with the site id and the changed items). When a
request with a key arrives and a draft of the same element in the same site already
carries that key, nothing is validated (not even the fingerprint) and nothing is written:
the structure check runs again on that draft and the normal `200` response for it comes
back with `"replayed": true` and the `changedItems` stored with the key. A retry is
therefore safe; a different key creates another draft. The RankRoute backend sends
`run-page-<id>`.

**Changed items** (`200`): a draft with only the changed strings. Nested entries are
updated in place through Craft's delta Matrix format, so they keep their ids.

```json
{
  "success": true,
  "elementId": 51,
  "siteId": 1,
  "draftId": 123,
  "draftElementId": 4567,
  "cpEditUrl": "https://example.com/admin/entries/pages/51?draftId=123",
  "changedItems": ["title", "pageBuilder[3].content"],
  "structureCheck": { "passed": true, "differences": [] },
  "replayed": false
}
```

**Nothing changed** (`200`): same shape with `draftId`, `draftElementId` and `cpEditUrl`
`null`, `changedItems: []`; no draft is created.

**Rejected** (`409` or `422`), nothing written:

```json
{
  "success": false,
  "errors": [
    { "id": "pageBuilder[3].content", "code": "html_structure_changed", "message": "…" }
  ]
}
```

| Status | `code` | Meaning |
|---|---|---|
| 409 | `fingerprint_mismatch` | The element changed since the export (`id` is `null`); export again |
| 422 | `invalid_idempotency_key` | `idempotencyKey` is present but not 1-64 characters of `A-Z a-z 0-9 . _ : -` (`id` is `null`) |
| 422 | `unknown_id` | Not an item of this element, or an item without a string `id` |
| 422 | `missing_id` | An exported item is not in the submission |
| 422 | `duplicate_id` | The same id is submitted twice |
| 422 | `empty_value` | Not a string, empty after trim, or HTML without any text left |
| 422 | `too_long` | Longer than `maxLength` characters |
| 422 | `forbidden_syntax` | A `seo.*` value introduces `{`, `}` or `$` that the original does not contain, or starts with `@` while the original does not (SEOmatic parses meta for environment variables, aliases and object templates) |
| 422 | `twig_in_value` | Contains `{{` or `{%` |
| 422 | `html_in_plain` | A `plain` item contains `<` directly followed by a letter, `/`, `!` or `?` (no closing `>` needed) |
| 422 | `reference_tag_changed` | The Craft reference tags (`{entry:6@1:url\|\|https://…}`, anywhere in the value) differ from the original, compared as a sorted list byte for byte, fallback URL included |
| 422 | `html_structure_changed` | An `html` item's tag skeleton differs: another tag, tag order, attribute or attribute value (reference tag fallbacks included), any comment, CDATA, doctype or bogus comment, or the content of `script`, `style`, `textarea`, `title`, `xmp`, `iframe`, `noembed`, `noframes` or `noscript`. Comments end the way browsers end them (`<!-->`, `<!--->`, `-->`, `--!>`). Only attribute order is ignored |
| 422 | `shared_nested_entry` | A changed item sits in a nested entry whose primary owner is another element. Export already leaves such text out; this guards the write |

**Structure check failed** (`500`): the draft is saved inside a transaction and compared
with the canonical element; on any difference the transaction is rolled back, so no draft
remains. A replay whose stored draft no longer passes answers the same body with
`"replayed": true`, and the draft is left as it is. The snapshot ignores only the fallback
URL of reference tags (Craft rewrites it on save); a field value Craft cannot read is
recorded with its exception class and a hash of the message, so a different failure on
the draft still counts as a difference.

```json
{
  "success": false,
  "errors": [{ "id": null, "code": "structure_check_failed", "message": "The draft would change more than text; it was discarded." }],
  "structureCheck": {
    "passed": false,
    "differences": [{ "path": "fields.pageBuilder.entries[2].fields.button.value", "before": {"…": "…"}, "after": {"…": "…"} }]
  }
}
```

At most 50 differences are listed.

### Text create (new page)

`POST /actions/rankroute/text/create`

Creates a new page as an unpublished draft: a copy of an existing entry (the source) with
the submitted strings in its text items, a new slug, and one placeholder image in place of
every image. Everything else (blocks, their order and count, buttons, links, forms,
options, SEOmatic settings) is the source's. Nothing is published. See ADR 0004 and
`docs/plans/2026-09-22-new-page-draft.md`.

```json
{
  "sourceElementId": 51,
  "siteId": 1,
  "fingerprint": "sha256:9f2c…",
  "slug": "industrial-applications",
  "items": [
    { "id": "title", "value": "Industrial applications" },
    { "id": "pageBuilder[3].content", "value": "<p>Read <a href=\"/about\">who we are</a>.</p>" }
  ],
  "idempotencyKey": "gap-12"
}
```

`url` may replace `sourceElementId`/`siteId`. `fingerprint` and `items` come from
`text/export` of the source and are validated exactly like a text import (same codes, same
order). `slug` must already be a normalised Craft slug of at most 255 characters. There is
no separate title: the `title` item is the title. Only an entry of a channel or structure
section can be a source.

```json
{
  "success": true,
  "sourceElementId": 51,
  "siteId": 1,
  "elementId": 4600,
  "draftId": 130,
  "draftElementId": 4600,
  "slug": "industrial-applications",
  "uri": "solutions/industrial-applications",
  "cpEditUrl": "https://example.com/admin/entries/pages/4600?draftId=130",
  "changedItems": ["title", "pageBuilder[3].content"],
  "placeholderAssetId": 77,
  "placeholders": ["image", "pageBuilder[2].items[0].cardImage", "headerTitle"],
  "structureCheck": { "passed": true, "differences": [] },
  "replayed": false
}
```

- `elementId` equals `draftElementId`: the new page is its own canonical element and keeps
  that id when the editor publishes it.
- `changedItems`: the items whose value differs from the source.
- `placeholders`: every Assets field (any depth) set to the placeholder, then every `html`
  item in which an `{asset:…}` reference tag now points at it. `placeholderAssetId` is
  `null` when the source had no images.
- **Structure**: same parent as the source, at the end of that level. Drafts are left out
  of every element query, so menus only change once the editor publishes.
- **Sites**: the strings are written in the requested site only. In every other site the
  copy is disabled and keeps the source's texts in that language, with the new slug.
- **Placeholder image**: `rankroute-placeholder.png` in the root folder of
  `textFlow.placeholderVolume` (or the first volume), uploaded from the plugin once and
  reused; created only when a copy has an image to replace.

| Status | `code` | Meaning |
|---|---|---|
| 409 | `fingerprint_mismatch` | The source changed since the export |
| 409 | `slug_taken` | The URI of the slug in that site belongs to a live element or another unpublished draft. Never auto-suffixed |
| 422 | `unsupported_element` | The source is not an entry of a channel or structure section |
| 422 | `invalid_idempotency_key` | As import |
| 422 | `invalid_slug` | Empty, not normalised (`ElementHelper::normalizeSlug`), longer than 255 characters, or reserved |
| 422 | item codes | As import |
| 500 | `structure_check_failed` | The copy differs from the source in more than text, slug and images; nothing is kept |

`400`, `401`, `404` and unexpected `500`s answer `{"error": "…"}` as elsewhere. A missing
placeholder volume (none at all, or an unknown `placeholderVolume`) is such a `500`, before
anything is written.

**`idempotencyKey`** works as for import: stored in the draft notes (`rankroute:<key>`, then
a JSON line with the source, site, slug, placeholder and changed items); a retry with the
same key for the same source and site answers from that draft with `"replayed": true`. The
key lives in the draft, so once the page is published a new request creates another page.

### Text verify

`GET /actions/rankroute/text/verify?draftId=<drafts.id>[&siteId=<siteId>]`

Runs the structure check for an existing draft against its canonical element. Without
`siteId` the draft is checked in the primary site, or in the first site it exists in.
`400` without a numeric `draftId`, `404` for an unknown draft.

For a new page from `text/create` it compares the draft with its source in copy mode
(nested entries by position; slug, URI, dates and images excepted) and adds
`sourceElementId` to the response; without `siteId` it checks the site the page was
created for. Any other unpublished draft answers `404`.

```json
{
  "success": true,
  "elementId": 51,
  "siteId": 1,
  "draftId": 123,
  "draftElementId": 4567,
  "structureCheck": { "passed": true, "differences": [] }
}
```

A failed check is still `200`, with `passed: false` and the differences.

### Configuration

Optional `config/rankroute.php`; there is no settings UI. The defaults:

```php
return [
    'textFlow' => [
        // case-insensitive fnmatch patterns on field handles
        'excludeFields' => ['*url', '*webhook*', 'importId', '*Id', 'llmContent', 'cocNumber'],
        // case-insensitive fnmatch patterns on nested entry type handles
        'excludeEntryTypes' => ['*button*'],
        // Export text that other sites of the element share? Off is the safe default.
        //
        // Craft shares one value between sites when a field's translation method says so
        // ("not translatable", or a site group, language or custom key another site of the
        // element has too), and the same goes for a native title through its entry type.
        // The SEOmatic field is a field like any other here. Saving such a value in one
        // site writes it into every site sharing it, so with this option on, rewriting a
        // page in one language overwrites that text in every other language of the same
        // element. That is what went wrong on a multilingual site with the old n8n flow.
        //
        // Switch it on only when sharing is intended, e.g. several sites in one language.
        // For a multilingual site the fix is the other way round: make those fields, the
        // SEOmatic field included, translatable per site, and leave this off.
        'exportSharedText' => false,
        // volume handle for the text/create placeholder image; null = the first volume
        'placeholderVolume' => null,
    ],
];
```

A list value replaces its default list; it is not merged.

### Smoke command

```bash
php craft rankroute/text-flow/smoke [options]
```

For every live entry (nested entries excluded), category and Commerce product with a URI,
over HTTP against the site with `RANKROUTE_API_KEY`: export (the element and site in the
response must be the local ones, otherwise the element fails: the base URL points at
another installation), deterministic rewrite (plain: append ` ✓` within `maxLength`; html:
append ` ✓` to the last text of each block element), import with a random
`idempotencyKey`, require every item changed and `structureCheck.passed`, `text/verify`
the draft, run the negative probes, then delete the draft. Cleanup only deletes a draft of
that same element; when the import fails or its connection breaks, the draft is looked up
by its idempotency key and deleted. Elements without text items are skipped. Prints a
table and a summary.

| Option | Alias | Default | Meaning |
|---|---|---|---|
| `--site=<handle>` | `-s` | all sites | Test one site |
| `--uri=<uri>` | `-u` | | Test only the element with this URI |
| `--limit=<n>` | `-l` | all | Test at most n elements |
| `--keep-drafts` | `-k` | off | Keep every draft |
| `--probe=<n>` | `-p` | `3` | Run the probes on the first n elements with text: changed `href` (expects `422 html_structure_changed`), missing id (`422 missing_id`), stale fingerprint (`409 fingerprint_mismatch`), all without a key, then a retry with the import's key (expects `200`, `replayed: true`, same `draftId`) |
| `--sample=<n>` | | `0` | Keep the drafts of n random elements and print their preview and edit URLs |
| `--base-url=<url>` | `-b` | each site's base URL | Base URL for the HTTP calls, e.g. when the CLI cannot reach the public host |
| `--verbose` | `-v` | off | Print every element, response bodies of failures and structure differences |

Exit code `0` when nothing failed, `1` on any failure, `78` when `RANKROUTE_API_KEY` is not
set, `64` for an unknown site.

## Field handlers

Each handler knows how to export, import and detect changes for one family of field
types. Field handlers belong to the optimizer flow; the text flow does not use them. Handlers are checked by priority, highest first; registration order in
`Plugin::init()` puts the specialised handlers before the fallback.

**MatrixFieldHandler** (priority 50)
- Handles Matrix and Neo fields.
- Exports block structure with type, enabled state, and nested fields.
- Uses `__handler` and `__value` hints for reliable type detection during import.
- Supports recursive nesting.

**AssetFieldHandler** (priority 50)
- Handles Asset fields.
- Exports as `[{id, url, title, alt}]`.
- Change detection is order-independent.

**RelationFieldHandler** (priority 50)
- Handles Entries, Categories, Tags and Users relation fields.
- Exports as arrays of element IDs.
- Change detection is order-independent.

**LinkFieldHandler** (priority 50)
- Handles native Craft Link fields, Hyper, and Lenz Link.
- Exports as `{type, url, label, target, ariaLabel, element}`.
- Supports single and multi-link fields.

**DropdownFieldHandler** (priority 50)
- Handles Dropdown, RadioButtons, ButtonGroup, Checkboxes and MultiSelect fields.
- Exports as `{value, label}` objects (or arrays for multi-select).

**SeomaticFieldHandler** (priority 50)
- Handles SEOmatic fields.
- Only registered when the SEOmatic plugin is installed and enabled.
- Exports the complete SEO metadata structure (title, description, OG, Twitter, canonical, robots).

**DefaultFieldHandler** (priority -100, fallback)
- Handles PlainText, Number, Lightswitch, Email, Color, Date, Time, and any other field type.
- Uses Craft's native serialisation.

Handlers below priority 50 use Craft's native serialisation unless they say otherwise.

### Custom field handlers

Implement `FieldHandlerInterface` and register it:

```php
use lameco\rankroute\Plugin;

Craft::$app->onInit(function() {
    Plugin::getInstance()->fieldHandlerRegistry->register(new MyCustomFieldHandler());
});
```

## Migrating from craft-entry-optimizer / craft-seo-import

Per-site runbook, the six-site checklist and the checklist before switching a site to the
text flow: [`docs/migration.md`](docs/migration.md).

See `CONTEXT.md` for the vocabulary and `docs/adr/` for the decisions.

## Development

```bash
composer check-cs
composer phpstan
composer test        # needs tests/.env, see tests/.env.example
```
