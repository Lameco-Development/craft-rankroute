# RankRoute

The Craft side of RankRoute, Laméco's SEO content pipeline. A client (the n8n flows, and
the RankRoute backend that replaces them) decides *what* to change, from SE Ranking data
and an LLM; this plugin is the only thing that reads from and writes to the Craft site,
over HTTP with a shared API key.

## Language

### The three flows

**Optimizer flow** (n8n "flow 1 — Entry Optimizer"):
Export one element as a JSON document, let n8n rewrite it, import the rewritten document.
The result is always a *draft*; an editor publishes it.
_Avoid_: entry optimizer (elements are not only entries), content sync

**Bulk meta flow** (n8n "flow 2 — SEO bulk-fill"):
Write a meta title and/or meta description onto many elements at once, addressed by URL.
Saves the *live* element directly; nothing is reviewed in Craft.
_Avoid_: SEO import (the optimizer flow also imports SEO fields)

**Text flow** (RankRoute backend):
Export only the text items of one element, let the backend rewrite the strings, import
`{id, value}` pairs. Craft writes only those strings into a *draft* on the untouched
structure and proves it with the structure check. See ADR 0003.
_Avoid_: optimizer v2, text optimizer (it is not a variant of the optimizer flow)

### Documents

**Export document**:
The JSON the optimizer flow hands to n8n: `metadata` (`id`, `siteId`), `title`, and every
custom field of the element's layout, empty ones included, each serialised by its field
handler. Always wrapped in a one-element array.
_Avoid_: export, payload, entry JSON

**Import document**:
An export document after n8n edited it. Same shape; `metadata.id` and `metadata.siteId`
identify the element.

**Bulk meta item**:
One row of the bulk meta flow: `url`, `meta_title`, `meta_description`. n8n sends them as a
flat array, as `{results: [...]}`, or as `[{results: [...]}]`; all three are accepted.

### Resolution

**Path**:
What is left of a URL after protocol and host are stripped and slashes trimmed. May start
with a site's base-path prefix (`nl/projecten`).

**Site base path**:
The path component of a site's base URL (`/nl/` → `nl`). Longest prefix wins when a path is
resolved to a site; no match means the primary site. A full URL only matches the sites on
its host (a site on its own domain, `https://example.de/`, is found by host alone); a host
no site has is ignored.

**Element**:
Whatever Craft element owns a URI — an entry, a Commerce product, a category. All flows
resolve a URL through `Elements::getElementByUri()`, so the element type is never assumed.
_Avoid_: entry (except when it really is `craft\elements\Entry`)

### Fields

**Field handler**:
The object that knows how to export, import and compare one family of field types. Picked
by priority: specialised handlers at 50, the default handler at -100.

**Changed field**:
A field whose imported value differs from the element's current value according to its
handler's `hasChanged()`. Only changed fields are written to the draft; no changed fields,
no draft.

**Handler hint**:
`__handler` / `__value` keys inside Matrix block data, so the import side can pick the right
handler for nested fields without re-deriving the field layout.

### Text flow

**Text item**:
One string of an element the text flow may rewrite: `{id, type, value, maxLength}`. `type`
is `plain` (PlainText, native title, SEOmatic meta) or `html` (CKEditor, Redactor). Only
non-empty values that are not a URL, e-mail address or number, contain no Twig, are not
excluded by `config/rankroute.php` and are not shared with another site of the element
(a translation key other sites have too) become items.
_Avoid_: field (an item can be a title or SEO meta), block

**Address**:
The `id` of a text item: a dot path from the element. `title`, `seo.seoTitle`,
`seo.seoDescription`, a field handle, or `<matrixHandle>[<n>].` steps before one of those,
where `n` is the 0-based position over all nested entries, disabled ones included
(`pageBuilder[6].items[1].title`).
_Avoid_: key, path (that is the URL term above)

**Fingerprint**:
`sha256:` hash of the element's structure snapshot and text items at export time. The
import must echo it; any edit to the element in between makes the import answer 409.

**Tag skeleton**:
The ordered opening, closing and void tags of an HTML value with their attributes, plus
comments and raw-text element content compared exactly, and none of the visible text. An
`html` item may only be imported with an identical skeleton, so links, classes and nesting
cannot change.
_Avoid_: HTML structure (ambiguous with block structure)

**Structure snapshot**:
A normalised tree of everything about an element except its extractable text: element
attributes, non-text field values, SEOmatic settings without the meta title/description,
tag skeletons of HTML items, and nested entries by canonical id.

**Reference tag**:
Craft's `{type:ref:attribute||fallback}` syntax (`{entry:6@1:url||https://…}`). The text
flow treats every reference tag in a value as immutable, fallback included.

**Idempotency key**:
Optional `idempotencyKey` of a text import, stored in the draft's notes. A retry with the
same key for the same element and site answers from that draft (`replayed: true`) instead
of creating another one.
_Avoid_: request id, run id (the backend's key happens to be `run-page-<id>`, but it is a key)

**Structure check**:
Comparing the structure snapshots of the canonical element and a draft. Runs on every text
import (a failure discards the draft) and on demand through `text/verify`. Answers
`{passed, differences: [{path, before, after}]}`.

**Smoke run**:
`php craft rankroute/text-flow/smoke` against a site: the full text flow over HTTP for every
live element, with a deterministic rewrite instead of an LLM, plus negative probes. Zero
failures is the gate before the backend is pointed at the site.
_Avoid_: smoke test (that is the status/auth check in the migration runbook)

**RankRoute backend**:
The Laravel app in the separate `rankroute` repo that replaces the n8n flows. The only
client of the text flow.
_Avoid_: n8n, the pipeline

### Compatibility

**Legacy alias**:
An action path from `craft-entry-optimizer` or `craft-seo-import` that this plugin still
answers, so n8n flows keep working until each site's flows are migrated. Removed in 0.1.0.

## Boundaries

- There are two clients. n8n uses the optimizer flow, the bulk meta flow and the legacy
  aliases until each site moves; the RankRoute backend uses the text flow. Payload and
  response shapes are contracts with those clients; changing a key is a breaking change
  even when Craft would not notice.
- SEOmatic is optional at runtime. The bulk meta flow needs it and says so; the optimizer
  flow works without it.
- The plugin never publishes in the optimizer flow and never creates drafts in the bulk
  meta flow.
- The text flow never publishes and never writes anything but text items: no fields,
  nested entries, links or settings beyond the submitted strings.
