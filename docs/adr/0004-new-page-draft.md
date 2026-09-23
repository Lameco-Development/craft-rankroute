# ADR 0004: A new page is a copy of an existing entry with new text

Date: 2026-09-22
Status: accepted

## Context

The RankRoute backend will run a competitor gap analysis via SE Ranking and, for a gap the
customer picks (a topic or keyword the site does not cover), create a new page. Decided in
the product meeting of 2026-09-22:

- The customer picks an existing entry as the base. No template concept, no example pages.
- The new page is literally a copy of that entry: same section and entry type, same Matrix
  blocks in the same order and count, buttons, links, forms and other non-text data as in
  the source, new text in every text item and a new slug proposed by the backend.
- It is a draft: nothing goes live, the customer reviews it in Craft. A new page is also
  created disabled, so publishing the draft still does not put it live (decision of the
  product owner, 2026-09-23).
- The LLM does not generate images. Every image becomes one placeholder image that makes
  obvious what the editor still has to do.
- Only for this flow; the text flow on existing entries (ADR 0003) stays as it is.

Until now the plugin never created an element: the text flow only writes strings into a
draft of an existing element. This adds a new endpoint and a new write, so it changes the
contract with the backend.

## Decision

Add `POST /actions/rankroute/text/create` to the text flow. The contract and the details
are in `docs/plans/2026-09-22-new-page-draft.md`; the README has the endpoint reference.

| | Decision |
|---|---|
| Skeleton | `text/export` of the source. The backend fills its items with new strings; no new export endpoint |
| Validation | `TextImportValidator` unchanged, plus the fingerprint of the source (409). Content rules that compare with the original (length ratio, "must differ") are the backend's business; the plugin keeps its structural rules (tag skeleton, reference tags, SEO syntax) |
| Copy | Craft's own `Elements::duplicateElement(…, asUnpublishedDraft: true)`: an unpublished draft that owns copies of every nested entry |
| Write | The text flow writer (`TextWriter`, moved out of `TextImportService`) writes the strings and the placeholder through the delta Matrix format |
| Proof | The structure check in copy mode compares source and copy: equal except nested entry ids (compared by position), slug, URI, post/expiry date, status and images (the source side is mapped to the placeholder). A difference rolls everything back (500) |
| Status | Always disabled, as an element and for every site, whatever the source's status. Publishing the draft gives a disabled entry; enabling it is a separate action of the editor. The copy check therefore ignores the element's status, but not the status of each block |
| Structure | Same parent as the source, appended at the end of that level. Drafts are invisible to element queries, so no menu changes until the editor publishes |
| Slug | Supplied by the backend, must already be a normalised slug (422 `invalid_slug`). A slug whose URI a live element or another unpublished draft in that site has answers 409 `slug_taken`; never auto-suffixed |
| Images | One bundled PNG, uploaded once as `rankroute-placeholder.png` into the root of `textFlow.placeholderVolume` or the first volume, found by filename and reused. It replaces every non-empty Assets field at every depth and every asset reference tag in the submitted HTML items |
| Sites | The texts are written in the source's site only. Every other site of the copy is disabled for that site and keeps the source's texts in its own language |
| Retries | `idempotencyKey` as in import, stored with the metadata in the draft notes; a retry answers `replayed: true` |
| Verify | `text/verify` accepts a created draft and checks it against its source in copy mode |

## Alternatives considered

- **Build the new entry field by field from the export.** Would need a serialiser for
  every field type the text flow deliberately never touches (links, relations, forms,
  SEOmatic), which is how the optimizer flow broke buttons. Craft's duplicate copies all of
  it exactly.
- **Auto-suffix a taken slug.** Silently publishes another URL than the one the backend
  chose for the keyword; a taken slug usually means the page exists or a retry.
- **Root level in the structure.** A root-level page is what a main menu built from
  `level(1)` shows once published, and its URI breaks the source's pattern.
- **Copy the new texts into every site.** Would put one language into every site of a
  multi-language section.
- **Keep the source's images.** The editor could miss that an image belongs to another page;
  the placeholder makes every image an explicit task.

## Consequences

- The plugin now creates elements (an entry and its nested entries as an unpublished draft,
  and once per install an asset). It still never publishes.
- `text/create`, its request and response, the error codes `slug_taken`, `invalid_slug`,
  `unsupported_element` and the create draft notes are a contract with the backend.
- A published new page keeps its element id, so the `elementId` in the response stays valid.
- The response carries `enabled` (always `false`), so the backend can report that the page
  is not live yet.
- `config/rankroute.php` gains `textFlow.placeholderVolume`.

## Limits

- **Text that is not an item is copied.** Link labels, button labels, Table cells, text in
  disabled nested entries or excluded fields, URL/number/Twig values: they keep the
  source's value, because the flow cannot know what to put there. The editor reviews them.
- **Images that are not reference tags.** `<img>` with a literal URL, images in HTML that is
  not an item, and SEOmatic's own image settings keep the source's image.
- **Shared nested entries.** A nested entry the source shares from another element is not
  given the placeholder, like import never writes into it.
- **Other sites.** A text field (or a title) whose translation method shares the value
  across sites carries the new text into them (ADR 0003's limit). Their slug is the new
  slug; they are disabled.
- **Idempotency ends at publishing.** The key lives in the draft notes; once the editor
  publishes the page, the same key would create another one (and answer `slug_taken` if
  the URI is still the same).
- **No creator.** Like import drafts, the draft has no creator user, so an editor needs the
  "view other users' drafts" permission for the section (admins always have it).
