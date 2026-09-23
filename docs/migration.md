# Migrating a site to RankRoute

Replaces `lameco/craft-entry-optimizer` and/or `lameco/craft-seo-import` with
`lameco/craft-rankroute` on one site. Run the steps in order — step 3 in particular is not
reorderable.

The legacy action paths keep working through the 0.0.x line (ADR 0002), so the plugin swap
and the n8n flow update do not have to happen in the same maintenance window. They do both
have to happen before 0.1.0, which removes the aliases.

> **Tensing flow 1 is active in production.** Update its HTTP nodes during a window in
> which the flow is not executing, and expect the swap to be visible to whoever watches
> that flow.

## 1. Gate — Craft 5.8 or later

```bash
php craft --version
```

RankRoute requires `craftcms/cms ^5.8.0` and PHP 8.2+ (`composer.json`). A site below
Craft 5.8 cannot install the plugin at all: Composer will refuse the require. **Upgrade
Craft first and finish that upgrade as its own piece of work** — do not continue this
runbook on a sub-5.8 site.

## 2. Add `RANKROUTE_API_KEY`

```dotenv
RANKROUTE_API_KEY=your-secret-key
```

One key per site, replacing the two old ones (`ENTRY_OPTIMIZER_API_KEY` and seo-import's
key). Either generate a new value, or reuse one of the two keys the site's n8n flows
already carry. Reusing one still means **the other flow's key changes**, so both n8n HTTP
nodes have to be checked in step 7 regardless.

The key lives in `.env` only. There is no settings UI.

## 3. Uninstall the old plugins — before touching Composer

```bash
php craft plugin/uninstall _craft-entry-optimizer
php craft plugin/uninstall _craft-seo-import
```

Run only the ones the site actually has.

**This must happen before the packages are removed from `composer.json`.** Craft's
uninstall migration is code that ships inside the plugin package. Remove the package
first, and `php craft plugin/uninstall` has nothing left to run: the rows stay behind in
the `plugins` table as orphans, and Craft then reports an installed plugin whose class it
cannot load.

A second reason to uninstall rather than leave them side by side: RankRoute registers
itself under the module ids `_craft-entry-optimizer` and `_craft-seo-import` to serve the
legacy paths. With an old plugin still installed, which module wins those ids is undefined
(ADR 0002).

## 4. Swap the Composer requires

Remove `lameco/craft-entry-optimizer` and `lameco/craft-seo-import` from `require`, and
remove their VCS entries from `repositories`. Add RankRoute in their place, with its VCS
repository entry.

```bash
composer remove lameco/craft-entry-optimizer lameco/craft-seo-import
composer require lameco/craft-rankroute
```

Leaving a stale `repositories` entry behind makes Composer keep polling a repository
nothing requires.

## 5. Install the plugin

```bash
php craft plugin/install rankroute
```

## 6. Smoke test

```bash
curl -s https://<site>/actions/rankroute/optimizer/status
```

Expect `200` with `{"plugin":"RankRoute","status":"active", ...}`. `status` is the one
endpoint that needs no key, so it verifies routing and installation independently of
whether step 2 landed correctly.

Then verify auth is live, with the key from step 2:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://<site>/actions/rankroute/optimizer/export?id=1
# expect 401

curl -s -H "Authorization: Bearer $RANKROUTE_API_KEY" \
  "https://<site>/actions/rankroute/optimizer/export?id=<a-real-element-id>"
# expect a one-element JSON array
```

A `401` with `API key not configured` means step 2 did not take effect (env not reloaded);
`Authentication required` means the key does not match.

## 7. Update the n8n HTTP nodes

Two nodes per site, one per flow:

| Flow | Old URL | New URL |
|---|---|---|
| Flow 1 — Entry Optimizer | `/actions/_craft-entry-optimizer/optimized-entry/export` and `/import` | `/actions/rankroute/optimizer/export` and `/import` |
| Flow 2 — SEO bulk-fill | `/actions/_craft-seo-import/api/import` | `/actions/rankroute/seo/import` |

For each node: change the URL, and change the `Authorization: Bearer` value to the site's
`RANKROUTE_API_KEY`.

**Move the key into an n8n credential instead of a plain header parameter.** Today every
flow copy embeds the secret inline in the node, which means the key is duplicated per site
and per flow copy, and is visible to anyone who can open the flow. A credential is set
once and referenced.

Because the legacy aliases still answer in 0.0.x, an un-updated node keeps working — which
is exactly why this step gets ticked off explicitly rather than assumed.

## 8. Tick the checklist

## Site checklist

| Site | Old plugins | 1. Craft ≥ 5.8 | 2. Key | 3. Uninstall | 4. Composer | 5. Install | 6. Smoke | 7. n8n nodes |
|---|---|---|---|---|---|---|---|---|
| tensing | entry-optimizer (**flow 1 active**), seo-import | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| boomfeestdag | entry-optimizer, seo-import | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| qtc | entry-optimizer, seo-import | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| sailwise | entry-optimizer, seo-import | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| esthec | entry-optimizer, seo-import | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |
| krt | seo-import only | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ |

Confirm per site which of the two plugins is actually installed before running step 3 —
the "old plugins" column records the expectation, not a verified inventory.

**0.1.0 is blocked until every row is complete.** That release removes the legacy aliases,
and any flow still pointing at an old path breaks the moment it ships.

## Before switching a site to the text flow

The text flow (ADR 0003) is a separate step from the plugin swap above. The n8n flows keep
using the optimizer endpoints until the site is switched; the RankRoute backend is the only
client of the text endpoints. Per site, in order:

1. **Install a RankRoute release that contains the text flow.** Check with
   `curl -s https://<site>/actions/rankroute/optimizer/status`: `endpoints` must list
   `textExport`, `textImport` and `textVerify`.
2. **Set `RANKROUTE_API_KEY`** (step 2 above), if the site does not have it yet.
3. **Review `config/rankroute.php`.** The defaults exclude `*url`, `*webhook*`, `importId`,
   `*Id`, `llmContent`, `cocNumber` and nested entries of type `*button*`. Add the site's
   own non-content text fields and button-like entry types before the smoke run.
   On a multi-site install, check that content text fields and the SEOmatic field are
   translatable per site: text shared between sites is not exported, so it is not
   optimised (ADR 0003, Limits). Only on an install where sites share a language on
   purpose is `textFlow.exportSharedText => true` an option; on a multilingual site it
   would let a rewrite in one language overwrite the others.
4. **Run the smoke command** on the site's server:

   ```bash
   php craft rankroute/text-flow/smoke --sample=5
   ```

   It calls the site over HTTP; pass `--base-url` when the server cannot reach its public
   host. It creates a draft per element and deletes it again, except the sampled ones.
5. **Require zero failures** (exit code `0`). Rerun a failing element with
   `--uri=<uri> --verbose` to see the response or the structure differences. Fix the cause
   (usually an exclude pattern) and run the full command again. Do not switch a site with
   any failure left.
6. **Check the sampled drafts in a browser** against the live page: buttons, links, forms
   and images must be identical. Delete the sampled drafts afterwards.
7. **Point the RankRoute backend at the site** with its base URL and key, and turn off the
   site's n8n optimizer flow so the two clients do not both write drafts.
