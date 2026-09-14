# ADR 0002: Legacy action aliases by registering the plugin under the old module ids

Date: 2026-09-14
Status: proposed — becomes accepted when the Phase 1 integration test passes

## Context

The n8n flows call `/actions/_craft-entry-optimizer/optimized-entry/{export,import}`,
`/actions/_craft-entry-optimizer/optimized-entry` (health) and
`/actions/_craft-seo-import/api/import`. Tensing's flow 1 is active in production. ADR 0001
keeps those paths working through 0.0.x so each site can swap the plugin and update its
flows on different days.

Craft treats any request whose first segment is the action trigger as an action request and
dispatches it with `Application::runAction()` before URL rules are consulted
(`craft\web\Application::_processActionRequest`). Site URL rules therefore cannot alias an
action path.

## Decision

In `Plugin::init()`, register this plugin instance under the two old module ids and map the
old controller ids onto the new controllers:

```php
Craft::$app->setModule('_craft-entry-optimizer', $this);
Craft::$app->setModule('_craft-seo-import', $this);
$this->controllerMap = [
    'optimized-entry' => OptimizerController::class, // export, import, index → status
    'api'             => SeoController::class,       // import
];
```

This relies on two facts verified against the vendored sources on 2026-09-14:

- Craft registers every plugin as a Yii module under its handle
  (`craftcms/cms/src/services/Plugins.php:1300`, `Craft::$app->setModule($plugin->id, $plugin)`),
  so a second id pointing at the same instance is exactly what Craft already does once.
- Yii resolves an action route by checking `controllerMap` on the matched module before
  falling back to the controller namespace (`yiisoft/yii2/base/Module.php:584-606`).

The aliases honour the new auth only: `RANKROUTE_API_KEY`, never the old env vars.

## Alternatives considered

- **Rewrite `Request::$actionSegments` in `Application::EVENT_BEFORE_REQUEST`.** Works but
  reaches into request internals; kept as the fallback if the module-id approach fails the
  Phase 1 test.
- **Keep the old plugins installed alongside.** Same code twice, two keys, and Craft would
  refuse duplicate controller routes anyway.

## Consequences

- An integration test must exercise all legacy paths through the booted app, because the
  behaviour depends on Yii dispatch, not on this plugin's code.
- A site that still has `_craft-entry-optimizer` or `_craft-seo-import` installed alongside
  RankRoute gets an undefined winner for the old ids; the migration runbook uninstalls the
  old plugins first.
- Removal in 0.1.0 is a breaking change and must wait for the six-site migration checklist.
