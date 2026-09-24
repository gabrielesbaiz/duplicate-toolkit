<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Contracts;

use Gabrielesbaiz\DuplicateToolkit\Support\DuplicateContext;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom handler for one relation, registered with
 * DuplicateOptions::relationUsing().
 */
interface DuplicatesRelation
{
    /**
     * Duplicate the given relation from the source model onto the duplicate.
     */
    public function handle(Model $source, Model $duplicate, RelationMeta $relation, DuplicateContext $context): void;
}
