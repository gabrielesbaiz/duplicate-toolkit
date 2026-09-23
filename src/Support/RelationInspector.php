<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Support;

use Gabrielesbaiz\DuplicateToolkit\Attributes\DuplicateRelations;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationKind;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Exceptions\RelationNotFoundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Discovers the relations defined on an Eloquent model.
 *
 * Discovery reflects on method return types only. Unlike the 1.x
 * implementation it never reads the model's source file and never invokes
 * model methods, so it is opcache safe and free of side effects.
 *
 * Results are memoised per class-string, which makes it impossible for the
 * relations of one model to leak into another.
 */
class RelationInspector
{
    /**
     * @var array<class-string<Model>, array<string, RelationMeta>>
     */
    protected array $cache = [];

    /**
     * Methods every Eloquent model inherits, which are never relations.
     *
     * @var array<int, string>|null
     */
    protected static ?array $baseMethods = null;

    public function __construct(
        protected bool $invokeUntyped = false,
        protected bool $shouldCache = true,
    ) {}

    /**
     * All relations defined on the given model, keyed by relation name.
     *
     * @return array<string, RelationMeta>
     */
    public function for(Model $model): array
    {
        $class = $model::class;

        if ($this->shouldCache && isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        $relations = $this->discover($model);

        ksort($relations);

        if ($this->shouldCache) {
            $this->cache[$class] = $relations;
        }

        return $relations;
    }

    /**
     * Only the relations that can be meaningfully duplicated: children and
     * pivoted relations. Parents and "through" relations are excluded.
     *
     * @return array<string, RelationMeta>
     */
    public function duplicatableFor(Model $model): array
    {
        return array_filter(
            $this->for($model),
            static fn (RelationMeta $meta): bool => $meta->kind->isDuplicatable(),
        );
    }

    public function get(Model $model, string $relation): RelationMeta
    {
        $relations = $this->for($model);

        return $relations[$relation] ?? throw RelationNotFoundException::make(
            $model::class,
            $relation,
            array_keys($relations),
        );
    }

    public function has(Model $model, string $relation): bool
    {
        return isset($this->for($model)[$relation]);
    }

    /**
     * Strategies declared on the model through the #[DuplicateRelations]
     * attribute, if any.
     *
     * @return array<string, RelationStrategy>
     */
    public function attributeStrategies(Model $model): array
    {
        $attributes = (new ReflectionClass($model))->getAttributes(DuplicateRelations::class);

        $strategies = [];

        foreach ($attributes as $attribute) {
            $strategies = [...$strategies, ...$attribute->newInstance()->toStrategies()];
        }

        return $strategies;
    }

    /**
     * Recursively build a relation tree, guarding against cycles.
     *
     * @param  array<int, class-string<Model>>  $seen
     * @return array<string, array{meta: RelationMeta, children: array<string, mixed>}>
     */
    public function tree(Model $model, int $depth = 1, array $seen = []): array
    {
        $seen[] = $model::class;
        $tree = [];

        foreach ($this->for($model) as $name => $meta) {
            $children = [];

            if (
                $depth > 0
                && $meta->relatedClass !== null
                && ! in_array($meta->relatedClass, $seen, true)
                && $meta->kind->isDuplicatable()
            ) {
                $children = $this->tree(new $meta->relatedClass, $depth - 1, $seen);
            }

            $tree[$name] = ['meta' => $meta, 'children' => $children];
        }

        return $tree;
    }

    public function flush(?string $class = null): void
    {
        if ($class === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$class]);
    }

    /**
     * @return array<string, RelationMeta>
     */
    protected function discover(Model $model): array
    {
        $relations = [];

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->shouldSkip($method)) {
                continue;
            }

            $meta = $this->metaFromReturnType($model, $method)
                ?? $this->metaFromInvocation($model, $method);

            if ($meta instanceof RelationMeta) {
                $relations[$meta->name] = $meta;
            }
        }

        return $relations;
    }

    protected function shouldSkip(ReflectionMethod $method): bool
    {
        return $method->isStatic()
            || $method->isAbstract()
            || $method->getNumberOfRequiredParameters() > 0
            || str_starts_with($method->getName(), '__')
            || in_array($method->getName(), static::baseMethods(), true);
    }

    protected function metaFromReturnType(Model $model, ReflectionMethod $method): ?RelationMeta
    {
        $type = $method->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        /** @var class-string $name */
        $name = $type->getName();

        if (! is_a($name, Relation::class, true)) {
            return null;
        }

        /** @var class-string<Relation<*, *, *>> $name */
        $kind = RelationKind::fromRelationClass($name);

        if (! $kind instanceof RelationKind) {
            return null;
        }

        return new RelationMeta(
            name: $method->getName(),
            parentClass: $model::class,
            type: $name,
            relatedClass: $this->resolveRelatedClass($model, $method->getName()),
            kind: $kind,
        );
    }

    /**
     * Fallback for legacy relation methods declared without a return type.
     * Disabled by default because it executes model code.
     */
    protected function metaFromInvocation(Model $model, ReflectionMethod $method): ?RelationMeta
    {
        if (! $this->invokeUntyped || $method->hasReturnType()) {
            return null;
        }

        try {
            $relation = $method->invoke($model);
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof Relation) {
            return null;
        }

        $kind = RelationKind::fromRelationClass($relation::class);

        if (! $kind instanceof RelationKind) {
            return null;
        }

        return new RelationMeta(
            name: $method->getName(),
            parentClass: $model::class,
            type: $relation::class,
            relatedClass: $relation->getRelated()::class,
            kind: $kind,
        );
    }

    /**
     * Resolve the related model class without keeping the relation instance
     * around. MorphTo has no single related class, hence the null.
     *
     * @return class-string<Model>|null
     */
    protected function resolveRelatedClass(Model $model, string $relation): ?string
    {
        try {
            $instance = $model->newInstance()->{$relation}();
        } catch (Throwable) {
            return null;
        }

        return $instance instanceof Relation ? $instance->getRelated()::class : null;
    }

    /**
     * @return array<int, string>
     */
    protected static function baseMethods(): array
    {
        return static::$baseMethods ??= get_class_methods(Model::class);
    }
}
