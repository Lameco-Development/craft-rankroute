<?php

namespace lameco\rankroute\dto;

/**
 * The response of `text/create`: a new page as an unpublished draft (200), a validation
 * failure (422), a stale fingerprint or taken slug (409) or a failed structure check (500).
 * Failures have the import's error shape.
 */
readonly class TextCreateResult
{
    public const STATUS_OK = 200;
    public const STATUS_CONFLICT = 409;
    public const STATUS_INVALID = 422;
    public const STATUS_STRUCTURE_CHECK_FAILED = 500;

    /**
     * @param int $statusCode HTTP status the controller answers with
     * @param bool $success
     * @param int $sourceElementId The entry the page was copied from
     * @param int $siteId The site the texts were written in
     * @param int|null $elementId Element id of the new page (the unpublished draft, and the entry once published)
     * @param int|null $draftId `drafts.id` of the new page
     * @param string|null $slug
     * @param string|null $uri The URI the page gets when published, null without URLs
     * @param string|null $cpEditUrl
     * @param string[] $changedItems Addresses whose value differs from the source
     * @param int|null $placeholderAssetId The placeholder image, null when nothing needed it
     * @param string[] $placeholders Addresses of Assets fields and html items where images became the placeholder
     * @param StructureCheckResult|null $structureCheck
     * @param list<array{id: string|null, code: string, message: string}> $errors
     * @param bool $replayed Whether this answers a retried create (same idempotency key)
     */
    public function __construct(
        public int $statusCode,
        public bool $success,
        public int $sourceElementId,
        public int $siteId,
        public ?int $elementId = null,
        public ?int $draftId = null,
        public ?string $slug = null,
        public ?string $uri = null,
        public ?string $cpEditUrl = null,
        public array $changedItems = [],
        public ?int $placeholderAssetId = null,
        public array $placeholders = [],
        public ?StructureCheckResult $structureCheck = null,
        public array $errors = [],
        public bool $replayed = false,
    ) {
    }

    /**
     * A failure with one error that is not about an item.
     */
    public static function error(int $statusCode, int $sourceElementId, int $siteId, string $code, string $message, ?StructureCheckResult $structureCheck = null, bool $replayed = false): self
    {
        return new self(
            statusCode: $statusCode,
            success: false,
            sourceElementId: $sourceElementId,
            siteId: $siteId,
            structureCheck: $structureCheck,
            errors: [['id' => null, 'code' => $code, 'message' => $message]],
            replayed: $replayed,
        );
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
            'sourceElementId' => $this->sourceElementId,
            'siteId' => $this->siteId,
            'elementId' => $this->elementId,
            'draftId' => $this->draftId,
            'draftElementId' => $this->elementId,
            'slug' => $this->slug,
            'uri' => $this->uri,
            'cpEditUrl' => $this->cpEditUrl,
            'changedItems' => $this->changedItems,
            'placeholderAssetId' => $this->placeholderAssetId,
            'placeholders' => $this->placeholders,
            'structureCheck' => ($this->structureCheck ?? new StructureCheckResult(true))->toArray(),
            'replayed' => $this->replayed,
        ];
    }
}
