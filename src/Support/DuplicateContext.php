<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Mutable state threaded through a single duplication run: the current depth,
 * the models already visited (cycle detection) and the old key => new key map
 * produced along the way.
 */
final class DuplicateContext
{
    /**
     * @var array<class-string<Model>, array<array-key, array-key>>
     */
    private array $idMap = [];

    /**
     * @var array<string, true>
     */
    private array $visited = [];

    /**
     * @var array<class-string<Model>, int>
     */
    private array $counts = [];

    public function __construct(
        public readonly int $maxDepth,
        public readonly bool $dryRun = false,
        public readonly bool $strictDepth = false,
        private int $depth = 0,
    ) {}

    public function depth(): int
    {
        return $this->depth;
    }

    public function descend(): void
    {
        $this->depth++;
    }

    public function ascend(): void
    {
        $this->depth--;
    }

    public function canDescend(): bool
    {
        return $this->depth < $this->maxDepth;
    }

    public function markVisited(Model $model): void
    {
        $this->visited[$this->signature($model)] = true;
    }

    public function hasVisited(Model $model): bool
    {
        return isset($this->visited[$this->signature($model)]);
    }

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
     * @return array<class-string<Model>, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    private function signature(Model $model): string
    {
        return $model::class.':'.Cast::toString($model->getKey());
    }
}
