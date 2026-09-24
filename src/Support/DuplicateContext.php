<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The mutable state threaded through a single duplication run: the current
 * depth, the models already visited, and the old key to new key map that the
 * run produces along the way.
 */
final class DuplicateContext
{
    /**
     * The old primary key to new primary key map, keyed by model class.
     *
     * @var array<class-string<Model>, array<array-key, array-key>>
     */
    private array $idMap = [];

    /**
     * The models already duplicated during this run, used to detect cycles.
     *
     * @var array<string, true>
     */
    private array $visited = [];

    /**
     * The number of records created, keyed by model class.
     *
     * @var array<class-string<Model>, int>
     */
    private array $counts = [];

    /**
     * Create a new duplicate context instance.
     */
    public function __construct(
        public readonly int $maxDepth,
        public readonly bool $dryRun = false,
        public readonly bool $strictDepth = false,
        private int $depth = 0,
    ) {}

    /**
     * Get the level of the relation tree currently being duplicated.
     */
    public function depth(): int
    {
        return $this->depth;
    }

    /**
     * Move one level deeper into the relation tree.
     */
    public function descend(): void
    {
        $this->depth++;
    }

    /**
     * Move one level back up the relation tree.
     */
    public function ascend(): void
    {
        $this->depth--;
    }

    /**
     * Determine if the run may descend another level.
     */
    public function canDescend(): bool
    {
        return $this->depth < $this->maxDepth;
    }

    /**
     * Mark the given model as visited by this run.
     */
    public function markVisited(Model $model): void
    {
        $this->visited[$this->signature($model)] = true;
    }

    /**
     * Determine if the given model has already been visited by this run.
     */
    public function hasVisited(Model $model): bool
    {
        return isset($this->visited[$this->signature($model)]);
    }

    /**
     * Record that the source model was duplicated into the given copy.
     */
    public function record(Model $source, Model $duplicate): void
    {
        $sourceKey = $source->getKey();
        $duplicateKey = $duplicate->getKey();

        if (is_scalar($sourceKey) && is_scalar($duplicateKey)) {
            /** @var array-key $sourceKey */
            /** @var array-key $duplicateKey */
            $this->idMap[$source::class][$sourceKey] = $duplicateKey;
        }

        $this->counts[$source::class] = ($this->counts[$source::class] ?? 0) + 1;
    }

    /**
     * Get the old primary key to new primary key map for the given model class.
     *
     * @param  class-string<Model>|null  $class
     * @return array<array-key, array-key>|array<class-string<Model>, array<array-key, array-key>>
     */
    public function idMap(?string $class = null): array
    {
        if ($class === null) {
            return $this->idMap;
        }

        return $this->idMap[$class] ?? [];
    }

    /**
     * Get the new primary key created for the given source record.
     */
    public function newKeyFor(Model $source): int|string|null
    {
        $key = $source->getKey();

        if (! is_scalar($key)) {
            return null;
        }

        /** @var array-key $key */
        $mapped = $this->idMap[$source::class][$key] ?? null;

        return is_int($mapped) || is_string($mapped) ? $mapped : null;
    }

    /**
     * Get the number of records created, keyed by model class.
     *
     * @return array<class-string<Model>, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * Get the total number of records created by this run.
     */
    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * Get the value that identifies a model across the whole run.
     */
    private function signature(Model $model): string
    {
        return $model::class.':'.Cast::toString($model->getKey());
    }
}
