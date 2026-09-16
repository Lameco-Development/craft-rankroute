<?php

namespace lameco\rankroute\dto;

/**
 * Outcome of comparing the structure snapshot of a canonical element with its draft.
 */
readonly class StructureCheckResult
{
    /**
     * @param bool $passed Whether the snapshots are equal
     * @param list<array{path: string, before: mixed, after: mixed}> $differences
     */
    public function __construct(
        public bool $passed,
        public array $differences = [],
    ) {
    }

    /**
     * @return array{passed: bool, differences: list<array{path: string, before: mixed, after: mixed}>}
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'differences' => $this->differences,
        ];
    }
}
