<?php

namespace lameco\rankroute\dto;

/**
 * The import response of the text flow. Either a success (with or without a draft), a
 * validation failure (422), a stale fingerprint (409) or a failed structure check (500).
 */
readonly class TextImportResult
{
    public const STATUS_OK = 200;
    public const STATUS_FINGERPRINT_MISMATCH = 409;
    public const STATUS_INVALID = 422;
    public const STATUS_STRUCTURE_CHECK_FAILED = 500;

    /**
     * @param int $statusCode HTTP status the controller answers with
     * @param bool $success
     * @param int $elementId
     * @param int $siteId
     * @param int|null $draftId `drafts.id` of the created draft
     * @param int|null $draftElementId Element id of the created draft
     * @param string|null $cpEditUrl Control panel URL of the draft
     * @param string[] $changedItems Addresses whose value was written
     * @param StructureCheckResult|null $structureCheck
     * @param list<array{id: string|null, code: string, message: string}> $errors
     * @param bool $replayed Whether this answers a retried import (same idempotency key) from the draft it already created
     */
    public function __construct(
        public int $statusCode,
        public bool $success,
        public int $elementId,
        public int $siteId,
        public ?int $draftId = null,
        public ?int $draftElementId = null,
        public ?string $cpEditUrl = null,
        public array $changedItems = [],
        public ?StructureCheckResult $structureCheck = null,
        public array $errors = [],
        public bool $replayed = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (!$this->success) {
            $result = [
                'success' => false,
                'errors' => $this->errors,
            ];

            if ($this->structureCheck !== null) {
                $result['structureCheck'] = $this->structureCheck->toArray();
            }

            if ($this->replayed) {
                $result['replayed'] = true;
            }

            return $result;
        }

        return [
            'success' => true,
            'elementId' => $this->elementId,
            'siteId' => $this->siteId,
            'draftId' => $this->draftId,
            'draftElementId' => $this->draftElementId,
            'cpEditUrl' => $this->cpEditUrl,
            'changedItems' => $this->changedItems,
            'structureCheck' => ($this->structureCheck ?? new StructureCheckResult(true))->toArray(),
            'replayed' => $this->replayed,
        ];
    }
}
