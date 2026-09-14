<?php

namespace lameco\rankroute\dto;

use craft\base\Element;

/**
 * Export Metadata DTO
 *
 * Immutable data transfer object containing essential element metadata for import operations.
 * Only includes data actually needed for element identification and field change detection.
 */
readonly class ExportMetadata
{
    /**
     * @param int $id Element ID (required for element lookup)
     * @param int $siteId Site ID (required for element lookup)
     */
    public function __construct(
        public int $id,
        public int $siteId,
    ) {
    }

    /**
     * Create metadata from an element
     *
     * @param Element $element The element to extract metadata from
     */
    public static function fromElement(Element $element): self
    {
        return new self(
            id: $element->id,
            siteId: $element->siteId,
        );
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'siteId' => $this->siteId,
        ];
    }
}
