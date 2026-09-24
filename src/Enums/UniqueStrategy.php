<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Enums;

/**
 * The way a value is made unique when a column is declared via uniqueColumns().
 */
enum UniqueStrategy: string
{
    /**
     * Append an incrementing suffix, e.g. "Name (1)", "Name (2)".
     */
    case NumericSuffix = 'numeric_suffix';

    /**
     * Append a short UUID fragment.
     */
    case Uuid = 'uuid';

    /**
     * Append a ULID fragment.
     */
    case Ulid = 'ulid';

    /**
     * Append the current unix timestamp.
     */
    case Timestamp = 'timestamp';
}
