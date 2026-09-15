<?php

namespace lameco\rankroute\dto;

/**
 * SEO Bulk Result DTO
 *
 * Immutable data transfer object containing the result of a bulk SEO meta import.
 */
readonly class SeoBulkResult
{
    /**
     * @param bool $success Whether the request was processed
     * @param int $updated Number of elements successfully updated
     * @param int $total Number of items in the normalised payload, including skipped ones
     * @param array<int, array{url?: mixed, uri?: string, reason: string}> $skipped One entry per item that was not written
     */
    public function __construct(
        public bool $success,
        public int $updated,
        public int $total,
        public array $skipped = [],
    ) {
    }

    /**
     * Convert to array for JSON serialization. Key order and presence are a contract with
     * n8n (CONTEXT.md — Boundaries): unchanged from craft-seo-import 1.0.4.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'updated' => $this->updated,
            'total' => $this->total,
            'skipped' => $this->skipped,
        ];
    }
}
