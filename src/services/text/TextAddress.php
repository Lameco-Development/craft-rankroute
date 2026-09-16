<?php

namespace lameco\rankroute\services\text;

use InvalidArgumentException;

/**
 * The address (`id`) of a text item: a dot path from the element.
 *
 * - `title`: the native title of the element (or, after an entry segment, of a nested entry);
 * - `seo.seoTitle` / `seo.seoDescription`: SEOmatic meta of the top-level element;
 * - `<fieldHandle>`: a custom field, by its field layout instance handle;
 * - `<matrixHandle>[<n>].`: the n-th nested entry of a Matrix field (0-based, counted over
 *   all nested entries in sort order, disabled ones included), followed by the rest of the path.
 *
 * Example: `pageBuilder[6].items[1].title`.
 */
final readonly class TextAddress
{
    public const TITLE = 'title';
    public const SEO_TITLE = 'seo.seoTitle';
    public const SEO_DESCRIPTION = 'seo.seoDescription';

    private const HANDLE_PATTERN = '[a-zA-Z][a-zA-Z0-9_]*';

    /**
     * @param list<array{handle: string, index: int}> $entries Matrix steps from the element down
     * @param string $leaf `title`, `seo.seoTitle`, `seo.seoDescription` or a field handle
     */
    public function __construct(
        public array $entries,
        public string $leaf,
    ) {
    }

    /**
     * @throws InvalidArgumentException if the string is not a well-formed address
     */
    public static function parse(string $id): self
    {
        $handle = self::HANDLE_PATTERN;

        if (!preg_match("/^((?:{$handle}\\[\\d+\\]\\.)*)({$handle}|seo\\.seoTitle|seo\\.seoDescription)$/", $id, $match)) {
            throw new InvalidArgumentException("Malformed text address \"{$id}\".");
        }

        $entries = [];

        if ($match[1] !== '') {
            preg_match_all("/({$handle})\\[(\\d+)\\]\\./", $match[1], $steps, PREG_SET_ORDER);

            foreach ($steps as $step) {
                $entries[] = ['handle' => $step[1], 'index' => (int)$step[2]];
            }
        }

        $address = new self($entries, $match[2]);

        if ($address->isSeo() && $entries !== []) {
            throw new InvalidArgumentException("SEO meta can only be addressed on the element itself, not in \"{$id}\".");
        }

        return $address;
    }

    public static function isValid(string $id): bool
    {
        try {
            self::parse($id);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The address of a nested entry's leaf, one Matrix step deeper.
     */
    public function withEntry(string $matrixHandle, int $index): self
    {
        return new self([...$this->entries, ['handle' => $matrixHandle, 'index' => $index]], $this->leaf);
    }

    public function withLeaf(string $leaf): self
    {
        return new self($this->entries, $leaf);
    }

    public function isTitle(): bool
    {
        return $this->leaf === self::TITLE;
    }

    public function isSeo(): bool
    {
        return $this->leaf === self::SEO_TITLE || $this->leaf === self::SEO_DESCRIPTION;
    }

    public function toString(): string
    {
        $prefix = '';

        foreach ($this->entries as $entry) {
            $prefix .= "{$entry['handle']}[{$entry['index']}].";
        }

        return $prefix . $this->leaf;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
