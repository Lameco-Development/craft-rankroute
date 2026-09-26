# Plan: `text/templates`, the kinds of page a new page can be copied from

Status: implemented on branch `feat/text-templates`. Follow-up of
`2026-09-22-new-page-draft.md` (ADR 0004); requested by the RankRoute backend's design for
template previews (`rankroute/docs/plans/2026-09-26-sjabloon-previews.md`).

## Problem

`text/create` copies an existing entry, the source, which the customer picks. To help the
customer pick, RankRoute wants to show one preview per kind of page (section × entry type)
and needs to know which kinds exist, how common each is, and a few real pages of each kind
to render. Nothing in the text flow answers that: `text/export` works on one known element.

## Approach

A fifth text flow endpoint, `GET text/templates[?siteId=<id>]`, read only, behind the same
API key check as the other text endpoints (`TextController::runAction()`).

- **What is listed = what `text/create` accepts.** `TextCreateService::isCopyable()` is split
  into the element rule (an entry, not nested) and `isCopyableSection()` (not a single). The
  listing uses the section rule for sections and runs every sample through `isCopyable()`,
  so the two cannot drift apart.
- **Per site.** Only sections whose site settings for that site have URLs; per section each
  entry type with at least one live entry with a URL in that site. A kind whose live entries
  all lack a URL is left out: there is nothing to preview.
- **`liveEntries`** counts live top-level entries of that section and entry type in that
  site (Craft's `live` status; drafts, revisions and trashed entries are left out by the
  element query, nested entries by the section filter).
- **`samples`**: at most 3 live entries with a non-empty URI and URL, `postDate` descending,
  then id descending.
- **Order**: most live entries first, then section name, then entry type name
  (`strnatcasecmp`), then the handles, so the order is stable. `TextTemplatesService::sort()`
  is pure and unit tested.
- **`siteId`**: optional, the primary site when omitted or empty; anything that is not only
  digits or not the id of a site answers `400 {"error": "…"}`.

The contract is in the README ("Text templates"). `status` lists it as `textTemplates`.

## Components

- `services/TextTemplatesService` (`templates()`, `sort()`), registered as
  `textTemplatesService`.
- `TextCreateService::isCopyableSection()`.
- `TextController::actionTemplates()`; `OptimizerController::actionStatus()` lists
  `textTemplates`.

## Tests

- Unit: `TextTemplatesSortTest` (order by count, names, handles).
- Integration `TextTemplatesTest` on the text flow fixture plus a channel `news` (URLs in
  the primary site only), a channel without URLs and a single: counts and samples of a
  channel and a structure (newest first, at most 3, only live, only with a URL); disabled,
  pending, expired, trashed and draft entries do not count; an entry type with only a
  disabled entry is not listed; nested entries of a shared entry type do not count; singles
  and sections without URLs are not listed; the nl site gets its own titles and URLs and
  loses `news`; no `siteId` = the primary site; unknown or malformed `siteId` → 400; nothing
  is written. `TextFlowTest` covers the 401s (missing and wrong key, and the rejection as
  the action's own response) for `templates` with the other text endpoints.
