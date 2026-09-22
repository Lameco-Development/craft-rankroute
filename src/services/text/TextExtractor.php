<?php

namespace lameco\rankroute\services\text;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\fields\PlainText;
use lameco\rankroute\dto\TextItem;
use Throwable;

/**
 * Element → text items: every string of an element n8n may rewrite, and nothing else.
 *
 * An item is a PlainText, CKEditor or Redactor field, a native title the editor types
 * (entry type with a title field and no title format) or a literal SEOmatic meta
 * title/description, whose value is non-empty, not a URL/e-mail/number, contains no Twig,
 * is not excluded by `config/rankroute.php`, is not shared with another site of the
 * element (see {@see isSharedWithOtherSites()}) and sits in enabled nested entries only.
 *
 * CKEditor, Redactor and SEOmatic are detected by class name, so none of them is a runtime
 * dependency.
 */
class TextExtractor extends Component
{
    public const CKEDITOR_FIELD = 'craft\ckeditor\Field';
    public const REDACTOR_FIELD = 'craft\redactor\Field';

    /** Native titles are `varchar(255)` in `elements_sites`. */
    public const TITLE_MAX_LENGTH = 255;

    /**
     * @var string[] Case-insensitive fnmatch patterns on field handles that never yield items.
     */
    public array $excludeFields = ['*url', '*webhook*', 'importId', '*Id', 'llmContent', 'cocNumber'];

    /**
     * @var string[] Case-insensitive fnmatch patterns on nested entry type handles; nothing
     *     inside a matching nested entry yields items.
     */
    public array $excludeEntryTypes = ['*button*'];

    /**
     * @var bool Skip the text of nested entries whose primary owner is another element.
     *     Always on in production; tests turn it off to reach the import-side guard.
     * @internal
     */
    public bool $skipSharedNestedEntries = true;

    /**
     * @var array<int, list<int>> site ids per canonical element id, see {@see otherSiteIds()}
     */
    private array $siteIdsByElement = [];

    public function init(): void
    {
        parent::init();

        $config = Craft::$app->getConfig()->getConfigFromFile('rankroute');
        $textFlow = is_array($config) ? ($config['textFlow'] ?? []) : [];

        if (isset($textFlow['excludeFields']) && is_array($textFlow['excludeFields'])) {
            $this->excludeFields = array_values($textFlow['excludeFields']);
        }

        if (isset($textFlow['excludeEntryTypes']) && is_array($textFlow['excludeEntryTypes'])) {
            $this->excludeEntryTypes = array_values($textFlow['excludeEntryTypes']);
        }
    }

    /**
     * @return array<string, ExtractedText> keyed by address, in document order
     */
    public function extract(ElementInterface $element): array
    {
        $items = [];
        $this->walk($element, new TextAddress([], ''), [], $items);

        return $items;
    }

    /**
     * @return TextItem[]
     */
    public function items(ElementInterface $element): array
    {
        return array_values(array_map(fn(ExtractedText $text) => $text->item, $this->extract($element)));
    }

    /**
     * `plain`, `html`, or null when the field is not a text field.
     */
    public function textTypeOf(FieldInterface $field): ?string
    {
        if ($field instanceof PlainText) {
            return TextItem::TYPE_PLAIN;
        }

        if (is_a($field, self::CKEDITOR_FIELD) || is_a($field, self::REDACTOR_FIELD)) {
            return TextItem::TYPE_HTML;
        }

        return null;
    }

    public function isSeomaticField(FieldInterface $field): bool
    {
        $class = get_class($field);

        return str_contains($class, 'seomatic') || str_contains($class, 'SeoSettings') || str_contains($class, 'Seomatic');
    }

    public function isFieldExcluded(string $handle): bool
    {
        return $this->matchesAny($handle, $this->excludeFields);
    }

    public function isEntryTypeExcluded(string $handle): bool
    {
        return $this->matchesAny($handle, $this->excludeEntryTypes);
    }

    /**
     * Whether a value is text the model may rewrite: non-empty, no Twig, and not a URL,
     * e-mail address or number.
     */
    public function isExtractableValue(string $value, string $type): bool
    {
        if (str_contains($value, '{{') || str_contains($value, '{%')) {
            return false;
        }

        $text = trim($type === TextItem::TYPE_HTML ? HtmlSkeleton::textContent($value) : $value);

        if ($text === '') {
            return false;
        }

        // A plain value that already looks like markup could never be imported back
        // unchanged (`html_in_plain`), so it would block every import of the element.
        if ($type === TextItem::TYPE_PLAIN && HtmlSkeleton::containsMarkup($value)) {
            return false;
        }

        if (filter_var($text, FILTER_VALIDATE_EMAIL) !== false || filter_var($text, FILTER_VALIDATE_URL) !== false) {
            return false;
        }

        if (preg_match('~^(https?://|www\.|mailto:|tel:|/|#)\S*$~i', $text)) {
            return false;
        }

        return !preg_match('~^[+\-]?[\d\s.,()/%-]*\d[\d\s.,()/%-]*$~', $text);
    }

    /**
     * Whether the element's title is typed by an editor, as opposed to generated or absent.
     */
    public function hasEditableTitle(ElementInterface $element): bool
    {
        if (!$element::hasTitles()) {
            return false;
        }

        if ($element instanceof Entry) {
            $type = $element->getType();

            return $type->hasTitleField && !$type->titleFormat;
        }

        return $element->getFieldLayout()?->isFieldIncluded('title') ?? false;
    }

    /**
     * A field value as a string: raw HTML for HTML fields (reference tags intact), the
     * string itself for PlainText, null when empty.
     */
    public function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'getRawContent')) {
            $value = $value->getRawContent();
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string)$value;
        }

        return null;
    }

    /**
     * Every nested entry of a Matrix field on an owner, disabled ones included, in sort order.
     *
     * @return Entry[]
     */
    public function nestedEntries(ElementInterface $owner, Matrix $field): array
    {
        if (!$owner->id) {
            return [];
        }

        return Entry::find()
            ->fieldId($field->id)
            ->ownerId($owner->id)
            ->siteId($owner->siteId)
            ->status(null)
            ->all();
    }

    public function seomaticField(ElementInterface $element): ?FieldInterface
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($this->isSeomaticField($field)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * SEOmatic's literal meta title/description, or null for a value that cannot be read.
     * Reads are defensive: SEOmatic can throw outside a web request.
     *
     * @return array{seoTitle: string|null, seoDescription: string|null}|null
     */
    public function seoMeta(ElementInterface $element, FieldInterface $field): ?array
    {
        try {
            $bundle = $element->getFieldValue($field->handle);
            $vars = is_object($bundle) ? ($bundle->metaGlobalVars ?? null) : null;

            if (!is_object($vars)) {
                return null;
            }

            return [
                'seoTitle' => $this->stringValue($vars->seoTitle ?? null),
                'seoDescription' => $this->stringValue($vars->seoDescription ?? null),
            ];
        } catch (Throwable $e) {
            Craft::warning("Could not read SEOmatic meta of element {$element->id}: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    /**
     * Whether a nested entry's text may be extracted under this owner: it is enabled, its
     * type is not excluded, and it belongs to the owner (primary owner is the owner or the
     * owner's canonical, so a draft's derivatives and the canonical entries it still shares
     * both count). A nested entry shared from another element is not the owner's to rewrite.
     */
    public function allowsNestedText(ElementInterface $owner, Entry $entry): bool
    {
        if (!$this->isNestedEntryEnabled($entry) || $this->isEntryTypeExcluded($entry->getType()->handle)) {
            return false;
        }

        if (!$this->skipSharedNestedEntries) {
            return true;
        }

        return in_array($entry->getPrimaryOwnerId(), [(int)$owner->id, (int)$owner->getCanonicalId()], true);
    }

    public function isNestedEntryEnabled(ElementInterface $entry): bool
    {
        return $entry->enabled && $entry->getEnabledForSite() !== false;
    }

    /**
     * Whether a field value of this element is shared with another site the element exists
     * in: the field's translation key (translation method "none", or a site group, language
     * or custom key another site of the element has too) is the same there. Craft
     * propagates such a value on save, so writing it for one site would write it for every
     * site sharing it, e.g. Dutch text into the English page. The text flow leaves it alone.
     */
    public function isFieldSharedWithOtherSites(ElementInterface $element, FieldInterface $field): bool
    {
        return $this->isSharedWithOtherSites($element, fn(ElementInterface $site) => $field->getTranslationKey($site));
    }

    /**
     * {@see isFieldSharedWithOtherSites()} for the native title (the entry type's title
     * translation method).
     */
    public function isTitleSharedWithOtherSites(ElementInterface $element): bool
    {
        return $this->isSharedWithOtherSites($element, fn(ElementInterface $site) => $site->getTitleTranslationKey());
    }

    /**
     * @param callable(ElementInterface): string $translationKey
     */
    public function isSharedWithOtherSites(ElementInterface $element, callable $translationKey): bool
    {
        $otherSiteIds = array_values(array_diff($this->siteIdsOf($element), [(int)$element->siteId]));

        if ($otherSiteIds === []) {
            return false;
        }

        $key = $translationKey($element);

        foreach ($otherSiteIds as $siteId) {
            // The key as Craft computes it for the element in that site; a copy is enough,
            // the key depends on the site (and, for a custom format, on the element).
            $inOtherSite = clone $element;
            $inOtherSite->siteId = $siteId;

            if ($translationKey($inOtherSite) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sites the element exists in, looked up by canonical id so a draft and its
     * canonical element answer the same. Cached per element for the lifetime of this
     * component: site membership does not change during a request.
     *
     * @return list<int>
     */
    private function siteIdsOf(ElementInterface $element): array
    {
        $canonicalId = (int)$element->getCanonicalId();

        return $this->siteIdsByElement[$canonicalId] ??= array_map('intval', (new Query())
            ->select(['siteId'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $canonicalId])
            ->column());
    }

    /**
     * @param array<string, ExtractedText> $items
     * @param list<array{handle: string, entryId: int, siblingIds: list<int>}> $entryPath
     */
    private function walk(ElementInterface $owner, TextAddress $prefix, array $entryPath, array &$items): void
    {
        if ($this->hasEditableTitle($owner) && !$this->isTitleSharedWithOtherSites($owner)) {
            $title = (string)$owner->title;

            if ($this->isExtractableValue($title, TextItem::TYPE_PLAIN)) {
                $this->add($items, $prefix->withLeaf(TextAddress::TITLE), TextItem::TYPE_PLAIN, $title, self::TITLE_MAX_LENGTH, $owner, null, $entryPath);
            }
        }

        if ($entryPath === []) {
            $seoField = $this->seomaticField($owner);
            $meta = $seoField && !$this->isFieldSharedWithOtherSites($owner, $seoField) ? $this->seoMeta($owner, $seoField) : null;

            foreach ([TextAddress::SEO_TITLE => 'seoTitle', TextAddress::SEO_DESCRIPTION => 'seoDescription'] as $leaf => $key) {
                $value = $meta[$key] ?? null;

                if ($value !== null && $this->isExtractableValue($value, TextItem::TYPE_PLAIN)) {
                    $this->add($items, $prefix->withLeaf($leaf), TextItem::TYPE_PLAIN, $value, null, $owner, $seoField, $entryPath);
                }
            }
        }

        foreach ($owner->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof Matrix) {
                $entries = $this->nestedEntries($owner, $field);
                $siblingIds = array_map(fn(Entry $entry) => (int)$entry->id, $entries);

                foreach ($entries as $index => $entry) {
                    if (!$this->allowsNestedText($owner, $entry)) {
                        continue;
                    }

                    $step = ['handle' => $field->handle, 'entryId' => (int)$entry->id, 'siblingIds' => $siblingIds];
                    $this->walk($entry, $prefix->withEntry($field->handle, $index), [...$entryPath, $step], $items);
                }

                continue;
            }

            $type = $this->textTypeOf($field);

            if ($type === null || $this->isFieldExcluded($field->handle) || $this->isFieldSharedWithOtherSites($owner, $field)) {
                continue;
            }

            $value = $this->stringValue($owner->getFieldValue($field->handle));

            if ($value === null || !$this->isExtractableValue($value, $type)) {
                continue;
            }

            $maxLength = $field instanceof PlainText && $field->charLimit ? (int)$field->charLimit : null;
            $this->add($items, $prefix->withLeaf($field->handle), $type, $value, $maxLength, $owner, $field, $entryPath);
        }
    }

    /**
     * @param array<string, ExtractedText> $items
     * @param list<array{handle: string, entryId: int, siblingIds: list<int>}> $entryPath
     */
    private function add(
        array &$items,
        TextAddress $address,
        string $type,
        string $value,
        ?int $maxLength,
        ElementInterface $owner,
        ?FieldInterface $field,
        array $entryPath,
    ): void {
        $id = $address->toString();
        $items[$id] = new ExtractedText(new TextItem($id, $type, $value, $maxLength), $address, $owner, $field, $entryPath);
    }

    /**
     * @param string[] $patterns
     */
    private function matchesAny(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch(strtolower((string)$pattern), strtolower($subject))) {
                return true;
            }
        }

        return false;
    }
}
