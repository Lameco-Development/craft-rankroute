<?php

namespace lameco\rankroute\services\text;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use DateTimeInterface;
use lameco\rankroute\dto\TextItem;
use lameco\rankroute\Plugin;
use Throwable;

/**
 * A normalised tree of everything about an element except its extractable text: the
 * thing that must be identical between a canonical element and a text-flow draft.
 *
 * - element attributes (type, layout, status, slug, URI, dates, authors, parent);
 * - non-text fields by their serialised value (Link, relations, options, Table, …);
 * - SEOmatic by its serialised settings without the extractable meta title/description;
 * - text fields as a marker: `{text: "plain"}` or `{text: "html", tags: [...]}`, where
 *   `tags` is the {@see HtmlSkeleton} of the value as Craft would store it (so HTML
 *   Purifier normalisation on save cannot register as a change); text that is not
 *   extractable (empty, URL, Twig, excluded, shared with another site, in a disabled
 *   entry) is kept in full;
 * - Matrix fields as the ordered list of nested entries, each by canonical id, so a
 *   draft's derivative nested entry compares equal to the entry it was copied from.
 *
 * Titles that are generated (entry types without a title field, or with a title format)
 * are left out: they follow from other fields and are rewritten by Craft on every save.
 */
class StructureSnapshot extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function build(ElementInterface $element): array
    {
        return [
            'element' => $this->elementAttributes($element),
            'fields' => $this->fieldSnapshots($element, true, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function elementAttributes(ElementInterface $element): array
    {
        return [
            'type' => get_class($element),
            'typeId' => $element->typeId ?? null,
            'fieldLayout' => $element->getFieldLayout()?->uid,
            'enabled' => (bool)$element->enabled,
            'enabledForSite' => $element->getEnabledForSite(),
            'slug' => $element->slug,
            'uri' => $element->uri,
            'postDate' => $this->date($element->postDate ?? null),
            'expiryDate' => $this->date($element->expiryDate ?? null),
            'authorIds' => $element instanceof Entry ? array_map('intval', $element->getAuthorIds()) : null,
            // No `level`: drafts are not stored in the structure, so theirs is always null.
            // The parent id carries the hierarchy for both.
            'parentId' => $element->getParentId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldSnapshots(ElementInterface $owner, bool $textAllowed, bool $topLevel): array
    {
        $extractor = $this->extractor();
        $snapshot = [];

        if ($extractor->hasEditableTitle($owner)) {
            $title = (string)$owner->title;
            $snapshot['title'] = $textAllowed
                && !$extractor->isTitleSharedWithOtherSites($owner)
                && $extractor->isExtractableValue($title, TextItem::TYPE_PLAIN)
                ? ['text' => TextItem::TYPE_PLAIN]
                : ['value' => $title];
        }

        foreach ($owner->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $handle = $field->handle;

            try {
                if ($field instanceof Matrix) {
                    $snapshot[$handle] = ['entries' => $this->nestedEntries($owner, $field, $textAllowed)];
                } elseif ($extractor->isSeomaticField($field)) {
                    $snapshot[$handle] = ['seomatic' => $this->seomatic($owner, $field, $textAllowed && $topLevel)];
                } elseif (($type = $extractor->textTypeOf($field)) !== null) {
                    $snapshot[$handle] = $this->text($owner, $field, $type, $textAllowed);
                } else {
                    $snapshot[$handle] = ['value' => $this->normalise($field->serializeValue($owner->getFieldValue($handle), $owner))];
                }
            } catch (Throwable $e) {
                // Not silently equal: a different failure on the draft side must differ.
                $snapshot[$handle] = ['unreadable' => get_class($e) . ':' . hash('sha256', $e->getMessage())];
            }
        }

        return $snapshot;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nestedEntries(ElementInterface $owner, Matrix $field, bool $textAllowed): array
    {
        $extractor = $this->extractor();
        $entries = [];

        foreach ($extractor->nestedEntries($owner, $field) as $entry) {
            $type = $entry->getType();
            $entries[] = [
                'canonicalId' => $entry->getCanonicalId(),
                'type' => $type->handle,
                'enabled' => (bool)$entry->enabled,
                'enabledForSite' => $entry->getEnabledForSite(),
                'fields' => $this->fieldSnapshots(
                    $entry,
                    $textAllowed && $extractor->allowsNestedText($owner, $entry),
                    false,
                ),
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function text(ElementInterface $owner, FieldInterface $field, string $type, bool $textAllowed): array
    {
        $extractor = $this->extractor();
        $raw = $extractor->stringValue($owner->getFieldValue($field->handle));
        $extractable = $raw !== null
            && $textAllowed
            && !$extractor->isFieldExcluded($field->handle)
            && !$extractor->isFieldSharedWithOtherSites($owner, $field)
            && $extractor->isExtractableValue($raw, $type);

        if ($type === TextItem::TYPE_PLAIN) {
            return $extractable ? ['text' => $type] : ['value' => $raw];
        }

        // As stored: HTML fields purify on save, so compare what the database would hold.
        $stored = $this->normalise($field->serializeValue($owner->getFieldValue($field->handle), $owner));
        $stored = HtmlSkeleton::normaliseReferenceTags(is_string($stored) ? $stored : '');

        return $extractable
            ? ['text' => $type, 'tags' => HtmlSkeleton::tokens($stored, relaxedReferenceTags: true)]
            : ['value' => $stored];
    }

    /**
     * @return mixed Serialised SEOmatic settings, minus meta title/description when those
     *     are extractable text
     */
    private function seomatic(ElementInterface $owner, FieldInterface $field, bool $textAllowed): mixed
    {
        $extractor = $this->extractor();
        $serialized = $this->normalise($field->serializeValue($owner->getFieldValue($field->handle), $owner));

        if (!is_array($serialized)) {
            return $serialized;
        }

        // Bookkeeping, not settings: SEOmatic defaults it to "now" for an element whose
        // SEO field was never saved, which would make every fingerprint unique.
        unset($serialized['sourceDateUpdated']);

        if (!$textAllowed || $extractor->isFieldSharedWithOtherSites($owner, $field)) {
            return $serialized;
        }

        $meta = $extractor->seoMeta($owner, $field) ?? [];

        foreach (['seoTitle', 'seoDescription'] as $key) {
            $value = $meta[$key] ?? null;

            if ($value !== null && $extractor->isExtractableValue($value, TextItem::TYPE_PLAIN) && isset($serialized['metaGlobalVars']) && is_array($serialized['metaGlobalVars'])) {
                unset($serialized['metaGlobalVars'][$key]);
            }
        }

        return $serialized;
    }

    private function date(mixed $date): ?string
    {
        return $date instanceof DateTimeInterface ? $date->format(DATE_ATOM) : null;
    }

    /**
     * Objects to arrays, JSON strings decoded, so snapshots compare with `===`.
     */
    private function normalise(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return json_decode((string)json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
    }

    private function extractor(): TextExtractor
    {
        return Plugin::getInstance()->textExtractor;
    }
}
