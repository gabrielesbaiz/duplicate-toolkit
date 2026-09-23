<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Internal control-flow signal: thrown to unwind the transaction of a dry run
 * once every write has been performed, so nothing is persisted.
 *
 * @internal
 */
final class DryRunRollback extends RuntimeException
{
    public function __construct(public readonly Model $model)
    {
        parent::__construct('Dry run rollback.');
    }
}
