# Text flow smoke results: lameco.nl (local)

Date: 2026-09-16. Branch `feat/text-flow` (uncommitted working tree on top of `4047a6d`),
installed as a Composer path repo (symlink) into `~/Sites/lameco-craft-website`
(Craft 5.10.5, SEOmatic, CKEditor, Formie, Blitz), served by Valet.

## Re-run after the review fixes

After implementing `docs/plans/2026-09-16-review-fixes.md` (R1-R7) and the follow-ups
(smoke cleanup scoped to the element, export element check, idempotency key on every smoke
import, shared nested entry guard, generic 500 body, export by id limited to page
elements, unreadable snapshot values hashed), same site, same command:

```bash
php craft rankroute/text-flow/smoke --probe=5 --verbose
```

Duration 8m53s (13:02 to 13:11). Exit code 0.

**288 elements: 288 passed, 0 skipped, 0 failed.** 4,229 text items rewritten and
imported, identical to the first run, so neither the plain-markup exclusion nor the shared
nested entry exclusion removed an item on this site.

- Probes on the first 5 elements (`cookie-consent`, `error`, `__home__`, `contact`,
  `cases`): changed href (where an HTML item has one), missing id, stale fingerprint, and
  the new replay probe (same `idempotencyKey` again: `200`, `replayed: true`, same
  `draftId`) all as expected.
- Drafts: 90 before and 90 after (the baseline grew from 84 to 90 through other work
  between the runs; two of those are backend e2e drafts with `rankroute:` notes, created at
  11:02, left untouched). Elements: 15,182 before and after.
- Web log: no `web.ERROR` lines and no `Text flow request failed` entries during the run.

## First run

```bash
php craft rankroute/text-flow/smoke --probe=5 --verbose
```

All three sites, base URLs from the sites themselves (no `--base-url` needed:
`http://lameco-craft-website.test`, `…/en`, `http://jobs.lameco-craft-website.test`).
Duration 9m17s. Exit code 0.

**288 elements: 288 passed, 0 skipped, 0 failed.** 4,229 text items rewritten and imported.
No fixes were needed; the plugin code was not changed.

| Site | Section / entry type | Processed | Imported (passed) | Skipped | Failed | Items |
|---|---|---:|---:|---:|---:|---:|
| nl | homePage / homePage | 1 | 1 | 0 | 0 | 34 |
| nl | contentPages / servicePage | 8 | 8 | 0 | 0 | 263 |
| nl | contentPages / contentPage | 14 | 14 | 0 | 0 | 356 |
| nl | landingPages / contentPage | 17 | 17 | 0 | 0 | 392 |
| nl | aipages / contentPage | 1 | 1 | 0 | 0 | 3 |
| nl | thanksPages / contentPage | 7 | 7 | 0 | 0 | 50 |
| nl | blogOverviewPage | 1 | 1 | 0 | 0 | 6 |
| nl | blogPages / blogPage | 93 | 93 | 0 | 0 | 1133 |
| nl | casesOverviewPage | 1 | 1 | 0 | 0 | 5 |
| nl | casePages / casePage | 13 | 13 | 0 | 0 | 338 |
| nl | eventsOverviewPage | 1 | 1 | 0 | 0 | 6 |
| nl | eventPages / eventPage | 6 | 6 | 0 | 0 | 99 |
| nl | formPages / formPage | 2 | 2 | 0 | 0 | 10 |
| nl | multiStepFormPages | 1 | 1 | 0 | 0 | 3 |
| nl | podcastOverviewPage | 1 | 1 | 0 | 0 | 5 |
| nl | contactPage | 1 | 1 | 0 | 0 | 6 |
| nl | errorPage | 1 | 1 | 0 | 0 | 6 |
| nl | cookieConsentPage | 1 | 1 | 0 | 0 | 1 |
| nl | llmSettings (`llms.txt`) | 1 | 1 | 0 | 0 | 1 |
| en | blogOverviewPage | 1 | 1 | 0 | 0 | 8 |
| en | blogPages / blogPage | 73 | 73 | 0 | 0 | 770 |
| en | casesOverviewPage | 1 | 1 | 0 | 0 | 3 |
| en | casePages / casePage | 20 | 20 | 0 | 0 | 526 |
| en | landingPages / contentPage | 2 | 2 | 0 | 0 | 42 |
| en | multiStepFormPages | 1 | 1 | 0 | 0 | 2 |
| en | podcastOverviewPage | 1 | 1 | 0 | 0 | 1 |
| en | contactPage | 1 | 1 | 0 | 0 | 3 |
| en | errorPage | 1 | 1 | 0 | 0 | 1 |
| en | cookieConsentPage | 1 | 1 | 0 | 0 | 1 |
| en | llmSettings (`llms.txt`) | 1 | 1 | 0 | 0 | 1 |
| jobsNl | homePage | 1 | 1 | 0 | 0 | 12 |
| jobsNl | contentPages / contentPage | 3 | 3 | 0 | 0 | 89 |
| jobsNl | thanksPages / contentPage | 2 | 2 | 0 | 0 | 8 |
| jobsNl | vacancyOverviewPage | 1 | 1 | 0 | 0 | 5 |
| jobsNl | vacancyPages / vacancyPage | 2 | 2 | 0 | 0 | 26 |
| jobsNl | vacancyApplyPage | 1 | 1 | 0 | 0 | 1 |
| jobsNl | contactPage | 1 | 1 | 0 | 0 | 5 |
| jobsNl | errorPage | 1 | 1 | 0 | 0 | 6 |
| jobsNl | cookieConsentPage | 1 | 1 | 0 | 0 | 1 |
| jobsNl | llmSettings (`llms.txt`) | 1 | 1 | 0 | 0 | 1 |
| **total** | | **288** | **288** | **0** | **0** | **4229** |

Every passed element went through export, import (all items changed), structure check in the
import response, `text/verify` on the draft, and draft deletion.

After the run: draft count back to its baseline (84), no orphaned nested entries, no new
elements left behind.

### Web log

No `ERROR` lines in `storage/logs/web-2026-09-16.log` during the run. The only warnings
are `craft\web\View::createTwig: Twig instantiated before Craft is fully initialized`, which
this site logs on every request (also before the plugin was installed).

## Probes

| Element | Items | changed href → 422 `html_structure_changed` | missing id → 422 `missing_id` | stale fingerprint → 409 `fingerprint_mismatch` |
|---|---:|---|---|---|
| nl `cookie-consent` | 1 | n/a (no HTML item with href) | ok | ok |
| nl `error` | 6 | ok | ok | ok |
| nl `__home__` | 34 | n/a (no HTML item with href) | ok | ok |
| nl `contact` | 6 | ok | ok | ok |
| nl `cases` | 5 | n/a (no HTML item with href) | ok | ok |
| nl `wat-we-doen/webdevelopment/applicaties` (extra run) | 45 | ok | ok | ok |
| jobsNl `__home__` (extra run) | 12 | ok | ok | ok |
| en `insights/articles/how-do-i-get-stars-reviews-in-google` (extra run) | 21 | ok | ok | ok |

## Rendered comparison (live vs draft preview)

Drafts created with `--site=<s> --uri=<u> --sample=1 --probe=0` per page (a chosen mix
instead of a random sample), then compared in Chrome. Collected from `main`: `a[href]`
(href, text, target), `button` (text, type), `form` (action, input name:type), `img`
(src, srcset, alt), `iframe` src, `video` src, top-level children of `main`, and the full
text of `main`. Normalisation: ✓ markers removed, and Craft's preview `token` query
parameter removed from hrefs.

| Page | Site / type | Blocks | Links | Buttons | Forms | Images | Iframes | Text (minus ✓) | ✓ in main |
|---|---|---|---|---|---|---|---|---|---:|
| `/` | nl homePage | 14 = | 26 = | 2 = | 0 = | 40 = | 4 = | = | 31 |
| `/wat-we-doen/webdevelopment/applicaties` | nl servicePage (contentPages) | 19 = | 9 = | 1 = | 0 = | 5 = | 0 = | = | 48 |
| `/insights/artikelen/hoe-krijg-ik-sterren-of-beoordelingen-in-google` | nl blogPage | 4 = | 21 = ¹ | 3 = | 1 = | 11 = | 0 = | = | 29 |
| `/cases/lameco-ip-parking` | nl casePage | 10 = | 6 = | 1 = | 0 = | 5 = | 0 = | = | 29 |
| `/ai-eindhoven` | nl landingPage | 17 = | 6 = | 1 = | 0 = | 4 = | 0 = | = | 37 |
| `/insights/evenementen/ai-summerschool` | nl eventPage | 6 = | 19 = ² | 22 = | 0 = | 56 = ³ | 1 = | = | 20 |
| `/90-day-plan-workshop` | nl formPage (Formie) | 1 = | 0 = | 1 = | 1 = | 0 = | 2 = ⁴ | = | 3 |
| jobs `/vacatures/frontend-developer` | jobsNl vacancyPage | 5 = | 4 = ² | 0 = | 0 = | 2 = | 0 = | = | 38 |

`=` means identical after normalisation. Footnotes are differences that are not caused by
the text flow:

1. Share links (`mailto:?subject=…`, `x.com/intent/post?text=…`) are built from the entry
   title, so they carry the rewritten title. Expected: the title is a text item.
2. Template links built from `entry.id` (`/events/<id>.ics`, `solliciteren?vacancyId=<id>`)
   show the draft element id in preview (18131 → 24663, 15090 → 24673). Preview artefact:
   after applying the draft, the canonical id is used again.
3. Google Maps tiles are loaded asynchronously in a different order; same set of `img`
   count, not content.
4. reCAPTCHA iframe URL has a random `cb=` parameter per page load.

Screenshots (viewport 1440×1000, applicaties page header with a `<br>` + `<strong>` in
`headerTitle`, buttons below it unchanged):
`docs/plans/smoke-screenshots/applicaties-live.png`,
`docs/plans/smoke-screenshots/applicaties-draft.png`.

The eight kept drafts (draft ids 8081, 8095, 8112, 8128, 8140, 8156, 8165, 8166) were hard
deleted afterwards.

## Manual export review

Checked `/`, `/wat-we-doen/webdevelopment/applicaties` (entry 51) and
`/insights/artikelen/hoe-krijg-ik-sterren-of-beoordelingen-in-google` (entry 3542) against a
dump of every field of the element tree.

- No leaks: no Link, Dropdown, Lightswitch, Assets, Entries relation, Table or Formie value
  is an item. `button` nested entries (`linkType`, `linkText`) and the
  `headerButtonOverride` Link field are absent. Disabled nested entries
  (`condensedCtaBlock`, disabled buttons) are absent. `importId` (value `10389`) is
  excluded.
- Nothing visible missing: `headerTitle`, `headerContent`, block `title`/`tag`/`content`,
  `stepItem` title/label/description, `iconCard` title/description, `listedContentItem`
  title/description/content (with `<a href="{entry:…}">` intact), `imageBlock.caption`
  and SEO title/description are all present. Nested entries without a title field
  (`clientsBlock`, `imageBlock`) correctly have no `title` item.
- HTML inline attributes (`style="margin-left:0px;"`, `class="text-intro"`) are kept in the
  skeleton; the smoke confirms they survive the round trip.

## Fixes made

None for the first run. The first full run had zero failures, so `HtmlSkeleton`, `TextImportValidator`,
`StructureSnapshot` and the smoke checks are unchanged. `composer check-cs`,
`composer phpstan` and `composer test` (182 tests, 638 assertions) are green on the
working tree.

## Open judgment calls

Defaults (`excludeFields`, `excludeEntryTypes: ['*button*']`) fit this site: the only
button-like entry types (`button`, `headerOverlayButton`, whose `label` is a button text)
are excluded. Fields worth a decision:

- `casePage.clientName`, `quoteEntry.clientName`: proper nouns. Rewriting a client name is
  never an improvement. Candidate for `excludeFields`.
- `stepItem.label` ("1 dagdeel, 3 of 5 dagen"): factual (durations, scope). Visible, but a
  model may alter facts.
- `eventPage.venueName`, `parking`, `publicTransport`, `doorsOpenNote`,
  `eventPageProgrammeItem.programmeNote`: practical/factual info, not SEO copy.
- `*.tag`: short eyebrow labels above headings. Visible, fine to keep.
- `imageBlock.caption`, `videoBlock.caption`: visible captions, fine to keep.
- `aiCtaBlock.inputPlaceholder`: UI string of the chat input (empty on the pages checked).
  `aiCtaBlock.initialMessages` (Table) is never an item, so the chat's opening messages
  cannot be optimised.
- `llmSettings` (`llms.txt`), `errorPage`, `cookieConsentPage`: each yields only a title
  item. Rewriting the `llms.txt` entry title or the error page title has no SEO value; the
  backend should probably skip these sections. `excludeEntryTypes` only applies to nested
  entries, so there is no config knob for top-level types today.
- `globalSettings.vacancyCtaButton` is a PlainText button label; globals are not exported,
  but if that ever changes it needs an exclude.

## Notes for other sites

- Here every text field is `translationMethod: site` and nested Matrix fields use
  `propagationMethod: none`, so a draft in one site cannot leak text into another. The text
  flow does not guard against non-translatable text fields; on a site where a text field is
  shared across sites, applying an `en` draft would also change `nl`.
- SEOmatic cannot read meta in a Craft bootstrap without the `@webroot` alias (standalone
  scripts): `seoMeta()` then returns null and the SEO items disappear. HTTP requests and
  `php craft` are unaffected.
