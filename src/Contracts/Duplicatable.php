<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Contracts;

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;

/**
 * Implemented by every Eloquent model that may be duplicated.
 *
 * The HasDuplicates concern already supplies a default implementation, so a
 * model only has to override duplicateOptions() when it wants to.
 */
interface Duplicatable
{
    /**
     * Get the options describing how this model should be duplicated.
     */
    public function duplicateOptions(): DuplicateOptions;
}
