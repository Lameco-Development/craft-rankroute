<?php

namespace lameco\rankroute\services\text;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use lameco\rankroute\dto\TextItem;

/**
 * A text item plus where it lives, which the import side needs to write it back and the
 * API never shows.
 */
final readonly class ExtractedText
{
    /**
     * @param TextItem $item What the export shows
     * @param TextAddress $address Parsed form of `$item->id`
     * @param ElementInterface $owner The element or nested entry the text belongs to
     * @param FieldInterface|null $field The text field, null for `title` and `seo.*`
     * @param list<array{handle: string, entryId: int, siblingIds: list<int>}> $entryPath
     *     Matrix steps from the element down to `$owner`: the Matrix field instance handle,
     *     the nested entry id, and the ids of every nested entry in that field in sort order
     */
    public function __construct(
        public TextItem $item,
        public TextAddress $address,
        public ElementInterface $owner,
        public ?FieldInterface $field,
        public array $entryPath,
    ) {
    }
}
