<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Events;

use Gabrielesbaiz\DuplicateToolkit\Results\DuplicateResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once the transaction has committed, carrying the full result of the
 * run including the old key to new key map.
 */
final readonly class Duplicated
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * @param  DuplicateResult<covariant Model>  $result
     */
    public function __construct(
        public Model $source,
        public Model $duplicate,
        public DuplicateResult $result,
    ) {}
}
