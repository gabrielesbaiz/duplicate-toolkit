<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class MaxDepthExceededException extends DuplicateToolkitException
{
    public static function for(Model $model, int $depth): self
    {
        return new self(sprintf(
            'Maximum duplication depth of %d exceeded while duplicating [%s]. Raise it with ->depth() or the "duplicate-toolkit.max_depth" config value.',
            $depth,
            $model::class,
        ));
    }
}
