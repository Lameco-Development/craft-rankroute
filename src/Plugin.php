<?php

namespace lameco\rankroute;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use lameco\rankroute\controllers\OptimizerController;
use lameco\rankroute\controllers\SeoController;
use lameco\rankroute\services\ElementResolver;
use lameco\rankroute\services\ExportService;
use lameco\rankroute\services\FieldHandlerRegistry;
use lameco\rankroute\services\fieldhandlers\AssetFieldHandler;
use lameco\rankroute\services\fieldhandlers\DefaultFieldHandler;
use lameco\rankroute\services\fieldhandlers\DropdownFieldHandler;
use lameco\rankroute\services\fieldhandlers\LinkFieldHandler;
use lameco\rankroute\services\fieldhandlers\MatrixFieldHandler;
use lameco\rankroute\services\fieldhandlers\RelationFieldHandler;
use lameco\rankroute\services\fieldhandlers\SeomaticFieldHandler;
use lameco\rankroute\services\ImportService;
use lameco\rankroute\services\SeoBulkService;
use lameco\rankroute\services\text\Fingerprint;
use lameco\rankroute\services\text\StructureCheck;
use lameco\rankroute\services\text\StructureSnapshot;
use lameco\rankroute\services\text\TextExtractor;
use lameco\rankroute\services\TextExportService;
use lameco\rankroute\services\TextImportService;

/**
 * RankRoute connector: element export/import for AI content optimisation and bulk SEO
 * meta import, one plugin replacing craft-entry-optimizer and craft-seo-import.
 *
 * @method static Plugin getInstance()
 * @property-read FieldHandlerRegistry $fieldHandlerRegistry
 * @property-read ExportService $exportService
 * @property-read ImportService $importService
 * @property-read SeoBulkService $seoBulkService
 * @property-read ElementResolver $elementResolver
 * @property-read TextExtractor $textExtractor
 * @property-read StructureSnapshot $structureSnapshot
 * @property-read StructureCheck $structureCheck
 * @property-read Fingerprint $textFingerprint
 * @property-read TextExportService $textExportService
 * @property-read TextImportService $textImportService
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
                'elementResolver' => ['class' => ElementResolver::class],
                'textExtractor' => ['class' => TextExtractor::class],
                'structureSnapshot' => ['class' => StructureSnapshot::class],
                'structureCheck' => ['class' => StructureCheck::class],
                'textFingerprint' => ['class' => Fingerprint::class],
                'textExportService' => ['class' => TextExportService::class],
                'textImportService' => ['class' => TextImportService::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lameco\\rankroute\\console\\controllers';
        }

        // Legacy action paths from craft-entry-optimizer and craft-seo-import keep
        // dispatching through this plugin until each site's n8n flows migrate (ADR 0002).
        Craft::$app->setModule('_craft-entry-optimizer', $this);
        Craft::$app->setModule('_craft-seo-import', $this);
        $this->controllerMap = [
            'optimized-entry' => OptimizerController::class,
            'api' => SeoController::class,
        ];

        // Registration order is load-bearing: specialised handlers first, Default last
        // (craft-entry-optimizer 1.0.7, Plugin::init()).
        $handlers = [
            new MatrixFieldHandler(),
            new AssetFieldHandler(),
            new RelationFieldHandler(),
            new LinkFieldHandler(),
            new DropdownFieldHandler(),
        ];

        // Conditionally register SEOmatic handler if plugin is installed
        $pluginsService = Craft::$app->getPlugins();
        if ($pluginsService->isPluginInstalled('seomatic') && $pluginsService->isPluginEnabled('seomatic')) {
            $handlers[] = new SeomaticFieldHandler();
            Craft::info('SEOmatic plugin detected - registered SEOmatic field handler', __METHOD__);
        }

        // Default handler should always be last (lowest priority)
        $handlers[] = new DefaultFieldHandler();

        $this->fieldHandlerRegistry->registerMultiple($handlers);
    }
}
