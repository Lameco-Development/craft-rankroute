# Plan: new page as a draft, based on an existing entry

Status: implemented on branch `feat/new-page-draft` (base `feat/text-flow`). Decided in the
product meeting of 2026-09-22; ADR 0004.

## Problem

The RankRoute backend will run a competitor gap analysis via SE Ranking. For a chosen gap
(a topic or keyword the customer does not cover yet) it has to create a *new* page. The
customer picks an existing entry as the base. The result must be that entry, copied:
same section and entry type, same Matrix blocks in the same order and count, buttons,
links, forms and every other non-text value kept, but new text in every text item, a new
slug, and every image replaced by one placeholder the editor has to swap. It is saved as a
draft; nothing goes live.

## Approach

A fourth text flow endpoint, `text/create`, that reuses everything the text flow already
has:

- **Skeleton = the source's text export.** `text/export` of the source already gives the
  backend every text item with its address, type and `maxLength`, plus the fingerprint.
  The backend fills that skeleton with new strings. No new export endpoint.
- **Validation = `TextImportValidator`, unchanged.** Same id set, same rules (non-empty,
  `maxLength`, no Twig, no markup in plain, SEO syntax, reference tags byte for byte, tag
  skeleton identical). The only rules that compare against the original are structural
  (skeleton, reference tags, SEO syntax) and stay. Content rules such as length ratio or
  "must differ from the source" are the backend's business.
- **Copy = Craft's own duplicate.** `Elements::duplicateElement($source, …, asUnpublishedDraft: true)`,
  the same call as the control panel's "Duplicate as draft". Craft duplicates nested
  entries (every level) with the new owner and copies every site's content.
- **Write = the text flow writer.** The copy is extracted with `TextExtractor`; its item
  ids must equal the source's. The submitted strings are written onto the copy with the
  same delta Matrix writer as `text/import` (moved into `TextWriter`), plus the placeholder
  in every non-empty Assets field.
- **Proof = the structure check in "copy" mode.** Source and copy are compared with
  `StructureSnapshot`, minus what is supposed to differ: ids of nested entries (compared
  by position), slug, URI, post/expiry date, and Assets values (the source side is mapped
  to the placeholder). A difference rolls the whole creation back.

## Contract (the backend depends on this)

`Authorization: Bearer <RANKROUTE_API_KEY>` like every text endpoint.

### `POST /actions/rankroute/text/create`

```json
{
  "sourceElementId": 51,
  "siteId": 1,
  "fingerprint": "sha256:…",
  "slug": "industrial-applications",
  "items": [
    { "id": "title", "value": "Industrial applications" },
    { "id": "pageBuilder[3].content", "value": "<p>Read <a href=\"/about\">who we are</a>.</p>" }
  ],
  "idempotencyKey": "gap-12"
}
```

- `sourceElementId` + optional `siteId` (primary site when omitted), or `url` of the
  source, resolved exactly like `text/export`.
- `fingerprint`: from `text/export` of the source. The source must not have changed since.
- `slug`: the slug of the new page in that site. Must already be a normalised Craft slug
  (`ElementHelper::normalizeSlug($slug) === $slug`, at most 255 characters); the plugin
  never rewrites it.
- `items`: every id of the source export, exactly like `text/import`.
- `idempotencyKey`: optional, same format as import (`[A-Za-z0-9._:-]{1,64}`).

There is no separate `title` parameter: the title is the `title` item, as in the export.
An entry type whose title is generated (title format) regenerates it on save.

Validation order: body shape (`400`), source exists (`404`), source is a copyable entry
(`422 unsupported_element`), `idempotencyKey` (`422`), `slug` (`422 invalid_slug`),
replay (below), fingerprint (`409`), items (`422`, same codes and table as import),
slug collision (`409 slug_taken`, checked on the copy inside the transaction).

### Response `200`

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

- `elementId` = `draftElementId`: an unpublished draft is its own canonical element, and
  Craft keeps that id when the editor publishes it.
- `changedItems`: the items whose value differs from the source (the others were written
  as submitted, which equals the copied value).
- `placeholderAssetId`: the placeholder asset, `null` when the source had no images.
- `placeholders`: addresses (text flow address syntax) of every Assets field set to the
  placeholder, plus every `html` item in which an asset reference tag was replaced.
- `uri`: the URI the page will get when published, `null` when the section has no URLs.

### Errors

| Status | `code` / body | Meaning |
|---|---|---|
| 400 | `{"error"}` | Not JSON, `items` not a list, `fingerprint` or `slug` not a string |
| 401 | `{"error"}` | Auth |
| 404 | `{"error"}` | Source not found (same rules as export by id or url) |
| 409 | `fingerprint_mismatch` | The source changed since the export |
| 409 | `slug_taken` | The URI the slug gives in that site belongs to another live element or to another unpublished draft |
| 422 | `unsupported_element` | The source is not an entry of a channel or structure section (singles, categories, products, nested entries cannot be copied) |
| 422 | `invalid_idempotency_key` | As import |
| 422 | `invalid_slug` | Empty, not normalised, too long, or reserved |
| 422 | item codes | Exactly the import table (`unknown_id`, `missing_id`, `duplicate_id`, `empty_value`, `too_long`, `forbidden_syntax`, `twig_in_value`, `html_in_plain`, `reference_tag_changed`, `html_structure_changed`) |
| 500 | `structure_check_failed` | The copy differs from the source beyond text, slug and images; everything is rolled back |
| 500 | `{"error"}` | Unexpected (reference in the log), including "no volume for the placeholder" |

Error bodies are the import shapes: `{"success": false, "errors": [{id, code, message}]}`
for coded errors, `{"error": "…"}` otherwise.

### Idempotency

The draft's notes hold `rankroute:<key>` and a JSON line
`{"flow": "create", "sourceElementId", "siteId", "slug", "placeholderAssetId", "changedItems", "placeholders"}`
(without a key the first line is `rankroute:`, so `text/verify` still recognises the draft).
A request with a key that already created an unpublished draft from the same source in
the same site answers from that draft with `"replayed": true` and re-runs the structure
check; nothing is validated or written. Once the editor publishes the draft, the notes are
gone and the same key would create a second page: the key protects retries, not reruns.

### `text/verify`

`GET text/verify?draftId=<drafts.id>` also accepts a created draft. It compares the draft
with its source in copy mode and answers the import shape plus `sourceElementId`:

```json
{ "success": true, "elementId": 4600, "sourceElementId": 51, "siteId": 1, "draftId": 130, "draftElementId": 4600, "structureCheck": {…} }
```

An unpublished draft that `text/create` did not make answers `404`.

## Decisions

### Structure position: next to the source

The copy gets the source's parent (root level when the source is at the root) and is
appended at the end of that level (the section's default placement). While it is a draft it
is invisible to every front-end query (Craft excludes drafts by default), so menus built
from the structure do not change. When the editor publishes it, it becomes a sibling of the
source, just like a page the editor would have created next to it; the parent can be
changed in the draft before publishing. Root level was the alternative, but a root-level
page is exactly what a main menu built from `level(1)` shows, and the URI would no longer
follow the source's pattern. A parent proposal from the backend can be added later.

The status is copied from the source: when the editor publishes, the page is live if the
source was. See open questions.

### Placeholder image: one bundled PNG, created once per install

`src/resources/rankroute-placeholder.png` (1600x900, striped, "RankRoute placeholder /
Vervang deze afbeelding / Replace this image") is uploaded as `rankroute-placeholder.png`
into the root folder of the volume named by `textFlow.placeholderVolume` in
`config/rankroute.php`, or the first volume (sort order) when that is not set. Title and
alt: "RankRoute placeholder: vervang deze afbeelding". It is looked up by that filename in
that folder and reused; only when it is missing is it uploaded (under a mutex). It is only
created when a copy actually has an image to replace. No volume at all, or an unknown
configured handle, answers 500 without writing.

Replaced: every non-empty Assets field on the copy, at every depth (disabled and excluded
nested entries included, a multi-asset field becomes one placeholder), and every
`{asset:<id>…}` reference tag inside the `html` items the backend submitted (CKEditor
inline images). Not replaced: `<img>` with a literal URL instead of a reference tag,
images in HTML that is not an item, SEOmatic's own image settings, and images in nested
entries owned by another element.

### Slug collision: 409, no auto-suffix

The slug is an SEO decision of the backend (it targets the gap keyword). Craft would
silently turn a taken slug into `…-2`, which is a different URL than the one the backend
reasons about, and a taken slug usually means the page already exists or the request is a
retry without a key. So: after Craft sets the URI on the copy, a changed slug (Craft had
to suffix because a live element has that URI) or another unpublished draft with the same
URI in that site rolls back and answers `409 slug_taken`. The backend can propose another
slug. Two requests for the same slug and site are serialised by a mutex.

### Multi-site: the copy lives in the source's site; other sites are disabled copies

`duplicateElement` creates the copy in every site the section's propagation method
requires, each with the source's content *for that site* (so no language is mixed into
another site). In every site other than the requested one the copy is **disabled for that
site** and gets the new slug; its texts stay the source's texts in that language. The new
texts are only written in the requested site. Only a text field whose translation method
shares values across sites (not per site) carries the new text into other sites, the same
limit ADR 0003 documents for import. Assets fields are usually not translatable, so the
placeholder shows in every site; those sites are disabled anyway.

## Components

- `services/text/TextWriter`: the delta writer, moved out of `TextImportService`, now takes
  `{entryPath, address, value}` writes with any value (strings or asset ids).
- `services/text/PlaceholderImage`: volume choice, lookup, upload, and
  `replaceAssetReferences()` (pure).
- `TextExtractor::assetFields()`: every non-empty Assets field with its address and entry
  path, disabled and excluded nested entries included, shared ones skipped.
- `StructureSnapshot::buildForCopy()` / `StructureCheck::checkCopy()`.
- `services/TextCreateService`, `dto/TextCreateResult`.
- `TextController::actionCreate()`, `actionVerify()` for created drafts, `status` lists
  `textCreate`.

## Tests

- Unit: `PlaceholderImageTest` (reference tag replacement), `CreateDraftNotesTest` (notes
  round trip).
- Integration `TextCreateTest` on the text flow fixture (the fixture gains a nested Assets
  field `cardImage` on the card entry type): a created copy has the same blocks, types,
  order and count with new ids, only the submitted texts, the placeholder in every asset
  field, buttons and links unchanged, the source untouched; placeholder reused; inline
  image replaced; slug taken (live and draft) → 409; invalid slug → 422; every import
  rejection path → 422 with nothing created; fingerprint → 409; replay; multi-site
  (other site disabled, keeps its own texts); structure position after publishing; verify.
