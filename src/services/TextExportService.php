<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use lameco\rankroute\dto\TextExportResult;
use lameco\rankroute\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * Text flow export: an element's text items plus the fingerprint the import has to echo.
 */
class TextExportService extends Component
{
    /**
     * Finds the element by `url` (URL or path, resolved like the other flows) or by
     * `id` + optional `siteId` (primary site when omitted). By id, only top-level entries,
     * categories and Commerce products are found.
     *
     * @throws BadRequestHttpException if neither is given, or the site is unknown
     * @throws NotFoundHttpException if no element matches
     */
    public function findElement(mixed $url, mixed $id, mixed $siteId): ElementInterface
    {
        if (is_string($url) && trim($url) !== '') {
            ['uri' => $uri, 'siteId' => $resolvedSiteId] = Plugin::getInstance()->elementResolver->resolve($url);
            $element = Craft::$app->getElements()->getElementByUri($uri, $resolvedSiteId);

            if (!$element) {
                throw new NotFoundHttpException("No element found for \"{$url}\".");
            }

            return $element;
        }

        if (!is_numeric($id) || (int)$id <= 0) {
            throw new BadRequestHttpException('A url, or an element id (with an optional siteId), is required.');
        }

        if ($siteId === null || $siteId === '') {
            $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        } elseif (!is_numeric($siteId) || !Craft::$app->getSites()->getSiteById((int)$siteId)) {
            throw new BadRequestHttpException("Unknown siteId \"{$siteId}\".");
        }

        $element = Craft::$app->getElements()->getElementById((int)$id, null, (int)$siteId);

        if (!$element || !self::isPageElement($element)) {
            throw new NotFoundHttpException("Element {$id} not found in site {$siteId}.");
        }

        return $element;
    }

    /**
     * Whether an element is something the text flow addresses by id: a top-level entry
     * (not a nested one), a category or a Commerce product. Anything else (nested entries,
     * assets, users, addresses, globals) is answered as not found.
     */
    public static function isPageElement(ElementInterface $element): bool
    {
        if ($element instanceof Entry) {
            return $element->fieldId === null;
        }

        return $element instanceof Category || is_a($element, 'craft\commerce\elements\Product');
    }

    public function export(ElementInterface $element): TextExportResult
    {
        $plugin = Plugin::getInstance();
        $items = $plugin->textExtractor->items($element);

        return new TextExportResult(
            element: [
                'id' => (int)$element->id,
                'siteId' => (int)$element->siteId,
                'type' => get_class($element),
                'url' => $element->getUrl(),
                'cpEditUrl' => $element->getCpEditUrl(),
            ],
            fingerprint: $plugin->textFingerprint->compute($element, $items),
            items: $items,
        );
    }
}
