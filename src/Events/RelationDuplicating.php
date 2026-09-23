<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Events;

use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class RelationDuplicating
{
    use Dispatchable;

    public function __construct(
        public Model $source,
        public Model $duplicate,
        public RelationMeta $relation,
        public RelationStrategy $strategy,
    ) {}
}
