<?php

namespace lameco\rankroute;

use craft\base\Plugin as BasePlugin;

/**
 * RankRoute connector: element export/import for AI content optimisation and bulk SEO
 * meta import, one plugin replacing craft-entry-optimizer and craft-seo-import.
 *
 * @method static Plugin getInstance()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
}
