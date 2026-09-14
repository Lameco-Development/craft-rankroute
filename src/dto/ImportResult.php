<?php

namespace lameco\rankroute\dto;

/**
 * Import Result DTO
 *
 * Immutable data transfer object containing the result of an import operation.
 */
readonly class ImportResult
{
    /**
     * @param bool $success Whether the import was successful
     * @param int $entryId Original entry ID
     * @param int|null $draftId Draft ID (if created)
     * @param array $updatedFields List of field handles that were updated
     * @param array $errors List of errors encountered during import
     * @param string|null $cpEditUrl Control Panel edit URL for the draft
     * @param string|null $message Optional message describing the result
     */
    public function __construct(
        public bool $success,
        public int $entryId,
        public ?int $draftId = null,
        public array $updatedFields = [],
        public array $errors = [],
        public ?string $cpEditUrl = null,
        public ?string $message = null,
    ) {
    }

    /**
     * Create a successful import result
     *
     * @param int $entryId Entry ID
     * @param int $draftId Draft ID
     * @param array $updatedFields List of updated field handles
     * @param string|null $cpEditUrl Control Panel edit URL
     * @return self
     */
    public static function success(
        int $entryId,
        int $draftId,
        array $updatedFields,
        ?string $cpEditUrl = null,
    ): self {
        $fieldCount = count($updatedFields);
        $message = $fieldCount > 0
            ? "Draft created successfully with {$fieldCount} updated field(s)"
            : "No changes detected";

        return new self(
            success: true,
            entryId: $entryId,
            draftId: $draftId,
            updatedFields: $updatedFields,
            cpEditUrl: $cpEditUrl,
            message: $message,
        );
    }

    /**
     * Create a failed import result
     *
     * @param int $entryId Entry ID
     * @param array $errors List of errors
     * @param string|null $message Optional error message
     * @return self
     */
    public static function failure(int $entryId, array $errors, ?string $message = null): self
    {
        return new self(
            success: false,
            entryId: $entryId,
            errors: $errors,
            message: $message ?? 'Import failed',
        );
    }

    /**
     * Create a no-changes result
     *
     * @param int $entryId Entry ID
     * @return self
     */
    public static function noChanges(int $entryId): self
    {
        return new self(
            success: true,
            entryId: $entryId,
            message: 'No changes detected',
        );
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'success' => $this->success,
            'entryId' => $this->entryId,
            'message' => $this->message,
        ];

        if ($this->draftId !== null) {
            $result['draftId'] = $this->draftId;
        }

        if (!empty($this->updatedFields)) {
            $result['updatedFields'] = $this->updatedFields;
        }

        if ($this->cpEditUrl !== null) {
            $result['cpEditUrl'] = $this->cpEditUrl;
        }

        if (!empty($this->errors)) {
            $result['errors'] = $this->errors;
        }

        return $result;
    }
}
