# RankRoute for Craft CMS

The Craft side of RankRoute, Laméco's SEO content pipeline in n8n. One plugin replacing
`lameco/craft-entry-optimizer` and `lameco/craft-seo-import`.

## Requirements

- PHP 8.2 or later
- Craft CMS 5.8.0 or later
- SEOmatic (optional; required for the bulk meta endpoint)

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
    "seoImport": "rankroute/seo/import"
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

## Field handlers

Each handler knows how to export, import and detect changes for one family of field
types. Handlers are checked by priority, highest first; registration order in
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

Per-site runbook and the six-site checklist: [`docs/migration.md`](docs/migration.md).

See `CONTEXT.md` for the vocabulary and `docs/adr/` for the decisions.

## Development

```bash
composer check-cs
composer phpstan
composer test        # needs tests/.env, see tests/.env.example
```
