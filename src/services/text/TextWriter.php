<?php

namespace lameco\rankroute\services\text;

use craft\base\ElementInterface;
use lameco\rankroute\Plugin;

/**
 * Writes values at text flow addresses onto a draft, leaving every other value and every
 * nested entry's identity untouched.
 *
 * Nested entries are written through Craft's own delta Matrix format on the draft
 * (`['entries' => [<id> => ['title' => …, 'fields' => […]]], 'sortOrder' => [<all ids>]]`),
 * nested as deep as the value sits. For an entry id listed in `entries`, Craft loads the
 * existing entry and sets only the given values on it. When the draft does not own that
 * entry primarily (a draft of an existing element), Craft saves a derivative copy for the
 * draft; when it does (a new page), the entry itself is updated. Every id only in
 * `sortOrder` is kept as is. See `craft\fields\Matrix::_createEntriesFromSerializedData()`
 * and `NestedElementManager::saveNestedElements()`.
 */
final class TextWriter
{
    /**
     * @param list<array{entryPath: list<array{handle: string, entryId: int, siblingIds: list<int>}>, address: TextAddress, value: mixed}> $writes
     *     `value` is a string for text (title, SEO meta, text fields) or any field value
     *     Craft accepts for other fields (asset ids)
     */
    public function apply(ElementInterface $draft, array $writes): void
    {
        /** @var array<string, array<string, mixed>> $matrixDeltas top-level Matrix handle => delta value */
        $matrixDeltas = [];
        $seoValues = [];

        foreach ($writes as $write) {
            $address = $write['address'];
            $value = $write['value'];

            if ($write['entryPath'] === []) {
                if ($address->isSeo()) {
                    $seoValues[$address->leaf === TextAddress::SEO_TITLE ? 'seoTitle' : 'seoDescription'] = $value;
                } elseif ($address->isTitle()) {
                    $draft->title = $value;
                } else {
                    $draft->setFieldValue($address->leaf, $value);
                }

                continue;
            }

            $this->addToDelta($matrixDeltas, $write['entryPath'], $address, $value);
        }

        foreach ($matrixDeltas as $handle => $delta) {
            $draft->setFieldValue($handle, $delta);
        }

        if ($seoValues !== []) {
            $this->applySeo($draft, $seoValues);
        }
    }

    /**
     * Adds one nested value to the delta tree:
     * `handle => {entries: {id: {title?, fields: {handle => value | nested delta}}}, sortOrder}`.
     * `sortOrder` always lists every existing nested entry, so none is dropped or reordered.
     *
     * @param array<string, mixed> $fields
     * @param list<array{handle: string, entryId: int, siblingIds: list<int>}> $path
     */
    private function addToDelta(array &$fields, array $path, TextAddress $address, mixed $value): void
    {
        $step = array_shift($path);
        $handle = $step['handle'];
        $fields[$handle] ??= ['entries' => [], 'sortOrder' => $step['siblingIds']];
        $entry = $fields[$handle]['entries'][$step['entryId']] ?? [];

        if ($path !== []) {
            $entry['fields'] ??= [];
            $this->addToDelta($entry['fields'], $path, $address, $value);
        } elseif ($address->isTitle()) {
            $entry['title'] = $value;
        } else {
            $entry['fields'][$address->leaf] = $value;
        }

        $fields[$handle]['entries'][$step['entryId']] = $entry;
    }

    /**
     * Sets only `metaGlobalVars.seoTitle`/`seoDescription` on the draft's own SEOmatic value,
     * keeping every other setting of it.
     *
     * @param array<string, mixed> $values
     */
    private function applySeo(ElementInterface $draft, array $values): void
    {
        $field = Plugin::getInstance()->textExtractor->seomaticField($draft);

        if ($field === null) {
            throw new \RuntimeException('The draft has no SEOmatic field.');
        }

        $bundle = $draft->getFieldValue($field->handle);

        if (!is_object($bundle) || !is_object($bundle->metaGlobalVars ?? null)) {
            throw new \RuntimeException('The SEOmatic value of the draft could not be read.');
        }

        foreach ($values as $key => $value) {
            $bundle->metaGlobalVars->{$key} = $value;
        }

        $draft->setFieldValue($field->handle, $bundle);
    }
}
