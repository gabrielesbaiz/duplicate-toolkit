<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit;

use Closure;
use Gabrielesbaiz\DuplicateToolkit\Contracts\DuplicatesRelation;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Enums\UniqueStrategy;
use Gabrielesbaiz\DuplicateToolkit\Support\AttributeMutator;
use Gabrielesbaiz\DuplicateToolkit\Support\Cast;
use Gabrielesbaiz\DuplicateToolkit\Support\DuplicateContext;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Immutable, fully typed description of how a model should be duplicated.
 *
 * Every mutator clones the instance, so an options object returned from a
 * model's duplicateOptions() can be safely derived from without leaking state
 * between duplication runs.
 */
final class DuplicateOptions
{
    /** @var array<int, string> */
    private array $excludedColumns = [];

    /** @var array<int, string> */
    private array $excludedColumnPatterns = [];

    /** @var array<int, string> */
    private array $uniqueColumns = [];

    /** @var array<int, AttributeMutator> */
    private array $mutators = [];

    /**
     * Relation name (optionally dot-nested) => strategy.
     *
     * @var array<string, RelationStrategy>
     */
    private array $relationStrategies = [];

    /**
     * Relation name (optionally dot-nested) => nested options or a callback
     * receiving the related model's own options.
     *
     * @var array<string, DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions>
     */
    private array $relationOptions = [];

    /**
     * @var array<string, DuplicatesRelation|Closure>
     */
    private array $relationHandlers = [];

    /** @var array<int, string>|null */
    private ?array $relationAllowList = null;

    /** @var array<int, Closure(Model, Model): void> */
    private array $beforeSave = [];

    /** @var array<int, Closure(Model, Model): void> */
    private array $afterSave = [];

    private ?int $depth = null;

    private ?bool $saveQuietly = null;

    private ?bool $copyTrashed = null;

    private ?bool $copyMedia = null;

    private bool $trackProvenance = false;

    private ?string $provenanceColumn = null;

    private ?UniqueStrategy $uniqueStrategy = null;

    private bool $dryRun = false;

    private bool $strictDepth = false;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Backwards friendly alias of make().
     */
    public static function instance(): self
    {
        return new self;
    }

    // -------------------------------------------------------------------
    // Columns
    // -------------------------------------------------------------------

    /**
     * Columns that must not be copied onto the duplicate.
     *
     * @param  string|array<int, string>  ...$columns
     */
    public function excludeColumns(string|array ...$columns): self
    {
        $clone = clone $this;
        $clone->excludedColumns = array_values(array_unique([
            ...$this->excludedColumns,
            ...Cast::toStringList(Arr::flatten($columns)),
        ]));

        return $clone;
    }

    /**
     * Exclude every column matching a regular expression, e.g. '/_count$/'.
     */
    public function excludeColumnsMatching(string ...$patterns): self
    {
        $clone = clone $this;
        $clone->excludedColumnPatterns = array_values(array_unique([
            ...$this->excludedColumnPatterns,
            ...$patterns,
        ]));

        return $clone;
    }

    /**
     * Columns whose value must stay unique across the table.
     *
     * @param  string|array<int, string>  ...$columns
     */
    public function uniqueColumns(string|array ...$columns): self
    {
        $clone = clone $this;
        $clone->uniqueColumns = array_values(array_unique([
            ...$this->uniqueColumns,
            ...Cast::toStringList(Arr::flatten($columns)),
        ]));

        return $clone;
    }

    public function uniqueUsing(UniqueStrategy $strategy): self
    {
        $clone = clone $this;
        $clone->uniqueStrategy = $strategy;

        return $clone;
    }

    // -------------------------------------------------------------------
    // Attribute overrides
    // -------------------------------------------------------------------

    /**
     * Force attribute values on the duplicate. Values may be closures
     * receiving (mixed $current, Model $duplicate, Model $source).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;

        foreach ($attributes as $column => $value) {
            $clone->mutators[] = AttributeMutator::set($column, $value);
        }

        return $clone;
    }

    public function suffix(string $column, string $suffix): self
    {
        $clone = clone $this;
        $clone->mutators[] = AttributeMutator::suffix($column, $suffix);

        return $clone;
    }

    public function prefix(string $column, string $prefix): self
    {
        $clone = clone $this;
        $clone->mutators[] = AttributeMutator::prefix($column, $prefix);

        return $clone;
    }

    public function replace(string $column, string $search, string $replace): self
    {
        $clone = clone $this;
        $clone->mutators[] = AttributeMutator::replace($column, $search, $replace);

        return $clone;
    }

    /**
     * @param  Closure(mixed, Model, Model): mixed  $callback
     */
    public function mutate(string $column, Closure $callback): self
    {
        $clone = clone $this;
        $clone->mutators[] = AttributeMutator::using($column, $callback);

        return $clone;
    }

    // -------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------

    /**
     * Configure one relation. The second argument is either a strategy or a
     * callback refining the related model's own options.
     *
     * @param  RelationStrategy|DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions  $strategy
     */
    public function relation(string $relation, RelationStrategy|DuplicateOptions|Closure $strategy = RelationStrategy::Copy): self
    {
        $clone = clone $this;

        if ($strategy instanceof RelationStrategy) {
            $clone->relationStrategies[$relation] = $strategy;

            return $clone;
        }

        $clone->relationStrategies[$relation] = RelationStrategy::Copy;
        $clone->relationOptions[$relation] = $strategy;

        return $clone;
    }

    /**
     * Duplicate only these relations; every other relation is skipped.
     *
     * @param  string|array<int, string>  ...$relations
     */
    public function onlyRelations(string|array ...$relations): self
    {
        $clone = clone $this;

        $names = Cast::toStringList(Arr::flatten($relations));

        foreach ($names as $relation) {
            $clone->relationStrategies[$relation] = RelationStrategy::Copy;
        }

        $clone->relationAllowList = $names;

        return $clone;
    }

    /**
     * @param  string|array<int, string>  ...$relations
     */
    public function excludeRelations(string|array ...$relations): self
    {
        $clone = clone $this;

        foreach (Cast::toStringList(Arr::flatten($relations)) as $relation) {
            $clone->relationStrategies[$relation] = RelationStrategy::Skip;
        }

        return $clone;
    }

    /**
     * Attach the original related records instead of copying them.
     *
     * @param  string|array<int, string>  ...$relations
     */
    public function referenceRelations(string|array ...$relations): self
    {
        $clone = clone $this;

        foreach (Cast::toStringList(Arr::flatten($relations)) as $relation) {
            $clone->relationStrategies[$relation] = RelationStrategy::Reference;
        }

        return $clone;
    }

    /**
     * @param  string|array<int, string>  ...$relations
     */
    public function copyRelations(string|array ...$relations): self
    {
        $clone = clone $this;

        foreach (Cast::toStringList(Arr::flatten($relations)) as $relation) {
            $clone->relationStrategies[$relation] = RelationStrategy::Copy;
        }

        return $clone;
    }

    /**
     * Columns excluded on a per-relation basis.
     *
     * @param  array<string, array<int, string>>  $columns
     */
    public function excludeRelationColumns(array $columns): self
    {
        $clone = clone $this;

        foreach ($columns as $relation => $relationColumns) {
            $clone->relationOptions[$relation] = static fn (self $options): self => $options
                ->excludeColumns($relationColumns);
        }

        return $clone;
    }

    /**
     * Unique columns on a per-relation basis.
     *
     * @param  array<string, array<int, string>>  $columns
     */
    public function uniqueRelationColumns(array $columns): self
    {
        $clone = clone $this;

        foreach ($columns as $relation => $relationColumns) {
            $clone->relationOptions[$relation] = static fn (self $options): self => $options
                ->uniqueColumns($relationColumns);
        }

        return $clone;
    }

    /**
     * Hand a relation over to a custom handler.
     *
     * @param  DuplicatesRelation|Closure(Model, Model, RelationMeta, DuplicateContext): void  $handler
     */
    public function relationUsing(string $relation, DuplicatesRelation|Closure $handler): self
    {
        $clone = clone $this;
        $clone->relationHandlers[$relation] = $handler;

        return $clone;
    }

    // -------------------------------------------------------------------
    // Behaviour
    // -------------------------------------------------------------------

    public function depth(int $depth): self
    {
        $clone = clone $this;
        $clone->depth = max(0, $depth);

        return $clone;
    }

    /**
     * Duplicate the root model only.
     */
    public function shallow(): self
    {
        return $this->depth(0);
    }

    /**
     * Throw instead of silently truncating when the relation tree is deeper
     * than the configured depth.
     */
    public function strictDepth(bool $strict = true): self
    {
        $clone = clone $this;
        $clone->strictDepth = $strict;

        return $clone;
    }

    public function quietly(bool $quietly = true): self
    {
        $clone = clone $this;
        $clone->saveQuietly = $quietly;

        return $clone;
    }

    public function withTrashed(bool $withTrashed = true): self
    {
        $clone = clone $this;
        $clone->copyTrashed = $withTrashed;

        return $clone;
    }

    public function withMedia(bool $withMedia = true): self
    {
        $clone = clone $this;
        $clone->copyMedia = $withMedia;

        return $clone;
    }

    public function trackProvenance(?string $column = null): self
    {
        $clone = clone $this;
        $clone->trackProvenance = true;
        $clone->provenanceColumn = $column;

        return $clone;
    }

    public function dryRun(bool $dryRun = true): self
    {
        $clone = clone $this;
        $clone->dryRun = $dryRun;

        return $clone;
    }

    /**
     * @param  Closure(Model, Model): void  $callback
     */
    public function beforeSave(Closure $callback): self
    {
        $clone = clone $this;
        $clone->beforeSave[] = $callback;

        return $clone;
    }

    /**
     * @param  Closure(Model, Model): void  $callback
     */
    public function afterSave(Closure $callback): self
    {
        $clone = clone $this;
        $clone->afterSave[] = $callback;

        return $clone;
    }

    /**
     * Merge another options object on top of this one. The incoming values win.
     */
    public function merge(self $other): self
    {
        $clone = clone $this;

        $clone->excludedColumns = array_values(array_unique([...$this->excludedColumns, ...$other->excludedColumns]));
        $clone->excludedColumnPatterns = array_values(array_unique([...$this->excludedColumnPatterns, ...$other->excludedColumnPatterns]));
        $clone->uniqueColumns = array_values(array_unique([...$this->uniqueColumns, ...$other->uniqueColumns]));
        $clone->mutators = [...$this->mutators, ...$other->mutators];
        $clone->relationStrategies = [...$this->relationStrategies, ...$other->relationStrategies];
        $clone->relationOptions = [...$this->relationOptions, ...$other->relationOptions];
        $clone->relationHandlers = [...$this->relationHandlers, ...$other->relationHandlers];
        $clone->beforeSave = [...$this->beforeSave, ...$other->beforeSave];
        $clone->afterSave = [...$this->afterSave, ...$other->afterSave];
        $clone->relationAllowList = $other->relationAllowList ?? $this->relationAllowList;
        $clone->depth = $other->depth ?? $this->depth;
        $clone->saveQuietly = $other->saveQuietly ?? $this->saveQuietly;
        $clone->copyTrashed = $other->copyTrashed ?? $this->copyTrashed;
        $clone->copyMedia = $other->copyMedia ?? $this->copyMedia;
        $clone->uniqueStrategy = $other->uniqueStrategy ?? $this->uniqueStrategy;
        $clone->provenanceColumn = $other->provenanceColumn ?? $this->provenanceColumn;
        $clone->trackProvenance = $other->trackProvenance || $this->trackProvenance;
        $clone->dryRun = $other->dryRun || $this->dryRun;
        $clone->strictDepth = $other->strictDepth || $this->strictDepth;

        return $clone;
    }

    // -------------------------------------------------------------------
    // Accessors (no magic __get: everything is typed and analysable)
    // -------------------------------------------------------------------

    /** @return array<int, string> */
    public function getExcludedColumns(): array
    {
        return $this->excludedColumns;
    }

    /** @return array<int, string> */
    public function getExcludedColumnPatterns(): array
    {
        return $this->excludedColumnPatterns;
    }

    /** @return array<int, string> */
    public function getUniqueColumns(): array
    {
        return $this->uniqueColumns;
    }

    /** @return array<int, AttributeMutator> */
    public function getMutators(): array
    {
        return $this->mutators;
    }

    /** @return array<string, RelationStrategy> */
    public function getRelationStrategies(): array
    {
        return $this->relationStrategies;
    }

    /** @return array<int, string>|null */
    public function getRelationAllowList(): ?array
    {
        return $this->relationAllowList;
    }

    /** @return array<string, DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions> */
    public function getRelationOptions(): array
    {
        return $this->relationOptions;
    }

    /** @return array<string, DuplicatesRelation|Closure> */
    public function getRelationHandlers(): array
    {
        return $this->relationHandlers;
    }

    /** @return array<int, Closure(Model, Model): void> */
    public function getBeforeSaveCallbacks(): array
    {
        return $this->beforeSave;
    }

    /** @return array<int, Closure(Model, Model): void> */
    public function getAfterSaveCallbacks(): array
    {
        return $this->afterSave;
    }

    public function getDepth(): ?int
    {
        return $this->depth;
    }

    public function shouldSaveQuietly(): ?bool
    {
        return $this->saveQuietly;
    }

    public function shouldCopyTrashed(): ?bool
    {
        return $this->copyTrashed;
    }

    public function shouldCopyMedia(): ?bool
    {
        return $this->copyMedia;
    }

    public function shouldTrackProvenance(): bool
    {
        return $this->trackProvenance;
    }

    public function getProvenanceColumn(): ?string
    {
        return $this->provenanceColumn;
    }

    public function getUniqueStrategy(): ?UniqueStrategy
    {
        return $this->uniqueStrategy;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function hasStrictDepth(): bool
    {
        return $this->strictDepth;
    }

    /**
     * Strategy for one relation, honouring the allow list, or null when the
     * caller expressed no preference.
     */
    public function strategyFor(string $relation): ?RelationStrategy
    {
        if (isset($this->relationStrategies[$relation])) {
            return $this->relationStrategies[$relation];
        }

        if ($this->relationAllowList !== null && ! in_array($relation, $this->relationAllowList, true)) {
            return RelationStrategy::Skip;
        }

        return null;
    }

    /**
     * Options declared for a nested relation, plus any deeper "a.b" entries
     * rebased onto the child.
     */
    public function forRelation(string $relation): ?self
    {
        $nested = null;

        if (isset($this->relationOptions[$relation])) {
            $declared = $this->relationOptions[$relation];
            $nested = $declared instanceof self ? $declared : $declared(self::make());
        }

        $prefix = $relation.'.';
        $rebased = self::make();
        $hasRebased = false;

        foreach ($this->relationStrategies as $key => $strategy) {
            if (str_starts_with($key, $prefix)) {
                $rebased = $rebased->relation(substr($key, strlen($prefix)), $strategy);
                $hasRebased = true;
            }
        }

        foreach ($this->relationOptions as $key => $options) {
            if (str_starts_with($key, $prefix)) {
                $rebased = $rebased->relation(substr($key, strlen($prefix)), $options);
                $hasRebased = true;
            }
        }

        if (! $hasRebased) {
            return $nested;
        }

        return $nested instanceof self ? $nested->merge($rebased) : $rebased;
    }
}
