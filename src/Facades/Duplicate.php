<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Facades;

use Gabrielesbaiz\DuplicateToolkit\DuplicateManager;
use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\PendingDuplicate;
use Gabrielesbaiz\DuplicateToolkit\Results\DuplicateResult;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingDuplicate<Model> of(Model $model)
 * @method static DuplicateResult<Model> run(Model $model, ?DuplicateOptions $options = null)
 * @method static array<string, RelationMeta> relations(Model $model)
 * @method static array<string, RelationMeta> duplicatableRelations(Model $model)
 * @method static array<string, array{meta: RelationMeta, children: array<string, mixed>}> tree(Model $model, int $depth = 1)
 * @method static class-string<Model> resolveModelClass(string $name)
 *
 * @see DuplicateManager
 */
final class Duplicate extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return DuplicateManager::class;
    }
}
