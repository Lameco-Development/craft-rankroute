<?php

namespace lameco\rankroute\services\text;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\models\Volume;
use yii\web\ServerErrorHttpException;

/**
 * The one image a new page gets in place of every image of its source: a bundled PNG that
 * says "RankRoute placeholder, replace this image", uploaded once into the site's asset
 * library and reused by every new page after that.
 *
 * It lives in the root folder of the volume named by `textFlow.placeholderVolume` in
 * `config/rankroute.php`, or of the first volume when that is not set. It is found again
 * by its filename (Craft may have suffixed it to avoid a clash with an orphaned file).
 */
class PlaceholderImage extends Component
{
    public const FILENAME = 'rankroute-placeholder.png';

    /** Title and alt text of the asset: what the editor sees in the asset library and fields. */
    public const TITLE = 'RankRoute placeholder: vervang deze afbeelding';

    /** Craft reference tags to an asset, e.g. `{asset:12:url||https://…}` or `{asset:12@1:transform:hero}`. */
    private const ASSET_REFERENCE_PATTERN = '/\{asset:\d+(?:@\d+)?:([^{}|]+)(?:\|\|[^{}]*)?\}/';

    /**
     * @var string|null Handle of the volume for the placeholder; the first volume when null.
     */
    public ?string $volume = null;

    public function init(): void
    {
        parent::init();

        $config = Craft::$app->getConfig()->getConfigFromFile('rankroute');
        $volume = is_array($config) ? ($config['textFlow']['placeholderVolume'] ?? null) : null;

        if (is_string($volume) && $volume !== '') {
            $this->volume = $volume;
        }
    }

    /**
     * The placeholder asset, uploaded when it does not exist yet.
     *
     * @throws ServerErrorHttpException when there is no volume to put it in
     */
    public function findOrCreate(): Asset
    {
        $volume = $this->volume();
        $mutex = Craft::$app->getMutex();
        $lock = 'rankroute-placeholder-image';

        if (!$mutex->acquire($lock, 30)) {
            throw new \RuntimeException('Could not acquire the placeholder image lock.');
        }

        try {
            return $this->find($volume) ?? $this->upload($volume);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * @throws ServerErrorHttpException when the configured volume does not exist, or there is none
     */
    public function volume(): Volume
    {
        $volumes = Craft::$app->getVolumes();

        if ($this->volume !== null) {
            return $volumes->getVolumeByHandle($this->volume)
                ?? throw new ServerErrorHttpException("The placeholder volume \"{$this->volume}\" (textFlow.placeholderVolume) does not exist.");
        }

        return $volumes->getAllVolumes()[0]
            ?? throw new ServerErrorHttpException('There is no asset volume to put the RankRoute placeholder image in.');
    }

    public function find(Volume $volume): ?Asset
    {
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

        if ($folder === null) {
            return null;
        }

        return Asset::find()
            ->folderId($folder->id)
            ->filename(pathinfo(self::FILENAME, PATHINFO_FILENAME) . '*')
            ->kind(Asset::KIND_IMAGE)
            ->status(null)
            ->orderBy(['elements.id' => SORT_ASC])
            ->one();
    }

    /**
     * Every asset reference tag in an HTML value pointed at the placeholder, keeping what
     * it renders (`url`, a transform) and replacing the fallback with the placeholder's URL.
     */
    public static function replaceAssetReferences(string $html, int $assetId, ?string $url): string
    {
        $fallback = $url !== null && $url !== '' ? '||' . $url : '';

        return preg_replace_callback(
            self::ASSET_REFERENCE_PATTERN,
            fn(array $match) => '{asset:' . $assetId . ':' . $match[1] . $fallback . '}',
            $html,
        ) ?? $html;
    }

    public static function hasAssetReferences(string $html): bool
    {
        return preg_match(self::ASSET_REFERENCE_PATTERN, $html) === 1;
    }

    private function upload(Volume $volume): Asset
    {
        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)
            ?? throw new ServerErrorHttpException("The placeholder volume \"{$volume->handle}\" has no root folder.");

        // Craft moves the temp file into the volume, so hand it a copy of the bundled one.
        $tempFile = tempnam(sys_get_temp_dir(), 'rankroute-placeholder');

        if ($tempFile === false || !copy(dirname(__DIR__, 2) . '/resources/' . self::FILENAME, $tempFile)) {
            throw new \RuntimeException('Could not copy the bundled placeholder image.');
        }

        $asset = new Asset();
        $asset->tempFilePath = $tempFile;
        $asset->setFilename(self::FILENAME);
        $asset->newFolderId = $folder->id;
        $asset->volumeId = $volume->id;
        $asset->avoidFilenameConflicts = true;
        $asset->title = self::TITLE;
        $asset->alt = self::TITLE;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new \RuntimeException('Could not save the placeholder image: ' . implode(', ', $asset->getErrorSummary(true)));
        }

        return $asset;
    }
}
