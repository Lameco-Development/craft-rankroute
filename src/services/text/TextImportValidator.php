<?php

namespace lameco\rankroute\services\text;

use lameco\rankroute\dto\TextItem;

/**
 * The validation rules of a text import, run before anything is written. All-or-nothing:
 * the caller writes nothing when this returns any error.
 *
 * Pure on purpose (no Craft): the current items come from {@see TextExtractor}, the
 * submitted items straight from the request body.
 */
final class TextImportValidator
{
    public const UNKNOWN_ID = 'unknown_id';
    public const MISSING_ID = 'missing_id';
    public const DUPLICATE_ID = 'duplicate_id';
    public const EMPTY_VALUE = 'empty_value';
    public const TOO_LONG = 'too_long';
    public const HTML_IN_PLAIN = 'html_in_plain';
    public const HTML_STRUCTURE_CHANGED = 'html_structure_changed';
    public const TWIG_IN_VALUE = 'twig_in_value';
    public const FORBIDDEN_SYNTAX = 'forbidden_syntax';
    public const REFERENCE_TAG_CHANGED = 'reference_tag_changed';

    /** Craft reference tags, e.g. `{entry:6@1:url||https://…}` (review rule R2). */
    public const REFERENCE_TAG_PATTERN = '/\{[a-z][a-z0-9_\\\\]*:[^}]*\}/i';

    /**
     * @param array<string, TextItem> $currentItems Current items keyed by id
     * @param list<mixed> $submittedItems `[{id, value}, …]` as decoded from the request
     * @return list<array{id: string|null, code: string, message: string}>
     */
    public function validate(array $currentItems, array $submittedItems): array
    {
        $errors = [];
        $seen = [];

        foreach ($submittedItems as $position => $submitted) {
            $id = is_array($submitted) ? ($submitted['id'] ?? null) : null;

            if (!is_string($id)) {
                $errors[] = self::error(null, self::UNKNOWN_ID, "Item {$position} has no string id.");
                continue;
            }

            if (isset($seen[$id])) {
                $errors[] = self::error($id, self::DUPLICATE_ID, "Item \"{$id}\" is submitted more than once.");
                continue;
            }

            $seen[$id] = true;

            if (!isset($currentItems[$id])) {
                $errors[] = self::error($id, self::UNKNOWN_ID, "Item \"{$id}\" is not a text item of this element.");
                continue;
            }

            $valueError = $this->validateValue($currentItems[$id], $submitted['value'] ?? null);

            if ($valueError !== null) {
                $errors[] = $valueError;
            }
        }

        foreach ($currentItems as $id => $item) {
            if (!isset($seen[$id])) {
                $errors[] = self::error((string)$id, self::MISSING_ID, "Item \"{$id}\" is missing from the submission.");
            }
        }

        return $errors;
    }

    /**
     * @return array{id: string, code: string, message: string}|null
     */
    public function validateValue(TextItem $current, mixed $value): ?array
    {
        $id = $current->id;

        if (!is_string($value) || trim($value) === '') {
            return self::error($id, self::EMPTY_VALUE, "Item \"{$id}\" must be a non-empty string.");
        }

        if ($current->type === TextItem::TYPE_HTML && trim(HtmlSkeleton::textContent($value)) === '') {
            return self::error($id, self::EMPTY_VALUE, "Item \"{$id}\" has no text left.");
        }

        if ($current->maxLength !== null && mb_strlen($value) > $current->maxLength) {
            return self::error($id, self::TOO_LONG, "Item \"{$id}\" is longer than {$current->maxLength} characters.");
        }

        if (str_starts_with($id, 'seo.') && ($syntax = self::forbiddenSyntax($current->value, $value)) !== null) {
            return self::error($id, self::FORBIDDEN_SYNTAX, "Item \"{$id}\" must not introduce \"{$syntax}\": SEO meta is parsed for environment variables, aliases and templates.");
        }

        if (str_contains($value, '{{') || str_contains($value, '{%')) {
            return self::error($id, self::TWIG_IN_VALUE, "Item \"{$id}\" must not contain Twig tags.");
        }

        if ($current->type === TextItem::TYPE_PLAIN && HtmlSkeleton::containsMarkup($value)) {
            return self::error($id, self::HTML_IN_PLAIN, "Item \"{$id}\" is plain text and must not contain HTML.");
        }

        if (self::referenceTags($current->value) !== self::referenceTags($value)) {
            return self::error($id, self::REFERENCE_TAG_CHANGED, "Item \"{$id}\" added, removed or changed a Craft reference tag; they must stay exactly as exported.");
        }

        if ($current->type === TextItem::TYPE_HTML && !HtmlSkeleton::equals($current->value, $value)) {
            return self::error($id, self::HTML_STRUCTURE_CHANGED, "Item \"{$id}\" changed its HTML tags or attributes; only text may change.");
        }

        return null;
    }

    /**
     * R1: the character or prefix a new SEO value introduces that the original does not
     * have (`{`, `}`, `$`, or a leading `@`), or null.
     */
    public static function forbiddenSyntax(string $original, string $new): ?string
    {
        foreach (['{', '}', '$'] as $character) {
            if (str_contains($new, $character) && !str_contains($original, $character)) {
                return $character;
            }
        }

        if (str_starts_with($new, '@') && !str_starts_with($original, '@')) {
            return '@';
        }

        return null;
    }

    /**
     * R2: every reference tag in a raw value, sorted, byte for byte.
     *
     * @return list<string>
     */
    public static function referenceTags(string $value): array
    {
        preg_match_all(self::REFERENCE_TAG_PATTERN, $value, $matches);
        $tags = $matches[0];
        sort($tags, SORT_STRING);

        return $tags;
    }

    /**
     * @return array{id: string|null, code: string, message: string}
     */
    private static function error(?string $id, string $code, string $message): array
    {
        return ['id' => $id, 'code' => $code, 'message' => $message];
    }
}
