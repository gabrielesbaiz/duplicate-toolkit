<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit;

use Gabrielesbaiz\DuplicateToolkit\Exceptions\ModelResolutionException;
use Gabrielesbaiz\DuplicateToolkit\Results\DuplicateResult;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;

/**
 * The public entry point behind the Duplicate facade.
 */
class DuplicateManager
{
    public function __construct(
        protected Duplicator $duplicator,
        protected RelationInspector $inspector,
        protected Config $config,
    ) {}

    /**
     * Start a fluent duplication.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return PendingDuplicate<TModel>
     */
    public function of(Model $model): PendingDuplicate
    {
        /** @var PendingDuplicate<TModel> $pending */
        $pending = new PendingDuplicate($model, $this->duplicator);

        return $pending;
    }

    /**
     * Duplicate immediately.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return DuplicateResult<TModel>
     */
    public function run(Model $model, ?DuplicateOptions $options = null): DuplicateResult
    {
        return $this->duplicator->run($model, $options);
    }

    /**
     * @return array<string, RelationMeta>
     */
    public function relations(Model $model): array
    {
        return $this->inspector->for($model);
    }

    /**
     * @return array<string, RelationMeta>
     */
    public function duplicatableRelations(Model $model): array
    {
        return $this->inspector->duplicatableFor($model);
    }

    /**
     * @return array<string, array{meta: RelationMeta, children: array<string, mixed>}>
     */
    public function tree(Model $model, int $depth = 1): array
    {
        return $this->inspector->tree($model, $depth);
    }

    public function inspector(): RelationInspector
    {
        return $this->inspector;
    }

    /**
     * Resolve "Product" or "App\Models\Product" into a model class name.
     *
     * @return class-string<Model>
     */
    public function resolveModelClass(string $name): string
    {
        $name = str_replace('/', '\\', trim($name));

        if (is_a($name, Model::class, true)) {
            /** @var class-string<Model> $name */
            return $name;
        }

        /** @var array<int, string> $namespaces */
        $namespaces = (array) $this->config->get('duplicate-toolkit.model_namespaces', ['App\\Models']);

        foreach ($namespaces as $namespace) {
            $candidate = rtrim($namespace, '\\').'\\'.ltrim($name, '\\');

            if (is_a($candidate, Model::class, true)) {
                /** @var class-string<Model> $candidate */
                return $candidate;
            }
        }

        throw ModelResolutionException::make($name, $namespaces);
    }
}
