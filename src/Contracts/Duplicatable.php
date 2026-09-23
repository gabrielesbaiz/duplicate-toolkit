<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Contracts;

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;

/**
 * Implemented by every Eloquent model that can be duplicated.
 *
 * The Concerns\HasDuplicates trait supplies a sensible default implementation,
 * so models only need to override duplicateOptions() when they want to.
 */
interface Duplicatable
{
    public function duplicateOptions(): DuplicateOptions;
}
