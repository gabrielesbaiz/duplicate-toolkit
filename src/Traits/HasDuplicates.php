<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Traits;

use Closure;
use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates as Concern;
use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Illuminate\Database\Eloquent\Model;

/**
 * A backwards compatibility shim for the 1.x trait location.
 *
 * Installing 2.0 must not stop an application booting, or the
 * duplicate-toolkit:upgrade command could never be reached on the very
 * codebase it exists to migrate.
 *
 * A 1.x getDuplicateOptions() method is honoured by the Duplicator itself, so
 * it keeps working with or without this trait.
 *
 * @deprecated 2.0 Use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates.
 *             Run `php artisan duplicate-toolkit:upgrade`. Removed in 3.0.
 *
 * @phpstan-require-extends Model
 */
trait HasDuplicates
{
    use Concern;

    /**
     * Duplicate this record and return the new model.
     *
     * @deprecated 2.0 Use duplicate().
     *
     * @param  DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions|null  $options
     */
    public function saveAsDuplicate(DuplicateOptions|Closure|null $options = null): static
    {
        return $this->duplicate($options);
    }
}
