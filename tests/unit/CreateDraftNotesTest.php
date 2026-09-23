<?php

namespace lameco\rankroute\tests\unit;

use lameco\rankroute\services\TextCreateService;
use lameco\rankroute\services\TextImportService;
use PHPUnit\Framework\TestCase;

/**
 * The notes `text/create` stores on a new page: the idempotency key and what `text/verify`
 * and a replay need.
 */
final class CreateDraftNotesTest extends TestCase
{
    private const META = [
        'sourceElementId' => 51,
        'siteId' => 1,
        'slug' => 'industrial-applications',
        'placeholderAssetId' => 77,
        'changedItems' => ['title'],
        'placeholders' => ['image'],
    ];

    public function testNotesCarryTheKeyThenTheMetadata(): void
    {
        self::assertSame(
            "rankroute:gap-12\n{\"flow\":\"create\",\"sourceElementId\":51,\"siteId\":1,\"slug\":\"industrial-applications\",\"placeholderAssetId\":77,\"changedItems\":[\"title\"],\"placeholders\":[\"image\"]}",
            TextCreateService::draftNotes('gap-12', self::META),
        );
    }

    public function testNotesRoundTripWithAndWithoutAKey(): void
    {
        self::assertSame(['key' => 'gap-12', 'meta' => ['flow' => 'create', ...self::META]], TextCreateService::parseDraftNotes(TextCreateService::draftNotes('gap-12', self::META)));
        self::assertSame(['key' => null, 'meta' => ['flow' => 'create', ...self::META]], TextCreateService::parseDraftNotes(TextCreateService::draftNotes(null, self::META)));
    }

    public function testOtherNotesAreNotANewPage(): void
    {
        self::assertNull(TextCreateService::parseDraftNotes(null));
        self::assertNull(TextCreateService::parseDraftNotes('Editor notes about this draft'));
        self::assertNull(TextCreateService::parseDraftNotes(TextImportService::draftNotes('run-page-1', 1, ['title'])));
        self::assertNull(TextCreateService::parseDraftNotes("rankroute:gap-12\n{\"flow\":\"create\",\"siteId\":1}"));
        self::assertNull(TextCreateService::parseDraftNotes("rankroute:gap-12\nnot json"));
    }
}
