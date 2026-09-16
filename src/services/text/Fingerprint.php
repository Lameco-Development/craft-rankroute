<?php

namespace lameco\rankroute\services\text;

use craft\base\Component;
use craft\base\ElementInterface;
use lameco\rankroute\dto\TextItem;
use lameco\rankroute\Plugin;

/**
 * `sha256:` + hash of the element's structure and text. Any edit to the element between a
 * text export and its import changes it, so the import can refuse to write onto a page n8n
 * never saw.
 */
class Fingerprint extends Component
{
    /**
     * @param TextItem[]|null $items The element's current items, when already extracted
     */
    public function compute(ElementInterface $element, ?array $items = null): string
    {
        $items ??= Plugin::getInstance()->textExtractor->items($element);

        $document = [
            'id' => (int)$element->id,
            'siteId' => (int)$element->siteId,
            'structure' => Plugin::getInstance()->structureSnapshot->build($element),
            'items' => array_map(fn(TextItem $item) => ['id' => $item->id, 'type' => $item->type, 'value' => $item->value], array_values($items)),
        ];

        return 'sha256:' . hash('sha256', (string)json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
