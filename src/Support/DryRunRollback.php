<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown to unwind the transaction of a dry run once every write has been
 * performed, so that the reported plan is exact yet nothing is persisted.
 *
 * @internal
 */
final class DryRunRollback extends RuntimeException
{
    /**
     * Create a new dry run rollback instance.
     */
    public function __construct(public readonly Model $model)
    {
        parent::__construct('Dry run rollback.');
    }
}
