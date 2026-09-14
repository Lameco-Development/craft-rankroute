# ADR 0001: One plugin replaces craft-entry-optimizer and craft-seo-import

Date: 2026-09-14
Status: accepted

## Context

RankRoute, Laméco's SEO content pipeline in n8n, drives two Craft plugins per client site:
`lameco/craft-entry-optimizer` 1.0.7 (export → AI rewrite → import as draft) and
`lameco/craft-seo-import` 1.0.4 (bulk write of SEOmatic meta title/description). Six sites
run one or both. Each plugin has its own API key, its own URL-to-site resolution code, and
its own SEOmatic handling; seo-import hard-requires SEOmatic while entry-optimizer detects
it at runtime. Neither has tests.

## Decision

Ship one plugin, `lameco/craft-rankroute` (handle `rankroute`, namespace
`lameco\rankroute`), whose 0.0.1 is a merge of the two released plugins and nothing more.
Decisions taken for the merge:

| | Decision |
|---|---|
| Endpoints | New `/actions/rankroute/optimizer/{export,import,status}` and `/actions/rankroute/seo/import`; the old action paths stay as aliases through 0.0.x (ADR 0002) |
| Auth | One `RANKROUTE_API_KEY`, `Authorization: Bearer`, no CP-session fallback, 401 when unset. `status` stays public |
| Write model | Optimizer import → draft with only changed fields. Bulk meta → live save. As before |
| Element resolution | Both flows share one resolver: longest site base-path prefix, then `Elements::getElementByUri()`. Bulk meta therefore becomes multi-site and element-type-agnostic (it was primary-site, entries only) |
| SEOmatic | Optional, detected at runtime. Dev dependency only, so integration tests cover the bulk write |
| Bulk items without `url` | Reported in `skipped` (`No url provided`) instead of dropped silently |
| Out of scope | entry-optimizer's unreleased `feature/entry-creation-bulk-craft6` branch (create-from-reference, bulk, queue, Craft 6) — candidate for 0.1.0 |
| Scaffold | As `craft-dash-dam`: PHPUnit unit + integration with a hand-rolled Craft boot, GitHub CI, release-please, MIT |

## Consequences

- One key per site and one plugin to install; n8n flows change once per site, and the
  aliases mean they need not change on the same day as the plugin swap.
- The bulk meta flow changes behaviour on multi-site installs: a URL under `/nl/` now hits
  the `nl` site instead of the primary one. Recorded in the CHANGELOG.
- The `entryId` key in the optimizer import response stays, even for non-entry elements,
  because n8n reads it.
- `phpstan/phpstan` is an explicit dev dependency: `craftcms/phpstan@dev-main` only
  suggests it now.
