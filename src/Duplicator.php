<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit;

use Closure;
use Gabrielesbaiz\DuplicateToolkit\Contracts\Duplicatable;
use Gabrielesbaiz\DuplicateToolkit\Contracts\DuplicatesRelation;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Enums\UniqueStrategy;
use Gabrielesbaiz\DuplicateToolkit\Events\Duplicated;
use Gabrielesbaiz\DuplicateToolkit\Events\Duplicating;
use Gabrielesbaiz\DuplicateToolkit\Events\RelationDuplicated;
use Gabrielesbaiz\DuplicateToolkit\Events\RelationDuplicating;
use Gabrielesbaiz\DuplicateToolkit\Exceptions\DuplicateToolkitException;
use Gabrielesbaiz\DuplicateToolkit\Exceptions\MaxDepthExceededException;
use Gabrielesbaiz\DuplicateToolkit\Exceptions\NotDuplicatableException;
use Gabrielesbaiz\DuplicateToolkit\Results\DuplicateResult;
use Gabrielesbaiz\DuplicateToolkit\Support\Cast;
use Gabrielesbaiz\DuplicateToolkit\Support\DryRunRollback;
use Gabrielesbaiz\DuplicateToolkit\Support\DuplicateContext;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The duplication engine.
 *
 * Registered as a singleton so applications can decorate or swap it. It keeps
 * no state between runs: everything mutable lives in the DuplicateContext.
 */
class Duplicator
{
    public function __construct(
        protected RelationInspector $inspector,
        protected Config $config,
        protected Dispatcher $events,
        protected DatabaseManager $db,
    ) {}

    /**
     * Duplicate a model and return the full result.
     *
     * @template TModel of Model
     *
     * @param  TModel  $source
     * @return DuplicateResult<TModel>
     */
    public function run(Model $source, ?DuplicateOptions $options = null): DuplicateResult
    {
        if (! $source->exists) {
            throw NotDuplicatableException::unsavedModel($source);
        }

        $options = $this->resolveOptions($source, $options);
        $startedAt = microtime(true);

        $this->events->dispatch(new Duplicating($source, $options));

        if ($this->fireDuplicateEvent($source, 'duplicating') === false) {
            throw new DuplicateToolkitException(sprintf(
                'Duplication of [%s] was halted by a "duplicating" model event listener.',
                $source::class,
            ));
        }

        $context = new DuplicateContext(
            maxDepth: $options->getDepth() ?? Cast::toInt($this->config->get('duplicate-toolkit.max_depth'), 3),
            dryRun: $options->isDryRun(),
            strictDepth: $options->hasStrictDepth(),
        );

        $duplicate = $this->transact($source, $context, fn (): Model => $this->duplicateModel($source, $options, $context));

        /** @var DuplicateResult<TModel> $result */
        $result = new DuplicateResult(
            model: $duplicate,
            source: $source,
            context: $context,
            dryRun: $context->dryRun,
            durationMs: (microtime(true) - $startedAt) * 1000,
        );

        if (! $context->dryRun) {
            $this->afterCommit($source, function () use ($source, $duplicate, $result): void {
                $this->events->dispatch(new Duplicated($source, $duplicate, $result));
                $this->fireDuplicateEvent($source, 'duplicated', halt: false);
            });
        }

        return $result;
    }

    /**
     * Run the duplication in a transaction. A dry run performs every write and
     * then rolls the whole thing back, so the reported plan is exact.
     *
     * @param  Closure(): Model  $callback
     */
    protected function transact(Model $source, DuplicateContext $context, Closure $callback): Model
    {
        $connection = $this->db->connection($source->getConnectionName());

        if (! $context->dryRun) {
            return $connection->transaction($callback);
        }

        try {
            return $connection->transaction(static function () use ($callback): Model {
                throw new DryRunRollback($callback());
            });
        } catch (DryRunRollback $rollback) {
            return $rollback->model;
        }
    }

    /**
     * Duplicate a single model plus, recursively, its relations.
     *
     * @template TModel of Model
     *
     * @param  TModel  $source
     * @param  int|null  $branchBudget  remaining levels for this branch, null for unlimited
     * @param  Closure(TModel): void|null  $tap  applied to the duplicate before it is saved
     * @return TModel
     */
    protected function duplicateModel(
        Model $source,
        DuplicateOptions $options,
        DuplicateContext $context,
        ?int $branchBudget = null,
        ?Closure $tap = null,
    ): Model {
        $context->markVisited($source);

        $duplicate = $source->replicate($this->excludedColumnsFor($source, $options));

        if ($tap instanceof Closure) {
            $tap($duplicate);
        }

        $this->applyMutators($duplicate, $source, $options);
        $this->applyProvenance($duplicate, $source, $options);
        $this->applyUniqueColumns($duplicate, $source, $options);

        foreach ($options->getBeforeSaveCallbacks() as $callback) {
            $callback($duplicate, $source);
        }

        $this->save($duplicate, $options);

        $context->record($source, $duplicate);

        foreach ($options->getAfterSaveCallbacks() as $callback) {
            $callback($duplicate, $source);
        }

        $this->copyMedia($source, $duplicate, $options);

        $budget = $options->getDepth() ?? $branchBudget;

        if ($context->canDescend() && ($budget === null || $budget > 0)) {
            $context->descend();
            $this->duplicateRelations($source, $duplicate, $options, $context, $budget === null ? null : $budget - 1);
            $context->ascend();
        } elseif ($context->strictDepth && $this->hasCopyableRelations($source, $options)) {
            throw MaxDepthExceededException::for($source, $context->maxDepth);
        }

        /** @var TModel $duplicate */
        return $duplicate;
    }

    /**
     * Columns never copied: timestamps, soft deletes, globally configured
     * columns, pattern matches and anything the caller excluded.
     *
     * @return array<int, string>
     */
    protected function excludedColumnsFor(Model $source, DuplicateOptions $options): array
    {
        $excluded = [
            ...$options->getExcludedColumns(),
            ...Cast::toStringList($this->config->get('duplicate-toolkit.excluded_columns', [])),
        ];

        if ($source->usesTimestamps()) {
            $excluded[] = Cast::toString($source->getCreatedAtColumn());
            $excluded[] = Cast::toString($source->getUpdatedAtColumn());
        }

        if (method_exists($source, 'getDeletedAtColumn')) {
            $excluded[] = Cast::toString($source->getDeletedAtColumn());
        }

        $patterns = [
            ...$options->getExcludedColumnPatterns(),
            ...Cast::toStringList($this->config->get('duplicate-toolkit.excluded_column_patterns', [])),
        ];

        foreach ($patterns as $pattern) {
            foreach (array_keys($source->getAttributes()) as $column) {
                if (preg_match($pattern, Cast::toString($column)) === 1) {
                    $excluded[] = Cast::toString($column);
                }
            }
        }

        return array_values(array_unique(array_filter($excluded)));
    }

    protected function applyMutators(Model $duplicate, Model $source, DuplicateOptions $options): void
    {
        foreach ($options->getMutators() as $mutator) {
            $mutator->apply($duplicate, $source);
        }
    }

    protected function applyProvenance(Model $duplicate, Model $source, DuplicateOptions $options): void
    {
        if (! $options->shouldTrackProvenance()) {
            return;
        }

        $column = $options->getProvenanceColumn()
            ?? Cast::toString($this->config->get('duplicate-toolkit.provenance_column'), 'duplicated_from_id');

        if (! $source->getConnection()->getSchemaBuilder()->hasColumn($source->getTable(), $column)) {
            return;
        }

        $duplicate->setAttribute($column, $source->getKey());
    }

    /**
     * Resolve unique column values with a single query per column instead of
     * the 1.x query-per-attempt loop, never colliding with the source row.
     */
    protected function applyUniqueColumns(Model $duplicate, Model $source, DuplicateOptions $options): void
    {
        $columns = $options->getUniqueColumns();

        if ($columns === []) {
            return;
        }

        $strategy = $options->getUniqueStrategy() ?? $this->configuredUniqueStrategy();
        $format = Cast::toString($this->config->get('duplicate-toolkit.unique_format'), ' (%d)');

        foreach ($columns as $column) {
            $original = $duplicate->getAttribute($column);

            if ($original === null) {
                continue;
            }

            $original = Cast::toString($original);

            $duplicate->setAttribute($column, match ($strategy) {
                UniqueStrategy::Uuid => $original.' ('.substr(Str::uuid()->toString(), 0, 8).')',
                UniqueStrategy::Ulid => $original.' ('.substr((string) Str::ulid(), -8).')',
                UniqueStrategy::Timestamp => $original.' ('.time().')',
                UniqueStrategy::NumericSuffix => $this->nextNumericSuffix($source, $column, $original, $format),
            });
        }
    }

    /**
     * Lowest free "value (n)", resolved with one query.
     */
    protected function nextNumericSuffix(Model $source, string $column, string $value, string $format): string
    {
        $taken = [];

        foreach ($source->newQuery()->withoutGlobalScopes()->where($column, 'like', $this->escapeLike($value).'%')->pluck($column) as $existing) {
            $taken[] = Cast::toString($existing);
        }

        if (! in_array($value, $taken, true)) {
            return $value;
        }

        $index = 1;

        while (in_array($value.sprintf($format, $index), $taken, true)) {
            $index++;
        }

        return $value.sprintf($format, $index);
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    protected function save(Model $duplicate, DuplicateOptions $options): void
    {
        $quietly = $options->shouldSaveQuietly()
            ?? Cast::toBool($this->config->get('duplicate-toolkit.save_quietly'));

        $quietly ? $duplicate->saveQuietly() : $duplicate->save();
    }

    // -------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------

    protected function duplicateRelations(
        Model $source,
        Model $duplicate,
        DuplicateOptions $options,
        DuplicateContext $context,
        ?int $branchBudget,
    ): void {
        $attributeStrategies = $this->inspector->attributeStrategies($source);

        foreach ($this->inspector->duplicatableFor($source) as $name => $meta) {
            $handler = $options->getRelationHandlers()[$name] ?? null;

            if ($handler !== null) {
                $this->runCustomHandler($handler, $source, $duplicate, $meta, $context);

                continue;
            }

            $strategy = $options->strategyFor($name)
                ?? $attributeStrategies[$name]
                ?? $this->defaultStrategyFor($meta);

            if ($strategy === RelationStrategy::Skip) {
                continue;
            }

            $this->events->dispatch(new RelationDuplicating($source, $duplicate, $meta, $strategy));

            $count = $strategy === RelationStrategy::Reference
                ? $this->referenceRelation($source, $duplicate, $meta)
                : $this->copyRelation($source, $duplicate, $meta, $options, $context, $branchBudget);

            $this->events->dispatch(new RelationDuplicated($source, $duplicate, $meta, $strategy, $count));
        }
    }

    /**
     * Whether stopping here would leave relations uncopied. Only consulted in
     * strict depth mode, so the extra queries are opt in.
     */
    protected function hasCopyableRelations(Model $source, DuplicateOptions $options): bool
    {
        $attributeStrategies = $this->inspector->attributeStrategies($source);

        foreach ($this->inspector->duplicatableFor($source) as $name => $meta) {
            $strategy = $options->strategyFor($name)
                ?? $attributeStrategies[$name]
                ?? $this->defaultStrategyFor($meta);

            if ($strategy !== RelationStrategy::Copy) {
                continue;
            }

            /** @var Relation<Model, Model, *> $relation */
            $relation = $source->{$name}();

            if ($relation->getQuery()->exists()) {
                return true;
            }
        }

        return false;
    }

    protected function defaultStrategyFor(RelationMeta $meta): RelationStrategy
    {
        $configured = $this->config->get($meta->kind->isPivoted()
            ? 'duplicate-toolkit.default_pivoted_relation_strategy'
            : 'duplicate-toolkit.default_relation_strategy');

        if ($configured instanceof RelationStrategy) {
            return $configured;
        }

        if (is_string($configured)) {
            return RelationStrategy::tryFrom($configured) ?? $meta->defaultStrategy();
        }

        return $meta->defaultStrategy();
    }

    /**
     * Attach the original related records to the duplicate. Only pivoted
     * relations can be referenced; child rows are owned by the source.
     */
    protected function referenceRelation(Model $source, Model $duplicate, RelationMeta $meta): int
    {
        if (! $meta->kind->isPivoted()) {
            return 0;
        }

        $relation = $this->belongsToMany($source, $meta);
        $attach = [];

        foreach ($relation->get() as $related) {
            $key = $related->getKey();

            if (is_int($key) || is_string($key)) {
                $attach[$key] = $this->pivotAttributes($relation, $related);
            }
        }

        if ($attach !== []) {
            $this->belongsToMany($duplicate, $meta)->attach($attach);
        }

        return count($attach);
    }

    /**
     * Replicate the related records and attach the copies to the duplicate.
     */
    protected function copyRelation(
        Model $source,
        Model $duplicate,
        RelationMeta $meta,
        DuplicateOptions $options,
        DuplicateContext $context,
        ?int $branchBudget,
    ): int {
        $nested = $options->forRelation($meta->name);

        if ($meta->kind->isPivoted()) {
            return $this->copyPivotedRelation($source, $duplicate, $meta, $nested, $context, $branchBudget);
        }

        /** @var HasOneOrMany<Model, Model, Model> $relation */
        $relation = $source->{$meta->name}();

        /** @var HasOneOrMany<Model, Model, Model> $targetRelation */
        $targetRelation = $duplicate->{$meta->name}();

        $foreignKey = $this->unqualify($relation->getForeignKeyName());
        $morphType = $targetRelation instanceof MorphOneOrMany
            ? $this->unqualify($targetRelation->getMorphType())
            : null;

        $chunk = Cast::toInt($this->config->get('duplicate-toolkit.chunk_size'), 500);
        $copied = 0;

        foreach ($this->relatedQuery($relation, $options)->lazyById($chunk) as $related) {
            if ($context->hasVisited($related)) {
                continue;
            }

            $this->duplicateModel(
                source: $related,
                options: $this->resolveOptions($related, null, $nested),
                context: $context,
                branchBudget: $branchBudget,
                tap: static function (Model $copy) use ($foreignKey, $morphType, $duplicate): void {
                    $copy->setAttribute($foreignKey, $duplicate->getKey());

                    if ($morphType !== null) {
                        $copy->setAttribute($morphType, $duplicate->getMorphClass());
                    }
                },
            );

            $copied++;
        }

        return $copied;
    }

    protected function copyPivotedRelation(
        Model $source,
        Model $duplicate,
        RelationMeta $meta,
        ?DuplicateOptions $nested,
        DuplicateContext $context,
        ?int $branchBudget,
    ): int {
        $relation = $this->belongsToMany($source, $meta);
        $attach = [];

        foreach ($relation->get() as $related) {
            if ($context->hasVisited($related)) {
                continue;
            }

            $copy = $this->duplicateModel(
                source: $related,
                options: $this->resolveOptions($related, null, $nested),
                context: $context,
                branchBudget: $branchBudget,
            );

            $key = $copy->getKey();

            if (is_int($key) || is_string($key)) {
                $attach[$key] = $this->pivotAttributes($relation, $related);
            }
        }

        if ($attach !== []) {
            $this->belongsToMany($duplicate, $meta)->attach($attach);
        }

        return count($attach);
    }

    /**
     * @return BelongsToMany<Model, Model, Pivot>
     */
    protected function belongsToMany(Model $model, RelationMeta $meta): BelongsToMany
    {
        /** @var BelongsToMany<Model, Model, Pivot> $relation */
        $relation = $model->{$meta->name}();

        return $relation;
    }

    /**
     * Pivot attributes worth carrying over: everything declared with
     * withPivot(), minus the keys and timestamps the relation manages itself.
     *
     * @param  BelongsToMany<Model, Model, Pivot>  $relation
     * @return array<string, mixed>
     */
    protected function pivotAttributes(BelongsToMany $relation, Model $related): array
    {
        $accessor = $relation->getPivotAccessor();

        if (! $related->relationLoaded($accessor)) {
            return [];
        }

        $pivot = $related->getRelation($accessor);

        if (! $pivot instanceof Model) {
            return [];
        }

        $except = array_filter([
            $pivot->getKeyName(),
            $relation->getForeignPivotKeyName(),
            $relation->getRelatedPivotKeyName(),
            $relation instanceof MorphToMany ? $this->unqualify($relation->getMorphType()) : null,
            $pivot->getCreatedAtColumn(),
            $pivot->getUpdatedAtColumn(),
        ]);

        return array_diff_key($pivot->getAttributes(), array_flip($except));
    }

    /**
     * @param  Relation<Model, Model, *>  $relation
     * @return Builder<Model>
     */
    protected function relatedQuery(Relation $relation, DuplicateOptions $options): Builder
    {
        $query = $relation->getQuery();

        $withTrashed = $options->shouldCopyTrashed()
            ?? Cast::toBool($this->config->get('duplicate-toolkit.copy_trashed'));

        // withTrashed() is forwarded through Builder::__call, so call the real
        // typed API it delegates to instead.
        if ($withTrashed && $this->usesSoftDeletes($relation->getRelated())) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }

    protected function runCustomHandler(
        DuplicatesRelation|Closure $handler,
        Model $source,
        Model $duplicate,
        RelationMeta $meta,
        DuplicateContext $context,
    ): void {
        if ($handler instanceof DuplicatesRelation) {
            $handler->handle($source, $duplicate, $meta, $context);

            return;
        }

        $handler($source, $duplicate, $meta, $context);
    }

    // -------------------------------------------------------------------
    // Extras
    // -------------------------------------------------------------------

    protected function copyMedia(Model $source, Model $duplicate, DuplicateOptions $options): void
    {
        $enabled = $options->shouldCopyMedia()
            ?? Cast::toBool($this->config->get('duplicate-toolkit.media.enabled'), true);

        if (! $enabled || ! interface_exists(HasMedia::class) || ! class_exists(Media::class)) {
            return;
        }

        if (! $source instanceof HasMedia || ! $duplicate instanceof HasMedia) {
            return;
        }

        foreach ($source->getMedia('*') as $media) {
            if ($media instanceof Media) {
                $media->copy($duplicate, $media->collection_name, $media->disk);
            }
        }
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * Merge the model's own options with any inherited and caller overrides.
     */
    protected function resolveOptions(Model $model, ?DuplicateOptions $overrides, ?DuplicateOptions $inherited = null): DuplicateOptions
    {
        $base = $this->modelOptions($model);

        if ($inherited instanceof DuplicateOptions) {
            $base = $base->merge($inherited);
        }

        return $overrides instanceof DuplicateOptions ? $base->merge($overrides) : $base;
    }

    /**
     * Public accessor for the options a model declares, used by the commands
     * to show what would happen before anything runs.
     */
    public function modelOptionsFor(Model $model): DuplicateOptions
    {
        return $this->modelOptions($model);
    }

    /**
     * The options declared by the model itself.
     *
     * The Duplicatable interface is the documented contract, but the trait
     * alone is enough: checking for the method keeps working for models that
     * use HasDuplicates without implementing the interface.
     */
    protected function modelOptions(Model $model): DuplicateOptions
    {
        if ($model instanceof Duplicatable) {
            return $model->duplicateOptions();
        }

        if (method_exists($model, 'duplicateOptions')) {
            $options = $model->duplicateOptions();

            if ($options instanceof DuplicateOptions) {
                return $options;
            }
        }

        return DuplicateOptions::make();
    }

    protected function configuredUniqueStrategy(): UniqueStrategy
    {
        $configured = $this->config->get('duplicate-toolkit.unique_strategy');

        if ($configured instanceof UniqueStrategy) {
            return $configured;
        }

        return is_string($configured)
            ? UniqueStrategy::tryFrom($configured) ?? UniqueStrategy::NumericSuffix
            : UniqueStrategy::NumericSuffix;
    }

    protected function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    protected function unqualify(string $column): string
    {
        return str_contains($column, '.') ? (string) Str::afterLast($column, '.') : $column;
    }

    /**
     * Fire one of the custom "duplicating"/"duplicated" model events, when the
     * model uses the HasDuplicates trait.
     */
    protected function fireDuplicateEvent(Model $model, string $event, bool $halt = true): mixed
    {
        return method_exists($model, 'fireDuplicateEvent')
            ? $model->fireDuplicateEvent($event, $halt)
            : null;
    }

    protected function afterCommit(Model $model, Closure $callback): void
    {
        $connection = $this->db->connection($model->getConnectionName());

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);

            return;
        }

        $callback();
    }
}
