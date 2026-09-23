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
 * Fluent entry point returned by Duplicate::of() and $model->duplicator().
 *
 * Every option method mirrors DuplicateOptions, so the two can be used
 * interchangeably without learning a second vocabulary.
 *
 * @template TModel of Model
 *
 * @mixin DuplicateOptions
 */
class PendingDuplicate
{
    protected DuplicateOptions $options;

    protected ?string $connection = null;

    protected ?string $queue = null;

    /**
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

    public function options(): DuplicateOptions
    {
        return $this->options;
    }

    /**
     * @return TModel
     */
    public function model(): Model
    {
        return $this->model;
    }

    /**
     * Execute and return the full result.
     *
     * @return DuplicateResult<TModel>
     */
    public function execute(): DuplicateResult
    {
        return $this->duplicator->run($this->model, $this->options);
    }

    /**
     * Execute and return only the duplicated model.
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

    public function onConnection(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function onQueue(?string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Run the duplication in the background.
     */
    public function dispatch(): PendingDispatch
    {
        $pending = DuplicateModel::dispatch($this->model, $this->options);

        // Only override what was asked for: the job already defaults to the
        // connection and queue configured for the package.
        if ($this->connection !== null) {
            $pending->onConnection($this->connection);
        }

        if ($this->queue !== null) {
            $pending->onQueue($this->queue);
        }

        return $pending;
    }

    // -------------------------------------------------------------------
    // DuplicateOptions passthrough, typed for IDEs and static analysis
    // -------------------------------------------------------------------
    /**
     * @param  string|array<int, string>  ...$columns
     */
    public function excludeColumns(string|array ...$columns): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->excludeColumns(...$columns));
    }

    public function excludeColumnsMatching(string ...$patterns): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->excludeColumnsMatching(...$patterns));
    }

    /**
     * @param  string|array<int, string>  ...$columns
     */
    public function uniqueColumns(string|array ...$columns): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->uniqueColumns(...$columns));
    }

    public function uniqueUsing(UniqueStrategy $strategy): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->uniqueUsing($strategy));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function withAttributes(array $attributes): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->withAttributes($attributes));
    }

    public function suffix(string $column, string $suffix): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->suffix($column, $suffix));
    }

    public function prefix(string $column, string $prefix): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->prefix($column, $prefix));
    }

    public function replace(string $column, string $search, string $replace): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->replace($column, $search, $replace));
    }

    /**
     * @param  Closure(mixed, Model, Model): mixed  $callback
     */
    public function mutate(string $column, Closure $callback): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->mutate($column, $callback));
    }

    /**
     * @param  RelationStrategy|DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions  $strategy
     */
    public function relation(string $relation, RelationStrategy|DuplicateOptions|Closure $strategy = RelationStrategy::Copy): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->relation($relation, $strategy));
    }

    /**
     * @param  string|array<int, string>  ...$relations
     */
    public function onlyRelations(string|array ...$relations): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->onlyRelations(...$relations));
    }

    /**
     * @param  string|array<int, string>  ...$relations
     */
    public function excludeRelations(string|array ...$relations): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->excludeRelations(...$relations));
    }

    /**
     * @param  string|array<int, string>  ...$relations
     */
    public function referenceRelations(string|array ...$relations): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->referenceRelations(...$relations));
    }

    /**
     * @param  string|array<int, string>  ...$relations
     */
    public function copyRelations(string|array ...$relations): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->copyRelations(...$relations));
    }

    public function relationUsing(string $relation, DuplicatesRelation|Closure $handler): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->relationUsing($relation, $handler));
    }

    public function depth(int $depth): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->depth($depth));
    }

    public function shallow(): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->shallow());
    }

    public function quietly(bool $quietly = true): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->quietly($quietly));
    }

    public function withTrashed(bool $withTrashed = true): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->withTrashed($withTrashed));
    }

    public function withMedia(bool $withMedia = true): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->withMedia($withMedia));
    }

    public function trackProvenance(?string $column = null): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->trackProvenance($column));
    }

    /**
     * @param  Closure(Model, Model): void  $callback
     */
    public function beforeSave(Closure $callback): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->beforeSave($callback));
    }

    /**
     * @param  Closure(Model, Model): void  $callback
     */
    public function afterSave(Closure $callback): static
    {
        return $this->tap(fn (DuplicateOptions $o): DuplicateOptions => $o->afterSave($callback));
    }

    /**
     * @param  Closure(DuplicateOptions): DuplicateOptions  $callback
     */
    protected function tap(Closure $callback): static
    {
        $this->options = $callback($this->options);

        return $this;
    }
}
