<?php

namespace lameco\rankroute\tests\unit;

use lameco\rankroute\dto\TextItem;
use lameco\rankroute\services\text\TextImportValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextImportValidatorTest extends TestCase
{
    /**
     * @return array<string, TextItem>
     */
    private function currentItems(): array
    {
        $items = [
            new TextItem('title', TextItem::TYPE_PLAIN, 'Applications', 255),
            new TextItem('intro', TextItem::TYPE_PLAIN, 'Short intro', 20),
            new TextItem('pageBuilder[0].content', TextItem::TYPE_HTML, '<p>Read <a href="/x">more</a></p>'),
            new TextItem('seo.seoTitle', TextItem::TYPE_PLAIN, 'Applications | Laméco'),
            new TextItem('seo.seoDescription', TextItem::TYPE_PLAIN, 'Costs from $ 50, see {price}'),
            new TextItem('pageBuilder[1].content', TextItem::TYPE_HTML, '<p>A</p><!-- x --><p>B</p>'),
            new TextItem('pageBuilder[2].content', TextItem::TYPE_HTML, '<p><a href="{entry:6@1:url||https://orig.example}">Contact</a> us</p>'),
        ];

        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item->id] = $item;
        }

        return $keyed;
    }

    /**
     * @param array<string, string> $overrides
     * @return list<array{id: string, value: string}>
     */
    private function submission(array $overrides = []): array
    {
        $submission = [];

        foreach ($this->currentItems() as $id => $item) {
            $submission[] = ['id' => $id, 'value' => $overrides[$id] ?? $item->value];
        }

        return $submission;
    }

    /**
     * @param list<array{id: string|null, code: string, message: string}> $errors
     * @return list<array{0: string|null, 1: string}>
     */
    private static function codes(array $errors): array
    {
        return array_map(fn(array $error) => [$error['id'], $error['code']], $errors);
    }

    public function testTheUnchangedSubmissionIsValid(): void
    {
        self::assertSame([], (new TextImportValidator())->validate($this->currentItems(), $this->submission()));
    }

    public function testRewrittenTextIsValid(): void
    {
        $errors = (new TextImportValidator())->validate($this->currentItems(), $this->submission([
            'title' => 'Our applications',
            'intro' => 'A better intro',
            'pageBuilder[0].content' => '<p>Discover <a href="/x">all of it</a> today</p>',
        ]));

        self::assertSame([], $errors);
    }

    public function testUnknownIdIsRejected(): void
    {
        $submission = $this->submission();
        $submission[] = ['id' => 'somethingElse', 'value' => 'x'];

        self::assertSame([['somethingElse', 'unknown_id']], self::codes((new TextImportValidator())->validate($this->currentItems(), $submission)));
    }

    public function testItemWithoutAStringIdIsRejectedAsUnknown(): void
    {
        $submission = $this->submission();
        $submission[] = ['value' => 'x'];
        $submission[] = 'not an item';

        self::assertSame([[null, 'unknown_id'], [null, 'unknown_id']], self::codes((new TextImportValidator())->validate($this->currentItems(), $submission)));
    }

    public function testMissingIdIsRejected(): void
    {
        $submission = $this->submission();
        array_pop($submission);

        self::assertSame([['pageBuilder[2].content', 'missing_id']], self::codes((new TextImportValidator())->validate($this->currentItems(), $submission)));
    }

    public function testDuplicateIdIsRejected(): void
    {
        $submission = $this->submission();
        $submission[] = ['id' => 'title', 'value' => 'Again'];

        self::assertSame([['title', 'duplicate_id']], self::codes((new TextImportValidator())->validate($this->currentItems(), $submission)));
    }

    public function testEmptyAndNonStringValuesAreRejected(): void
    {
        $submission = $this->submission(['title' => "  \n "]);
        $submission[1]['value'] = null;

        $errors = (new TextImportValidator())->validate($this->currentItems(), $submission);

        self::assertSame([['title', 'empty_value'], ['intro', 'empty_value']], self::codes($errors));
    }

    public function testHtmlWithoutTextIsEmpty(): void
    {
        $errors = (new TextImportValidator())->validate($this->currentItems(), $this->submission([
            'pageBuilder[0].content' => '<p> <a href="/x">&nbsp;</a></p>',
        ]));

        self::assertSame([['pageBuilder[0].content', 'empty_value']], self::codes($errors));
    }

    public function testTooLongIsCountedInCharactersNotBytes(): void
    {
        $validator = new TextImportValidator();

        self::assertSame([], $validator->validate($this->currentItems(), $this->submission(['intro' => str_repeat('é', 20)])));
        self::assertSame(
            [['intro', 'too_long']],
            self::codes($validator->validate($this->currentItems(), $this->submission(['intro' => str_repeat('é', 21)]))),
        );
    }

    public function testHtmlInPlainIsRejected(): void
    {
        $errors = (new TextImportValidator())->validate($this->currentItems(), $this->submission(['title' => 'Our <b>applications</b>']));

        self::assertSame([['title', 'html_in_plain']], self::codes($errors));
    }

    public function testComparisonCharactersInPlainAreNotHtml(): void
    {
        self::assertSame([], (new TextImportValidator())->validate($this->currentItems(), $this->submission(['title' => 'Speed < 5 > 3'])));
    }

    public function testChangedHrefIsAStructureChange(): void
    {
        $errors = (new TextImportValidator())->validate($this->currentItems(), $this->submission([
            'pageBuilder[0].content' => '<p>Read <a href="/y">more</a></p>',
        ]));

        self::assertSame([['pageBuilder[0].content', 'html_structure_changed']], self::codes($errors));
    }

    public function testRemovedLinkIsAStructureChange(): void
    {
        $errors = (new TextImportValidator())->validate($this->currentItems(), $this->submission([
            'pageBuilder[0].content' => '<p>Read more</p>',
        ]));

        self::assertSame([['pageBuilder[0].content', 'html_structure_changed']], self::codes($errors));
    }

    public function testTwigInAValueIsRejected(): void
    {
        $errors = (new TextImportValidator())->validate($this->currentItems(), $this->submission([
            'title' => '{{ craft.app.config.general.securityKey }}',
            'intro' => '{% set x = 1 %}',
        ]));

        self::assertSame([['title', 'twig_in_value'], ['intro', 'twig_in_value']], self::codes($errors));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function reviewRuleProvider(): array
    {
        return [
            'R1 env var with braces' => ['seo.seoTitle', '${DB_PASSWORD}', 'forbidden_syntax'],
            'R1 bare env var' => ['seo.seoTitle', '$DB_PASSWORD', 'forbidden_syntax'],
            'R1 alias' => ['seo.seoTitle', '@root', 'forbidden_syntax'],
            'R1 object template' => ['seo.seoTitle', '{author.email}', 'forbidden_syntax'],
            'R1 closing brace only' => ['seo.seoTitle', 'Applications }', 'forbidden_syntax'],
            'R2 added reference tag in html' => ['pageBuilder[0].content', '<p>Contact {user:1:email}</p>', 'reference_tag_changed'],
            'R2 added reference tag in plain' => ['intro', 'Mail {user:1:email}', 'reference_tag_changed'],
            'R2+R3 changed fallback in href' => [
                'pageBuilder[2].content',
                '<p><a href="{entry:6@1:url||https://evil.example}">Contact</a> us today</p>',
                'reference_tag_changed',
            ],
            'R2 removed reference tag' => ['pageBuilder[2].content', '<p><a href="/contact">Contact</a> us</p>', 'reference_tag_changed'],
            'R4 comment smuggling' => ['pageBuilder[1].content', '<p>A</p><!--><script>alert(1)</script><!-- --><p>B</p>', 'html_structure_changed'],
            'R4 changed comment' => ['pageBuilder[1].content', '<p>A</p><!-- y --><p>B</p>', 'html_structure_changed'],
            'R6 unclosed tag in plain' => ['title', 'x <img src=x onerror=alert(1)//', 'html_in_plain'],
        ];
    }

    #[DataProvider('reviewRuleProvider')]
    public function testReviewRulesRejectSmuggling(string $id, string $value, string $code): void
    {
        $errors = (new TextImportValidator())->validate($this->currentItems(), $this->submission([$id => $value]));

        self::assertSame([[$id, $code]], self::codes($errors));
    }

    public function testR1AllowsCharactersTheOriginalAlreadyHas(): void
    {
        $errors = (new TextImportValidator())->validate($this->currentItems(), $this->submission([
            'seo.seoDescription' => 'From $ 60, see {price} today',
        ]));

        self::assertSame([], $errors);
    }

    public function testR1OnlyAppliesToSeoItems(): void
    {
        self::assertSame([], (new TextImportValidator())->validate($this->currentItems(), $this->submission(['intro' => 'Only $ 5'])));
        self::assertNull(TextImportValidator::forbiddenSyntax('@lameco on X', '@lameco on Bluesky'));
        self::assertSame('@', TextImportValidator::forbiddenSyntax('Follow @lameco', '@web'));
    }

    public function testR2ReferenceTagsAreComparedAsSortedLists(): void
    {
        self::assertSame(
            TextImportValidator::referenceTags('{asset:2:url} and {entry:6@1:url||https://x}'),
            TextImportValidator::referenceTags('{entry:6@1:url||https://x} then {asset:2:url}'),
        );
        self::assertNotSame(
            TextImportValidator::referenceTags('{entry:6@1:url||https://x}'),
            TextImportValidator::referenceTags('{entry:6@1:url||https://x} {entry:6@1:url||https://x}'),
        );
    }

    public function testEveryErrorIsReportedAtOnce(): void
    {
        $submission = $this->submission(['title' => '', 'pageBuilder[0].content' => '<p>x</p>']);
        array_splice($submission, 1, 1);
        $submission = array_values(array_filter($submission, fn(array $item) => !str_starts_with($item['id'], 'seo.') && !in_array($item['id'], ['pageBuilder[1].content', 'pageBuilder[2].content'], true)));
        $submission[] = ['id' => 'seo.seoTitle', 'value' => 'Applications | Laméco'];
        $submission[] = ['id' => 'seo.seoDescription', 'value' => 'Costs from $ 50, see {price}'];
        $submission[] = ['id' => 'pageBuilder[1].content', 'value' => '<p>A</p><!-- x --><p>B</p>'];
        $submission[] = ['id' => 'pageBuilder[2].content', 'value' => '<p><a href="{entry:6@1:url||https://orig.example}">Contact</a> us</p>'];
        $submission[] = ['id' => 'nope', 'value' => 'x'];

        $errors = (new TextImportValidator())->validate($this->currentItems(), $submission);

        self::assertSame([
            ['title', 'empty_value'],
            ['pageBuilder[0].content', 'html_structure_changed'],
            ['nope', 'unknown_id'],
            ['intro', 'missing_id'],
        ], self::codes($errors));
    }
}
