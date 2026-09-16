<?php

namespace lameco\rankroute\dto;

/**
 * The export response of the text flow.
 */
readonly class TextExportResult
{
    /**
     * @param array{id: int, siteId: int, type: string, url: string|null, cpEditUrl: string|null} $element
     * @param string $fingerprint `sha256:` + hash of the element's structure and text
     * @param TextItem[] $items
     */
    public function __construct(
        public array $element,
        public string $fingerprint,
        public array $items,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'element' => $this->element,
            'fingerprint' => $this->fingerprint,
            'items' => array_map(fn(TextItem $item) => $item->toArray(), array_values($this->items)),
        ];
    }
}
