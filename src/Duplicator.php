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
 * It is registered as a singleton so that an application may decorate or
 * replace it. Nothing is kept between runs: everything mutable lives on the
 * DuplicateContext that each run creates for itself.
 */
class Duplicator
{
    /**
     * Create a new duplicator instance.
     */
    public function __construct(
        protected RelationInspector $inspector,
        protected Config $config,
        protected Dispatcher $events,
        protected DatabaseManager $db,
    ) {}

    /**
     * Duplicate a model and return the full result of the run.
     *
     * @template TModel of Model
     *
     * @param  TModel  $source
     * @return DuplicateResult<TModel>
     *
     * @throws DuplicateToolkitException
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
            maxDepth: $options->getDepth() ?? Cast::toInt($this->config->get('duplicate-toolkit.max_depth'), 1),
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
     * Run the duplication inside a transaction.
     *
     * A dry run performs every write and then rolls the whole thing back, so
     * the plan it reports is exactly what a real run would have produced.
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
     * Duplicate a single model and then, recursively, its relations.
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
     * Get the columns that must never be copied onto the duplicate.
     *
     * Beyond whatever the caller excluded, we always drop the timestamps and
     * the soft delete column so that the duplicate is born as a fresh record
     * rather than inheriting the history of the row it came from.
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

    /**
     * Apply the configured attribute overrides to the duplicate.
     */
    protected function applyMutators(Model $duplicate, Model $source, DuplicateOptions $options): void
    {
        foreach ($options->getMutators() as $mutator) {
            $mutator->apply($duplicate, $source);
        }
    }

    /**
     * Write the key of the source record onto the duplicate.
     */
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
     * Give the declared unique columns a value no other row is using.
     *
     * Here we resolve each column with a single query rather than the 1.x
     * loop that queried once per attempt, and the source row is included in
     * the comparison so a duplicate can never collide with its own original.
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
     * Get the lowest free "value (n)" for a column, using a single query.
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

    /**
     * Escape the wildcards a value would otherwise contribute to a like clause.
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * Persist the duplicate, quietly when that is what was asked for.
     */
    protected function save(Model $duplicate, DuplicateOptions $options): void
    {
        $quietly = $options->shouldSaveQuietly()
            ?? Cast::toBool($this->config->get('duplicate-toolkit.save_quietly'));

        $quietly ? $duplicate->saveQuietly() : $duplicate->save();
    }

    /**
     * Duplicate every relation of the source model onto the duplicate.
     */
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

            if ($this->inspector->isMediaLibraryRelation($meta)) {
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
     * Determine if stopping here would leave related records uncopied.
     *
     * This is only consulted in strict depth mode, which keeps the extra query
     * per relation entirely opt in.
     */
    protected function hasCopyableRelations(Model $source, DuplicateOptions $options): bool
    {
        $attributeStrategies = $this->inspector->attributeStrategies($source);

        foreach ($this->inspector->duplicatableFor($source) as $name => $meta) {
            if ($this->inspector->isMediaLibraryRelation($meta)) {
                continue;
            }

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

    /**
     * Get the configured strategy for a relation of the given kind.
     */
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
     * Attach the original related records to the duplicate.
     *
     * Only pivoted relations may be referenced, since a child row is owned by
     * the record it hangs off and cannot belong to two of them at once.
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

    /**
     * Replicate the records behind a pivoted relation and attach the copies.
     */
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
     * Get the pivoted relation instance for the given model.
     *
     * @return BelongsToMany<Model, Model, Pivot>
     */
    protected function belongsToMany(Model $model, RelationMeta $meta): BelongsToMany
    {
        /** @var BelongsToMany<Model, Model, Pivot> $relation */
        $relation = $model->{$meta->name}();

        return $relation;
    }

    /**
     * Get the pivot attributes worth carrying over to the new pivot row.
     *
     * Everything declared with withPivot() qualifies, minus the keys and the
     * timestamps that the relation already manages on our behalf.
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
     * Get the query used to read the records behind a relation.
     *
     * @param  Relation<Model, Model, *>  $relation
     * @return Builder<Model>
     */
    protected function relatedQuery(Relation $relation, DuplicateOptions $options): Builder
    {
        $query = $relation->getQuery();

        $withTrashed = $options->shouldCopyTrashed()
            ?? Cast::toBool($this->config->get('duplicate-toolkit.copy_trashed'));

        // The withTrashed() method is forwarded through Builder::__call, so
        // here we reach for the typed API it delegates to instead.
        if ($withTrashed && $this->usesSoftDeletes($relation->getRelated())) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }

    /**
     * Hand a relation over to the handler the caller registered for it.
     */
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

    /**
     * Copy the media library collections of the source onto the duplicate.
     */
    protected function copyMedia(Model $source, Model $duplicate, DuplicateOptions $options): void
    {
        $enabled = $options->shouldCopyMedia()
            ?? $this->mediaRelationStrategy($source, $options)
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

    /**
     * Read the media decision out of a strategy set on the media relation.
     *
     * The relation itself is never walked, since only the media library knows
     * how to move a media row together with its file. Naming that relation is
     * still a legitimate way of saying whether the copy should carry the media
     * over, so a strategy declared for it drives the media pass instead: only
     * "copy" turns it on, and every other strategy turns it off.
     */
    protected function mediaRelationStrategy(Model $source, DuplicateOptions $options): ?bool
    {
        if (! interface_exists(HasMedia::class) || ! $source instanceof HasMedia) {
            return null;
        }

        $attributeStrategies = $this->inspector->attributeStrategies($source);

        foreach ($this->inspector->for($source) as $name => $meta) {
            if (! $this->inspector->isMediaLibraryRelation($meta)) {
                continue;
            }

            $strategy = $options->strategyFor($name) ?? $attributeStrategies[$name] ?? null;

            if ($strategy instanceof RelationStrategy) {
                return $strategy === RelationStrategy::Copy;
            }
        }

        return null;
    }

    /**
     * Merge the options of the model with the inherited and caller overrides.
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
     * Get the options a model declares for itself.
     *
     * The commands reach for this to show what a duplication would do before
     * a single row is written.
     */
    public function modelOptionsFor(Model $model): DuplicateOptions
    {
        return $this->modelOptions($model);
    }

    /**
     * Resolve the options declared by the model itself.
     *
     * The Duplicatable contract is the documented route, but the trait alone
     * is enough: looking the method up directly keeps working for a model that
     * uses HasDuplicates without ever implementing the interface.
     */
    protected function modelOptions(Model $model): DuplicateOptions
    {
        // A model still declaring the 1.x getDuplicateOptions() method has not
        // been migrated yet, so that method remains the authority on how it is
        // duplicated. The codemod removes it, after which the checks below take
        // over.
        if (method_exists($model, 'getDuplicateOptions')) {
            $legacy = $model->getDuplicateOptions();

            if ($legacy instanceof DuplicateOptions) {
                return $legacy;
            }
        }

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

    /**
     * Get the unique strategy the configuration asks for.
     */
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

    /**
     * Determine if the given model is soft deleting.
     */
    protected function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * Strip the table name from a qualified column.
     */
    protected function unqualify(string $column): string
    {
        return str_contains($column, '.') ? (string) Str::afterLast($column, '.') : $column;
    }

    /**
     * Fire one of the custom "duplicating" or "duplicated" model events.
     *
     * Only a model using the HasDuplicates trait knows how to fire them, so
     * anything else is left alone.
     */
    protected function fireDuplicateEvent(Model $model, string $event, bool $halt = true): mixed
    {
        return method_exists($model, 'fireDuplicateEvent')
            ? $model->fireDuplicateEvent($event, $halt)
            : null;
    }

    /**
     * Run the callback once the surrounding transaction has committed.
     */
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
