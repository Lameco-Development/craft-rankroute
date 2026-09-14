# RankRoute

The Craft side of RankRoute, Laméco's SEO content pipeline that runs in n8n. n8n decides
*what* to change (from SE Ranking data and an LLM); this plugin is the only thing that
reads from and writes to the Craft site, over HTTP with a shared API key.

## Language

### The two flows

**Optimizer flow** (n8n "flow 1 — Entry Optimizer"):
Export one element as a JSON document, let n8n rewrite it, import the rewritten document.
The result is always a *draft*; an editor publishes it.
_Avoid_: entry optimizer (elements are not only entries), content sync

**Bulk meta flow** (n8n "flow 2 — SEO bulk-fill"):
Write a meta title and/or meta description onto many elements at once, addressed by URL.
Saves the *live* element directly; nothing is reviewed in Craft.
_Avoid_: SEO import (the optimizer flow also imports SEO fields)

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
resolved to a site; no match means the primary site.

**Element**:
Whatever Craft element owns a URI — an entry, a Commerce product, a category. Both flows
resolve through `Elements::getElementByUri()`, so the element type is never assumed.
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

### Compatibility

**Legacy alias**:
An action path from `craft-entry-optimizer` or `craft-seo-import` that this plugin still
answers, so n8n flows keep working until each site's flows are migrated. Removed in 0.1.0.

## Boundaries

- n8n is the only intended client. Payload and response shapes are contracts with the n8n
  HTTP nodes; changing a key is a breaking change even when Craft would not notice.
- SEOmatic is optional at runtime. The bulk meta flow needs it and says so; the optimizer
  flow works without it.
- The plugin never publishes in the optimizer flow and never creates drafts in the bulk
  meta flow.
