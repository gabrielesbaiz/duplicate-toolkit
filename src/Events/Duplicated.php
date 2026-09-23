<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Events;

use Gabrielesbaiz\DuplicateToolkit\Results\DuplicateResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after the transaction commits, carrying the full result including the
 * old key => new key map.
 */
final readonly class Duplicated
{
    use Dispatchable;

    /**
     * @param  DuplicateResult<covariant Model>  $result
     */
    public function __construct(
        public Model $source,
        public Model $duplicate,
        public DuplicateResult $result,
    ) {}
}
