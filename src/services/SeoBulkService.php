<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use lameco\rankroute\dto\SeoBulkResult;
use lameco\rankroute\Plugin;
use yii\web\BadRequestHttpException;

/**
 * Writes SEOmatic meta title/description onto many elements at once, addressed by URL.
 * The bulk-write loop of craft-seo-import 1.0.4 `ApiController::actionImport()`, minus
 * HTTP, with D7 (multi-site, any element, via the shared {@see ElementResolver}) and D12
 * (`No url provided` is reported instead of silently skipped).
 *
 * Detects the SEOmatic field by class-name substring, deliberately without a
 * `use nystudio107\...` import anywhere in this file, so the plugin loads without SEOmatic
 * installed (CONTEXT.md — Boundaries: "SEOmatic is optional at runtime").
 */
class SeoBulkService extends Component
{
    /**
     * Accepts the three shapes n8n sends (CONTEXT.md — Bulk meta item): a flat array, a
     * `{results: [...]}` object, or a `[{results: [...]}]` array.
     *
     * @return array<int, mixed> Only the outer container is validated, so an element is not
     *     guaranteed to be an array — `[1,2,3]` reaches import() intact.
     * @throws BadRequestHttpException if the body is empty, invalid JSON, or has no items
     */
    public function normalizeItems(string $json): array
    {
        if (trim($json) === '') {
            throw new BadRequestHttpException('No JSON data provided in request body.');
        }

        $data = json_decode($json, true);

        if (is_array($data) && isset($data[0]) && is_array($data[0]) && isset($data[0]['results'])) {
            $items = $data[0]['results'];
        } elseif (is_array($data) && isset($data['results'])) {
            $items = $data['results'];
        } else {
            $items = $data;
        }

        // array_is_list rejects a bare item object: iterating that would walk its values and
        // answer 200 with a skip per value, reporting success for a write that never happened.
        if (!is_array($items) || empty($items) || !array_is_list($items)) {
            throw new BadRequestHttpException('No results found in JSON data.');
        }

        return $items;
    }

    /**
     * @param array<int, mixed> $items Each item is expected to be an array, but the shape
     *     is only guaranteed by {@see normalizeItems()}, not by the type system — n8n's
     *     payload is untrusted input.
     */
    public function import(array $items): SeoBulkResult
    {
        $updated = 0;
        $skipped = [];

        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];
            $url = $item['url'] ?? null;

            if (!$url) {
                $skipped[] = ['url' => $url, 'reason' => 'No url provided'];
                continue;
            }

            $metaTitle = $item['meta_title'] ?? null;
            $metaDescription = $item['meta_description'] ?? null;

            if (empty($metaTitle) && empty($metaDescription)) {
                $skipped[] = ['url' => $url, 'reason' => 'No meta_title or meta_description provided'];
                continue;
            }

            ['uri' => $uri, 'siteId' => $siteId] = Plugin::getInstance()->elementResolver->resolve($url);

            $element = Craft::$app->getElements()->getElementByUri($uri, $siteId);

            if (!$element instanceof Element) {
                $skipped[] = ['url' => $url, 'uri' => $uri, 'reason' => 'No entry found'];
                continue;
            }

            $seomaticField = $this->findSeomaticField($element);

            if ($seomaticField === null) {
                $skipped[] = ['url' => $url, 'reason' => 'No SEOmatic field found on this entry'];
                continue;
            }

            $metaGlobalVars = [];

            if ($metaTitle) {
                $metaGlobalVars['seoTitle'] = $metaTitle;
                $metaGlobalVars['override-seoTitle'] = true;
            }

            if ($metaDescription) {
                $metaGlobalVars['seoDescription'] = $metaDescription;
                $metaGlobalVars['override-seoDescription'] = true;
            }

            $element->setFieldValue($seomaticField->handle, [
                'metaGlobalVars' => $metaGlobalVars,
            ]);

            if (Craft::$app->getElements()->saveElement($element)) {
                ++$updated;
            } else {
                $skipped[] = ['url' => $url, 'reason' => 'Failed to save entry'];
            }
        }

        return new SeoBulkResult(
            success: true,
            updated: $updated,
            total: count($items),
            skipped: $skipped,
        );
    }

    /**
     * Whether the `seomatic` plugin is installed and enabled — same check as the
     * conditional handler registration in {@see \lameco\rankroute\Plugin::init()}.
     */
    public function seomaticInstalled(): bool
    {
        $pluginsService = Craft::$app->getPlugins();

        return $pluginsService->isPluginInstalled('seomatic') && $pluginsService->isPluginEnabled('seomatic');
    }

    /**
     * Find the SEOmatic field on an element's field layout, regardless of its handle,
     * by class name rather than an `instanceof` check against the vendor class.
     */
    private function findSeomaticField(ElementInterface $element): ?FieldInterface
    {
        $fieldLayout = $element->getFieldLayout();

        if (!$fieldLayout) {
            return null;
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            $fieldClass = get_class($field);

            if (str_contains($fieldClass, 'seomatic') || str_contains($fieldClass, 'SeoSettings') || str_contains($fieldClass, 'Seomatic')) {
                return $field;
            }
        }

        return null;
    }
}
