# ADR 0003: A text flow that can only change text

Date: 2026-09-16
Status: accepted

## Context

The optimizer flow exports a whole element, lets an LLM rewrite the whole document and
imports the whole document back as a draft. On Aztec that broke buttons. Three causes, all
in code carried over from `craft-entry-optimizer`:

- `MatrixFieldHandler::import()` recreates every nested entry (`new1..N`) instead of
  updating the existing ones, so nested entries lose their identity and anything the
  handler does not round-trip is lost with them.
- Link fields are exported as flat strings, so a button's link cannot survive the round
  trip intact.
- Nothing enforces that only text changes. The model receives buttons, links, assets and
  block structure, and whatever it returns is written.

Optimising a page must never change buttons, links, images, forms or block structure. That
guarantee cannot be added to a flow whose contract is "send back the whole element".

## Decision

Add a **text flow** next to the optimizer flow, with its own endpoints
(`/actions/rankroute/text/{export,import,verify}`) and its own client.

| | Decision |
|---|---|
| Extraction | Craft extracts the text items (PlainText, CKEditor, Redactor, editable native titles, literal SEOmatic meta title/description), each with an address such as `pageBuilder[3].content`. Extraction lives in Craft, not in the client, because only Craft knows field types, field layouts and entry types |
| Client contract | The client gets `{element, fingerprint, items}` and returns only `{id, value}` pairs plus the fingerprint. It never sees or sends anything but strings |
| Write model | Only the changed strings are written, into a new draft. Nested entries are written through Craft's delta Matrix format (`{entries: {<id>: {...}}, sortOrder: [<all ids>]}`), so every nested entry keeps its identity; never `new*` keys. The flow never publishes |
| Validation | Strict and all-or-nothing, before anything is written: fingerprint equal to the current one (409), the submitted id set equal to the current id set, non-empty values within `maxLength`, no template or env syntax introduced into SEO meta, no Twig, nothing markup-like in plain items, reference tags unchanged byte for byte, an identical tag skeleton in HTML items including comments and raw-text element content, and every changed nested entry owned by the element (422). The rules are shared with the RankRoute backend (`docs/plans/2026-09-16-review-fixes.md`, R1-R6) |
| Retries | An optional `idempotencyKey` is stored in the draft's notes with the changed items. A request with a key that already produced a draft of that element in that site writes nothing and answers from that draft (`replayed: true`, structure check re-run) (R7) |
| Structure check | Every import compares a structure snapshot (everything except extractable text) of the canonical element with the saved draft. A difference rolls back the draft transaction and answers 500. `text/verify` runs the same check for an existing draft |
| Gate per site | `php craft rankroute/text-flow/smoke` runs export, deterministic rewrite, import, structure check, verify and negative probes for every live element over HTTP. Zero failures is the condition for pointing the client at a site |
| Client | A new RankRoute backend, a Laravel app in the separate repo `rankroute`, replaces the n8n flows and is the only client of the text endpoints |
| Old endpoints | `optimizer/*`, `seo/import` and the legacy aliases are not changed. n8n keeps using them until each site moves to the backend. They will be deprecated later; no date is set |

Where the implementation refined the plan (`docs/plans/2026-09-16-text-flow.md`): native
titles carry `maxLength` 255, Twig in a submitted value is its own code (`twig_in_value`),
the tag skeleton compares attributes order-insensitively, `level` is not part of the
snapshot (drafts have none), and a failed structure check rolls back instead of deleting
the draft.

After an independent security review (`docs/plans/2026-09-16-review-fixes.md`):

- SEO meta may not gain `{`, `}`, `$` or a leading `@` (`forbidden_syntax`): SEOmatic runs
  meta through `Craft::parseEnv()` and object templates, so `${DB_PASSWORD}` or
  `{author.email}` would leak.
- Reference tags (`{user:1:email}`, `{entry:6@1:url||…}`) must stay byte for byte
  (`reference_tag_changed`), and import validation compares the fallback URL strictly.
  Only the canonical-vs-draft snapshot ignores the fallback, because Craft rewrites it on
  save.
- Comments end the way browsers end them and are compared with their content, as are
  CDATA, doctypes, bogus comments and the content of raw-text elements, so no markup can
  be smuggled through a comment boundary or a `<script>`.
- Plain values reject `<` followed by a letter, `/`, `!` or `?`, closed or not.
- Text in a nested entry whose primary owner is another element is not extracted, and the
  writer refuses it (`shared_nested_entry`), so an import can never edit another page.
- Export by id only finds top-level entries, categories and Commerce products; a `500`
  answers a reference, not the exception detail.

## Alternatives considered

- **Fix `MatrixFieldHandler` and the link export in the optimizer flow.** Needed for the
  optimizer flow on its own terms, but still lets the model return non-text data, so it
  does not give the guarantee.
- **Extract text in the client from the optimizer export.** The client would have to
  re-derive what is text from serialised values without field types or layouts, and the
  import would still accept a whole element.

## Consequences

- The export and import shapes, the addresses, the error codes and the structure check
  response are a contract with the RankRoute backend; changing them is a breaking change.
- Some text cannot be optimised: empty fields (the model must not add content), values
  that are a URL, e-mail address, number or contain Twig, link labels, Table cells, fields
  whose handle matches `excludeFields`, anything inside a nested entry whose type matches
  `excludeEntryTypes` or that is disabled, and text inside CKEditor nested entries. Those
  fail safe: the `<craft-entry>` tag is part of the tag skeleton and the rest of the
  structure is compared in full.
- `config/rankroute.php` (`textFlow.excludeFields`, `textFlow.excludeEntryTypes`,
  case-insensitive fnmatch patterns) tunes extraction per site. Still no settings UI.
- Any edit to the element between export and import, text or structure, changes the
  fingerprint; the client must export again after a 409.
- `craftcms/ckeditor` is a new dev dependency, so integration tests cover HTML items.
  CKEditor and Redactor stay optional at runtime (detected by class name).
- Every site runs the smoke command with zero failures before the backend is pointed at it.
  The command creates and deletes drafts on that site; it only ever deletes drafts of the
  element it is testing, found by draft id and canonical id, or by its idempotency key
  after a failed import.
- A retry is safe only with an `idempotencyKey`. The replay answers the changed items as
  stored with the key, not a new diff: after a save, Craft rewrites reference tag
  fallbacks and purifies HTML, so diffing canonical and draft text would report items the
  import never touched.

## Limits

- **Target site only.** Validation, the write and the structure check cover the site of the
  request. A draft exists in every site of the element, but its other sites are not
  compared.
- **Non-translatable text fields propagate.** A text field whose translation method shares
  its value across sites is written in the target site, and applying the draft changes
  every site sharing that value. The flow does not detect this; sites should keep content
  fields translatable per site (lameco.nl does).
- **Unreadable values.** A field value the snapshot cannot read (the field throws) is
  recorded as its exception class plus a hash of the message. Two identical failures on
  canonical and draft still compare equal, so a field that always throws is not checked.
- **CKEditor nested entries.** Changing the text of a CKEditor field that contains
  `<craft-entry>` can make CKEditor duplicate those entries into the draft; the changed
  `data-entry-id` then fails the structure check. Safe, but such a field cannot be
  optimised.
