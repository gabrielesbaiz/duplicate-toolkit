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
 * An immutable, fully typed description of how a model should be duplicated.
 *
 * Every mutator clones the instance, so an options object returned from a
 * model's duplicateOptions() may be derived from freely without one
 * duplication run leaking state into the next.
 */
final class DuplicateOptions
{
    /**
     * The columns that must not be copied onto the duplicate.
     *
     * @var array<int, string>
     */
    private array $excludedColumns = [];

    /**
     * The patterns matching columns that must not be copied onto the duplicate.
     *
     * @var array<int, string>
     */
    private array $excludedColumnPatterns = [];

    /**
     * The columns whose value must stay unique across the table.
     *
     * @var array<int, string>
     */
    private array $uniqueColumns = [];

    /**
     * The attribute overrides applied to the duplicate before it is saved.
     *
     * @var array<int, AttributeMutator>
     */
    private array $mutators = [];

    /**
     * The strategy for each relation, keyed by a plain or dot-nested name.
     *
     * @var array<string, RelationStrategy>
     */
    private array $relationStrategies = [];

    /**
     * The nested options for each relation, keyed by a plain or dot-nested name.
     *
     * A value may be an options object or a callback that receives the related
     * model's own options.
     *
     * @var array<string, DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions>
     */
    private array $relationOptions = [];

    /**
     * The custom handlers registered for individual relations.
     *
     * @var array<string, DuplicatesRelation|Closure>
     */
    private array $relationHandlers = [];

    /**
     * The only relations that may be duplicated, if the caller named any.
     *
     * @var array<int, string>|null
     */
    private ?array $relationAllowList = null;

    /**
     * The callbacks invoked on the duplicate before it is saved.
     *
     * @var array<int, Closure(Model, Model): void>
     */
    private array $beforeSave = [];

    /**
     * The callbacks invoked on the duplicate once it has been saved.
     *
     * @var array<int, Closure(Model, Model): void>
     */
    private array $afterSave = [];

    /**
     * The number of relation levels to follow.
     */
    private ?int $depth = null;

    /**
     * Indicates if the duplicate is saved without firing model events.
     */
    private ?bool $saveQuietly = null;

    /**
     * Indicates if trashed related records are copied as well.
     */
    private ?bool $copyTrashed = null;

    /**
     * Indicates if media library collections are copied as well.
     */
    private ?bool $copyMedia = null;

    /**
     * Indicates if the key of the source record is written to the duplicate.
     */
    private bool $trackProvenance = false;

    /**
     * The column the key of the source record is written to.
     */
    private ?string $provenanceColumn = null;

    /**
     * The strategy used to make unique columns unique.
     */
    private ?UniqueStrategy $uniqueStrategy = null;

    /**
     * Indicates if every write is rolled back once the run completes.
     */
    private bool $dryRun = false;

    /**
     * Indicates if exceeding the configured depth throws instead of truncating.
     */
    private bool $strictDepth = false;

    /**
     * Create a new options instance.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Create a new options instance under the 1.x name.
     */
    public static function instance(): self
    {
        return new self;
    }

    /**
     * Exclude the given columns from the duplicate.
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
     * Exclude every column matching one of the given regular expressions.
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
     * Declare the columns whose value must stay unique across the table.
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

    /**
     * Set the strategy used to make the unique columns unique.
     */
    public function uniqueUsing(UniqueStrategy $strategy): self
    {
        $clone = clone $this;
        $clone->uniqueStrategy = $strategy;

        return $clone;
    }

    /**
     * Force attribute values on the duplicate.
     *
     * A value may be a closure receiving the current value, the duplicate and
     * the source model, so it can be derived from the record being copied.
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

    /**
     * Append a string to a column on the duplicate.
     */
    public function suffix(string $column, string $suffix): self
    {
        $clone = clone $this;
        $clone->mutators[] = AttributeMutator::suffix($column, $suffix);

        return $clone;
    }

    /**
     * Prepend a string to a column on the duplicate.
     */
    public function prefix(string $column, string $prefix): self
    {
        $clone = clone $this;
        $clone->mutators[] = AttributeMutator::prefix($column, $prefix);

        return $clone;
    }

    /**
     * Search and replace within a column on the duplicate.
     */
    public function replace(string $column, string $search, string $replace): self
    {
        $clone = clone $this;
        $clone->mutators[] = AttributeMutator::replace($column, $search, $replace);

        return $clone;
    }

    /**
     * Derive the value of a column on the duplicate from a callback.
     *
     * @param  Closure(mixed, Model, Model): mixed  $callback
     */
    public function mutate(string $column, Closure $callback): self
    {
        $clone = clone $this;
        $clone->mutators[] = AttributeMutator::using($column, $callback);

        return $clone;
    }

    /**
     * Configure a single relation.
     *
     * The second argument is either a strategy or a callback refining the
     * options the related model declares for itself.
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
     * Duplicate only the given relations, skipping every other one.
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
     * Leave the given relations alone.
     *
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
     * Duplicate the related records of the given relations.
     *
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
     * Exclude columns on a per-relation basis.
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
     * Declare unique columns on a per-relation basis.
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

    /**
     * Set how many levels of relations are followed.
     */
    public function depth(int $depth): self
    {
        $clone = clone $this;
        $clone->depth = max(0, $depth);

        return $clone;
    }

    /**
     * Duplicate the root model only, leaving every relation alone.
     */
    public function shallow(): self
    {
        return $this->depth(0);
    }

    /**
     * Save the duplicate without firing Eloquent model events.
     *
     * @deprecated 2.0 Use quietly(). Removed in 3.0.
     */
    public function saveQuietly(bool $quietly = true): self
    {
        return $this->quietly($quietly);
    }

    /**
     * Duplicate the root model only, leaving every relation alone.
     *
     * @deprecated 2.0 Use shallow(). Removed in 3.0.
     */
    public function disableDeepDuplication(): self
    {
        return $this->shallow();
    }

    /**
     * Throw rather than silently truncate when the relation tree runs deeper
     * than the configured depth.
     */
    public function strictDepth(bool $strict = true): self
    {
        $clone = clone $this;
        $clone->strictDepth = $strict;

        return $clone;
    }

    /**
     * Save the duplicate without firing Eloquent model events.
     */
    public function quietly(bool $quietly = true): self
    {
        $clone = clone $this;
        $clone->saveQuietly = $quietly;

        return $clone;
    }

    /**
     * Copy the trashed related records alongside the duplicate.
     */
    public function withTrashed(bool $withTrashed = true): self
    {
        $clone = clone $this;
        $clone->copyTrashed = $withTrashed;

        return $clone;
    }

    /**
     * Copy the media library collections onto the duplicate.
     */
    public function withMedia(bool $withMedia = true): self
    {
        $clone = clone $this;
        $clone->copyMedia = $withMedia;

        return $clone;
    }

    /**
     * Write the key of the source record onto the duplicate.
     */
    public function trackProvenance(?string $column = null): self
    {
        $clone = clone $this;
        $clone->trackProvenance = true;
        $clone->provenanceColumn = $column;

        return $clone;
    }

    /**
     * Perform every write and then roll the whole run back.
     */
    public function dryRun(bool $dryRun = true): self
    {
        $clone = clone $this;
        $clone->dryRun = $dryRun;

        return $clone;
    }

    /**
     * Register a callback to run on the duplicate before it is saved.
     *
     * @param  Closure(Model, Model): void  $callback
     */
    public function beforeSave(Closure $callback): self
    {
        $clone = clone $this;
        $clone->beforeSave[] = $callback;

        return $clone;
    }

    /**
     * Register a callback to run on the duplicate once it has been saved.
     *
     * @param  Closure(Model, Model): void  $callback
     */
    public function afterSave(Closure $callback): self
    {
        $clone = clone $this;
        $clone->afterSave[] = $callback;

        return $clone;
    }

    /**
     * Merge another options object on top of this one, letting it win.
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

    /**
     * Get the columns that must not be copied onto the duplicate.
     *
     * @return array<int, string>
     */
    public function getExcludedColumns(): array
    {
        return $this->excludedColumns;
    }

    /**
     * Get the patterns matching columns that must not be copied.
     *
     * @return array<int, string>
     */
    public function getExcludedColumnPatterns(): array
    {
        return $this->excludedColumnPatterns;
    }

    /**
     * Get the columns whose value must stay unique across the table.
     *
     * @return array<int, string>
     */
    public function getUniqueColumns(): array
    {
        return $this->uniqueColumns;
    }

    /**
     * Get the attribute overrides applied to the duplicate.
     *
     * @return array<int, AttributeMutator>
     */
    public function getMutators(): array
    {
        return $this->mutators;
    }

    /**
     * Get the strategy declared for each relation.
     *
     * @return array<string, RelationStrategy>
     */
    public function getRelationStrategies(): array
    {
        return $this->relationStrategies;
    }

    /**
     * Get the only relations that may be duplicated, if any were named.
     *
     * @return array<int, string>|null
     */
    public function getRelationAllowList(): ?array
    {
        return $this->relationAllowList;
    }

    /**
     * Get the nested options declared for each relation.
     *
     * @return array<string, DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions>
     */
    public function getRelationOptions(): array
    {
        return $this->relationOptions;
    }

    /**
     * Get the custom handlers registered for individual relations.
     *
     * @return array<string, DuplicatesRelation|Closure>
     */
    public function getRelationHandlers(): array
    {
        return $this->relationHandlers;
    }

    /**
     * Get the callbacks invoked before the duplicate is saved.
     *
     * @return array<int, Closure(Model, Model): void>
     */
    public function getBeforeSaveCallbacks(): array
    {
        return $this->beforeSave;
    }

    /**
     * Get the callbacks invoked once the duplicate has been saved.
     *
     * @return array<int, Closure(Model, Model): void>
     */
    public function getAfterSaveCallbacks(): array
    {
        return $this->afterSave;
    }

    /**
     * Get the number of relation levels to follow.
     */
    public function getDepth(): ?int
    {
        return $this->depth;
    }

    /**
     * Determine if the duplicate is saved without firing model events.
     */
    public function shouldSaveQuietly(): ?bool
    {
        return $this->saveQuietly;
    }

    /**
     * Determine if trashed related records are copied as well.
     */
    public function shouldCopyTrashed(): ?bool
    {
        return $this->copyTrashed;
    }

    /**
     * Determine if media library collections are copied as well.
     */
    public function shouldCopyMedia(): ?bool
    {
        return $this->copyMedia;
    }

    /**
     * Determine if the key of the source record is written to the duplicate.
     */
    public function shouldTrackProvenance(): bool
    {
        return $this->trackProvenance;
    }

    /**
     * Get the column the key of the source record is written to.
     */
    public function getProvenanceColumn(): ?string
    {
        return $this->provenanceColumn;
    }

    /**
     * Get the strategy used to make the unique columns unique.
     */
    public function getUniqueStrategy(): ?UniqueStrategy
    {
        return $this->uniqueStrategy;
    }

    /**
     * Determine if every write is rolled back once the run completes.
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Determine if exceeding the configured depth should throw.
     */
    public function hasStrictDepth(): bool
    {
        return $this->strictDepth;
    }

    /**
     * Get the strategy for one relation, honouring the allow list.
     *
     * Null is returned when the caller expressed no preference at all, which
     * leaves the model attribute and the configured default to decide.
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
     * Get the options declared for a nested relation.
     *
     * Any deeper "a.b" entries are rebased onto the child, so a strategy set
     * on "versions.descriptions" reaches the descriptions of each version.
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
