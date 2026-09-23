<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Exceptions;

use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates;
use Gabrielesbaiz\DuplicateToolkit\Contracts\Duplicatable;

final class NotDuplicatableException extends DuplicateToolkitException
{
    public static function make(object $model): self
    {
        return new self(sprintf(
            '[%s] cannot be duplicated. Add the %s trait and implement %s.',
            $model::class,
            HasDuplicates::class,
            Duplicatable::class,
        ));
    }

    public static function unsavedModel(object $model): self
    {
        return new self(sprintf(
            '[%s] does not exist in the database yet and therefore cannot be duplicated.',
            $model::class,
        ));
    }
}
