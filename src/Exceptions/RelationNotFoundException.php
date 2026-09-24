<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Exceptions;

final class RelationNotFoundException extends DuplicateToolkitException
{
    /**
     * Create a new exception for a relation the model does not define.
     *
     * @param  class-string  $modelClass
     * @param  array<int, string>  $available
     */
    public static function make(string $modelClass, string $relation, array $available = []): self
    {
        $message = sprintf('Relation [%s] is not defined on [%s].', $relation, $modelClass);

        if ($available !== []) {
            $message .= ' Available relations: '.implode(', ', $available).'.';
        }

        return new self($message);
    }
}
