<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Enums;

/**
 * The way a single relation is handled while duplicating a model.
 */
enum RelationStrategy: string
{
    /**
     * Replicate the related records and attach the copies to the duplicate.
     */
    case Copy = 'copy';

    /**
     * Leave the related records untouched and point the duplicate at the
     * originals. For pivoted relations this re-attaches the same pivot rows.
     */
    case Reference = 'reference';

    /**
     * Ignore the relation entirely.
     */
    case Skip = 'skip';

    /**
     * Get the human readable label for the strategy.
     */
    public function label(): string
    {
        return match ($this) {
            self::Copy => 'Copy',
            self::Reference => 'Reference',
            self::Skip => 'Skip',
        };
    }

    /**
     * Get a short description of what the strategy does.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Copy => 'Duplicate the related records',
            self::Reference => 'Attach the existing related records',
            self::Skip => 'Do not touch the relation',
        };
    }
}
