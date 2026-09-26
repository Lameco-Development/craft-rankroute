# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Craft CMS 5 plugin, PHP 8.2+ (Composer platform pinned to 8.4). Handle `rankroute`, package `lameco/craft-rankroute`, namespace `lameco\rankroute`. No frontend build, no Twig — the plugin is an HTTP API for the RankRoute n8n flows and the RankRoute backend.

## Commands

```bash
composer check-cs   # ECS, dry run
composer fix-cs     # ECS, applying fixes
composer phpstan    # PHPStan level 4 (config in phpstan.neon)
composer test       # PHPUnit — unit tests in tests/unit, integration tests in tests/integration
```

Integration tests boot a real Craft app against a MySQL database named in `tests/.env` (copy `tests/.env.example`); the harness drops every table in it, so the name must contain `test`.

## Repo operations

- PRs are squash-merged into `main`. The PR title must be a Conventional Commit (`feat:`/`fix:`/`refactor:`/`test:`/`chore:`/`ci:`/`docs:`) — it becomes the squash commit message that release-please reads.
- release-please owns versioning, tags, releases, and `CHANGELOG.md`; never edit those by hand. The first release is `0.0.1` (pinned via `initial-version` in `release-please-config.json`).
- CI (`.github/workflows/ci.yml`) runs ECS, PHPStan and the full PHPUnit suite (unit + integration, MySQL service container) on PHP 8.2 and 8.4, on PRs and pushes to `main`.

## What this plugin is

One plugin replacing two: `lameco/craft-entry-optimizer` (export an element to JSON, import AI-edited JSON back as a draft with only the changed fields) and `lameco/craft-seo-import` (bulk-write SEOmatic meta title/description from `[{url, meta_title, meta_description}]`). The decisions behind the merge are in `docs/adr/0001-one-plugin-replaces-two.md`; the vocabulary is in `CONTEXT.md`.

## Architecture (target, filled in per phase)

- `controllers/OptimizerController` — `export`, `import`, `status`
- `controllers/SeoController` — `import` (bulk meta)
- `services/ElementResolver` — URL or path → `{siteId, uri}`: a full URL only matches sites on its host, then longest site base-path prefix, then `Elements::getElementByUri()`
- `services/ExportService`, `services/ImportService`, `services/FieldHandlerRegistry`, `services/fieldhandlers/*` — carried over from entry-optimizer
- `services/SeoBulkService` — carried over from seo-import's controller
- `dto/*` — readonly result objects with `toArray()`
- `controllers/TextController`: `export`, `import`, `create`, `verify`, `templates` (the text flow, ADR 0003; `create` ADR 0004; `templates` `docs/plans/2026-09-26-text-templates.md`)
- `services/TextExportService`, `services/TextImportService`: text items out; validated strings into a draft via Craft's delta Matrix format, then the structure check. Text a field's or title's translation method shares with another site of the element is not an item, unless `textFlow.exportSharedText` is on
- `services/TextCreateService`: a new page from a source entry: validate like import, `duplicateElement` as unpublished draft (always disabled, in every site), write the strings and the placeholder image, structure check in copy mode, 409 on a taken slug
- `services/TextTemplatesService`: `text/templates`, read only: per site the section × entry type kinds `text/create` can copy (`TextCreateService::isCopyableSection()`/`isCopyable()`), live entry count, up to 3 newest live samples with a URL
- `services/text/*`: `TextExtractor` (element → text items and non-empty Assets fields, `config/rankroute.php` excludes), `TextAddress`, `HtmlSkeleton`, `TextImportValidator`, `TextWriter` (values at addresses onto a draft, shared by import and create), `StructureSnapshot` (+ copy mode), `StructureCheck`, `Fingerprint`, `PlaceholderImage` (bundled `src/resources/rankroute-placeholder.png`, uploaded once), `SmokeRewrite`
- `console/controllers/TextFlowController`: `rankroute/text-flow/smoke`, the per-site gate before the text flow is enabled

Legacy action paths (`/actions/_craft-entry-optimizer/optimized-entry/*`, `/actions/_craft-seo-import/api/import`) are served by this plugin through the 0.0.x line, see `docs/adr/0002-legacy-action-aliases.md`. They and the `optimizer/*` endpoints stay unchanged for the n8n flows; the text flow has no legacy alias and its only client is the RankRoute backend (Laravel, separate `rankroute` repo).

## Auth

Every endpoint except `status` requires `Authorization: Bearer <RANKROUTE_API_KEY>`. No CP-session fallback. The key lives in `.env` only; there is no settings UI.

## Domain docs

Single-context: `CONTEXT.md` + `docs/adr/` at the repo root. Add an ADR when a decision changes a boundary or a contract that n8n depends on.
