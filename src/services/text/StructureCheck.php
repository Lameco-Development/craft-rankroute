<?php

namespace lameco\rankroute\services\text;

use craft\base\Component;
use craft\base\ElementInterface;
use lameco\rankroute\dto\StructureCheckResult;
use lameco\rankroute\Plugin;

/**
 * Canonical element vs draft: equal {@see StructureSnapshot}s, or the list of differences.
 */
class StructureCheck extends Component
{
    /** Keeps a broken draft's diff readable in an n8n execution log. */
    public const MAX_DIFFERENCES = 50;

    public function check(ElementInterface $canonical, ElementInterface $draft): StructureCheckResult
    {
        $snapshot = Plugin::getInstance()->structureSnapshot;

        return $this->compare($snapshot->build($canonical), $snapshot->build($draft));
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function compare(array $before, array $after): StructureCheckResult
    {
        $differences = [];
        $this->diff($before, $after, '', $differences);

        return new StructureCheckResult($differences === [], array_slice($differences, 0, self::MAX_DIFFERENCES));
    }

    /**
     * @param list<array{path: string, before: mixed, after: mixed}> $differences
     */
    private function diff(mixed $before, mixed $after, string $path, array &$differences): void
    {
        if (!is_array($before) || !is_array($after)) {
            if ($before !== $after) {
                $differences[] = ['path' => $path, 'before' => $before, 'after' => $after];
            }

            return;
        }

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $childPath = is_int($key) ? "{$path}[{$key}]" : ($path === '' ? (string)$key : "{$path}.{$key}");

            if (!array_key_exists($key, $before) || !array_key_exists($key, $after)) {
                $differences[] = ['path' => $childPath, 'before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
                continue;
            }

            $this->diff($before[$key], $after[$key], $childPath, $differences);
        }
    }
}
