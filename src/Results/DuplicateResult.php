<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Results;

use Gabrielesbaiz\DuplicateToolkit\Support\DuplicateContext;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a duplication run produced.
 *
 * @template TModel of Model
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DuplicateResult implements Arrayable
{
    /**
     * @param  TModel  $model
     * @param  TModel  $source
     */
    public function __construct(
        public Model $model,
        public Model $source,
        public DuplicateContext $context,
        public bool $dryRun = false,
        public float $durationMs = 0.0,
    ) {}

    /**
     * Old primary key => new primary key, for the given model class.
     *
     * Without an argument the whole map is returned, keyed by class name.
     *
     * @param  class-string<Model>|null  $class
     * @return array<array-key, array-key>|array<class-string<Model>, array<array-key, array-key>>
     */
    public function idMap(?string $class = null): array
    {
        return $this->context->idMap($class);
    }

    /**
     * The new primary key created for a given source record.
     */
    public function newKeyFor(Model $source): int|string|null
    {
        return $this->context->newKeyFor($source);
    }

    /**
     * Number of records created, in total or for one model class.
     *
     * @param  class-string<Model>|null  $class
     */
    public function count(?string $class = null): int
    {
        if ($class === null) {
            return $this->context->total();
        }

        return $this->context->counts()[$class] ?? 0;
    }

    /**
     * @return array<class-string<Model>, int>
     */
    public function counts(): array
    {
        return $this->context->counts();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model::class,
            'key' => $this->model->getKey(),
            'source_key' => $this->source->getKey(),
            'dry_run' => $this->dryRun,
            'duration_ms' => round($this->durationMs, 2),
            'total' => $this->count(),
            'counts' => $this->counts(),
        ];
    }
}
