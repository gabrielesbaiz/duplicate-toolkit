<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit;

use Closure;
use Gabrielesbaiz\DuplicateToolkit\Contracts\DuplicatesRelation;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Enums\UniqueStrategy;
use Gabrielesbaiz\DuplicateToolkit\Jobs\DuplicateModel;
use Gabrielesbaiz\DuplicateToolkit\Results\DuplicateResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * The fluent builder returned by Duplicate::of() and $model->duplicator().
 *
 * Every option method mirrors one on DuplicateOptions, and is spelled out
 * here rather than proxied so that editors and static analysis see the same
 * vocabulary on both objects.
 *
 * @template TModel of Model
 *
 * @mixin DuplicateOptions
 */
class PendingDuplicate
{
    /**
     * The options the duplication will run with.
     */
    protected DuplicateOptions $options;

    /**
     * The queue connection the job should be dispatched on.
     */
    protected ?string $connection = null;

    /**
     * The queue the job should be dispatched to.
     */
    protected ?string $queue = null;

    /**
     * Create a new pending duplicate instance.
     *
     * @param  TModel  $model
     */
    public function __construct(
        protected Model $model,
        protected Duplicator $duplicator,
        ?DuplicateOptions $options = null,
    ) {
        $this->options = $options ?? DuplicateOptions::make();
    }

    /**
     * Replace the options wholesale, or refine them with a callback.
     *
     * @param  DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions  $options
     */
    public function withOptions(DuplicateOptions|Closure $options): static
    {
        $this->options = $options instanceof DuplicateOptions
            ? $options
            : $options($this->options);

        return $this;
    }

    /**
     * Get the options the duplication will run with.
     */
    public function options(): DuplicateOptions
    {
        return $this->options;
    }

    /**
     * Get the model that is about to be duplicated.
     *
     * @return TModel
     */
    public function model(): Model
    {
        return $this->model;
    }

    /**
     * Execute the duplication and return the full result of the run.
     *
     * @return DuplicateResult<TModel>
     */
    public function execute(): DuplicateResult
    {
        return $this->duplicator->run($this->model, $this->options);
    }

    /**
     * Execute the duplication and return only the new model.
     *
     * @return TModel
     */
    public function save(): Model
    {
        return $this->execute()->model;
    }

    /**
     * Perform every write and roll back, reporting what would have happened.
     *
     * @return DuplicateResult<TModel>
     */
    public function preview(): DuplicateResult
    {
        return $this->duplicator->run($this->model, $this->options->dryRun());
    }

    /**
     * Set the queue connection the job should be dispatched on.
     */
    public function onConnection(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Set the queue the job should be dispatched to.
     */
    public function onQueue(?string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Dispatch the duplication to the queue.
     */
    public function dispatch(): PendingDispatch
    {
        $pending = DuplicateModel::dispatch($this->model, $this->options);

        // Here we only override what the caller actually named, since the job
        // already falls back to the connection and queue the package was
        // configured with.
        if ($this->connection !== null) {
            $pending->onConnection($this->connection);
        }

        if ($this->queue !== null) {
            $pending->onQueue($this->queue);
        }

        return $pending;
    }

    /**
     * Exclude the given columns from the duplicate.
     *
     * @param  string|array<int, string>  ...$columns
     */
    public function excludeColumns(string|array ...$columns): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->excludeColumns(...$columns));
    }

    /**
     * Exclude every column matching one of the given regular expressions.
     */
    public function excludeColumnsMatching(string ...$patterns): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->excludeColumnsMatching(...$patterns));
    }

    /**
     * Declare the columns whose value must stay unique across the table.
     *
     * @param  string|array<int, string>  ...$columns
     */
    public function uniqueColumns(string|array ...$columns): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->uniqueColumns(...$columns));
    }

    /**
     * Set the strategy used to make the unique columns unique.
     */
    public function uniqueUsing(UniqueStrategy $strategy): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->uniqueUsing($strategy));
    }

    /**
     * Force attribute values on the duplicate.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function withAttributes(array $attributes): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->withAttributes($attributes));
    }

    /**
     * Append a string to a column on the duplicate.
     */
    public function suffix(string $column, string $suffix): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->suffix($column, $suffix));
    }

    /**
     * Prepend a string to a column on the duplicate.
     */
    public function prefix(string $column, string $prefix): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->prefix($column, $prefix));
    }

    /**
     * Search and replace within a column on the duplicate.
     */
    public function replace(string $column, string $search, string $replace): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->replace($column, $search, $replace));
    }

    /**
     * Derive the value of a column on the duplicate from a callback.
     *
     * @param  Closure(mixed, Model, Model): mixed  $callback
     */
    public function mutate(string $column, Closure $callback): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->mutate($column, $callback));
    }

    /**
     * Configure a single relation.
     *
     * @param  RelationStrategy|DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions  $strategy
     */
    public function relation(string $relation, RelationStrategy|DuplicateOptions|Closure $strategy = RelationStrategy::Copy): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->relation($relation, $strategy));
    }

    /**
     * Duplicate only the given relations, skipping every other one.
     *
     * @param  string|array<int, string>  ...$relations
     */
    public function onlyRelations(string|array ...$relations): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->onlyRelations(...$relations));
    }

    /**
     * Leave the given relations alone.
     *
     * @param  string|array<int, string>  ...$relations
     */
    public function excludeRelations(string|array ...$relations): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->excludeRelations(...$relations));
    }

    /**
     * Attach the original related records instead of copying them.
     *
     * @param  string|array<int, string>  ...$relations
     */
    public function referenceRelations(string|array ...$relations): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->referenceRelations(...$relations));
    }

    /**
     * Duplicate the related records of the given relations.
     *
     * @param  string|array<int, string>  ...$relations
     */
    public function copyRelations(string|array ...$relations): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->copyRelations(...$relations));
    }

    /**
     * Hand a relation over to a custom handler.
     */
    public function relationUsing(string $relation, DuplicatesRelation|Closure $handler): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->relationUsing($relation, $handler));
    }

    /**
     * Set how many levels of relations are followed.
     */
    public function depth(int $depth): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->depth($depth));
    }

    /**
     * Duplicate the root model only, leaving every relation alone.
     */
    public function shallow(): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->shallow());
    }

    /**
     * Save the duplicate without firing Eloquent model events.
     */
    public function quietly(bool $quietly = true): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->quietly($quietly));
    }

    /**
     * Copy the trashed related records alongside the duplicate.
     */
    public function withTrashed(bool $withTrashed = true): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->withTrashed($withTrashed));
    }

    /**
     * Copy the media library collections onto the duplicate.
     */
    public function withMedia(bool $withMedia = true): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->withMedia($withMedia));
    }

    /**
     * Write the key of the source record onto the duplicate.
     */
    public function trackProvenance(?string $column = null): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->trackProvenance($column));
    }

    /**
     * Register a callback to run on the duplicate before it is saved.
     *
     * @param  Closure(Model, Model): void  $callback
     */
    public function beforeSave(Closure $callback): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->beforeSave($callback));
    }

    /**
     * Register a callback to run on the duplicate once it has been saved.
     *
     * @param  Closure(Model, Model): void  $callback
     */
    public function afterSave(Closure $callback): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->afterSave($callback));
    }

    /**
     * Refine the options with the given callback.
     *
     * @param  Closure(DuplicateOptions): DuplicateOptions  $callback
     */
    protected function tap(Closure $callback): static
    {
        $this->options = $callback($this->options);

        return $this;
    }
}
