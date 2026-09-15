<?php

namespace lameco\rankroute\dto;

use craft\base\Element;

/**
 * Export Result DTO
 *
 * Immutable data transfer object containing the complete export result.
 * Includes all native element fields at root level for consistent import.
 */
readonly class ExportResult
{
    /**
     * @param ExportMetadata $metadata Element metadata
     * @param string $title Element title (always present, even if empty)
     * @param array $fields Custom field values (field handle => value)
     */
    public function __construct(
        public ExportMetadata $metadata,
        public string $title,
        public array $fields = [],
    ) {
    }

    /**
     * Create export result from element
     *
     * @param Element $element The element to export
     * @param array $fields Custom field values
     */
    public static function fromElement(Element $element, array $fields = []): self
    {
        return new self(
            metadata: ExportMetadata::fromElement($element),
            title: $element->title ?? '',
            fields: $fields,
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
            'metadata' => $this->metadata->toArray(),
            'title' => $this->title,
            ...$this->fields,
        ];
    }
}
