<?php

namespace lameco\rankroute\services;

use Craft;
use craft\base\Component;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use yii\web\BadRequestHttpException;

/**
 * `text/templates`: the kinds of page a new page (`text/create`) can be copied from in one
 * site, one per section and entry type, with the number of live entries and a few recent
 * ones as samples. Only what {@see TextCreateService::isCopyable()} accepts is listed, and
 * only when it has a live entry with a URL in that site. Never writes anything.
 *
 * @phpstan-type Sample array{elementId: int, title: string, url: string}
 * @phpstan-type Template array{
 *     section: array{handle: string, name: string, type: string},
 *     entryType: array{handle: string, name: string},
 *     liveEntries: int,
 *     samples: list<Sample>,
 * }
 */
class TextTemplatesService extends Component
{
    public const MAX_SAMPLES = 3;

    /**
     * @param mixed $siteId `siteId` query parameter; the primary site when null or empty
     * @return array{siteId: int, templates: list<Template>}
     * @throws BadRequestHttpException for a siteId that is not the id of a site
     */
    public function templates(mixed $siteId): array
    {
        $siteId = $this->siteId($siteId);
        $templates = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $siteSettings = $section->getSiteSettings()[$siteId] ?? null;

            if (!TextCreateService::isCopyableSection($section) || $siteSettings === null || !$siteSettings->hasUrls) {
                continue;
            }

            foreach ($section->getEntryTypes() as $entryType) {
                $template = $this->template($section, $entryType, $siteId);

                if ($template !== null) {
                    $templates[] = $template;
                }
            }
        }

        return ['siteId' => $siteId, 'templates' => self::sort($templates)];
    }

    /**
     * Most live entries first, then section name, then entry type name (both natural and
     * case-insensitive), then the handles, so the order is always the same.
     *
     * @param list<Template> $templates
     * @return list<Template>
     */
    public static function sort(array $templates): array
    {
        usort($templates, fn(array $a, array $b) => $b['liveEntries'] <=> $a['liveEntries']
            ?: strnatcasecmp($a['section']['name'], $b['section']['name'])
            ?: strnatcasecmp($a['entryType']['name'], $b['entryType']['name'])
            ?: strcmp($a['section']['handle'], $b['section']['handle'])
            ?: strcmp($a['entryType']['handle'], $b['entryType']['handle']));

        return $templates;
    }

    /**
     * @return Template|null null when no live entry of this kind has a URL in the site
     */
    private function template(Section $section, EntryType $entryType, int $siteId): ?array
    {
        $recent = $this->liveEntries($section, $entryType, $siteId)
            ->uri(':notempty:')
            ->orderBy(['entries.postDate' => SORT_DESC, 'elements.id' => SORT_DESC])
            ->limit(self::MAX_SAMPLES)
            ->all();
        $samples = [];

        foreach ($recent as $entry) {
            $url = $entry->getUrl();

            if (!TextCreateService::isCopyable($entry) || $url === null || $url === '') {
                continue;
            }

            $samples[] = ['elementId' => (int)$entry->id, 'title' => (string)$entry->title, 'url' => $url];
        }

        if ($samples === []) {
            return null;
        }

        return [
            'section' => ['handle' => (string)$section->handle, 'name' => (string)$section->name, 'type' => (string)$section->type],
            'entryType' => ['handle' => (string)$entryType->handle, 'name' => (string)$entryType->name],
            'liveEntries' => (int)$this->liveEntries($section, $entryType, $siteId)->count(),
            'samples' => $samples,
        ];
    }

    /**
     * Live top-level entries of one section and entry type in one site. Filtering on the
     * section leaves nested entries out (they have none); drafts and revisions are left
     * out by the element query itself.
     */
    private function liveEntries(Section $section, EntryType $entryType, int $siteId): EntryQuery
    {
        return Entry::find()
            ->sectionId($section->id)
            ->typeId($entryType->id)
            ->siteId($siteId)
            ->status(Entry::STATUS_LIVE);
    }

    /**
     * @throws BadRequestHttpException
     */
    private function siteId(mixed $siteId): int
    {
        $sites = Craft::$app->getSites();

        if ($siteId === null || $siteId === '') {
            return (int)$sites->getPrimarySite()->id;
        }

        if ((is_int($siteId) || (is_string($siteId) && ctype_digit($siteId))) && ($site = $sites->getSiteById((int)$siteId)) !== null) {
            return (int)$site->id;
        }

        throw new BadRequestHttpException(is_scalar($siteId) ? "Unknown siteId \"{$siteId}\"." : 'siteId must be the id of a site.');
    }
}
