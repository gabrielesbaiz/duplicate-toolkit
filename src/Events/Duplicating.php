<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Events;

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired before anything is written. Returning false from a listener aborts the
 * whole duplication run.
 */
final readonly class Duplicating
{
    use Dispatchable;

    public function __construct(
        public Model $source,
        public DuplicateOptions $options,
    ) {}
}
