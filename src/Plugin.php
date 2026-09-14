<?php

namespace lameco\rankroute;

use Craft;
use craft\base\Plugin as BasePlugin;
use lameco\rankroute\controllers\OptimizerController;
use lameco\rankroute\controllers\SeoController;
use lameco\rankroute\services\ExportService;
use lameco\rankroute\services\FieldHandlerRegistry;
use lameco\rankroute\services\ImportService;
use lameco\rankroute\services\SeoBulkService;

/**
 * RankRoute connector: element export/import for AI content optimisation and bulk SEO
 * meta import, one plugin replacing craft-entry-optimizer and craft-seo-import.
 *
 * @method static Plugin getInstance()
 * @property-read FieldHandlerRegistry $fieldHandlerRegistry
 * @property-read ExportService $exportService
 * @property-read ImportService $importService
 * @property-read SeoBulkService $seoBulkService
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'fieldHandlerRegistry' => ['class' => FieldHandlerRegistry::class],
                'exportService' => ['class' => ExportService::class],
                'importService' => ['class' => ImportService::class],
                'seoBulkService' => ['class' => SeoBulkService::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Legacy action paths from craft-entry-optimizer and craft-seo-import keep
        // dispatching through this plugin until each site's n8n flows migrate (ADR 0002).
        Craft::$app->setModule('_craft-entry-optimizer', $this);
        Craft::$app->setModule('_craft-seo-import', $this);
        $this->controllerMap = [
            'optimized-entry' => OptimizerController::class,
            'api' => SeoController::class,
        ];
    }
}
