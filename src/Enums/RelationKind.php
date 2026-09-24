<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Enums;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The classification of a relation, used to pick a sane default strategy.
 */
enum RelationKind: string
{
    /** The hasOne and morphOne relations. */
    case ChildSingle = 'child_single';

    /** The hasMany and morphMany relations. */
    case ChildMultiple = 'child_multiple';

    /** The belongsTo and morphTo relations. */
    case Parental = 'parent';

    /** The belongsToMany and morphToMany relations. */
    case Pivoted = 'pivoted';

    /** The hasOneThrough and hasManyThrough relations. */
    case Through = 'through';

    /**
     * Classify the given relation class.
     *
     * @param  class-string<Relation<*, *, *>>  $relationClass
     */
    public static function fromRelationClass(string $relationClass): ?self
    {
        return match (true) {
            is_a($relationClass, MorphToMany::class, true) => self::Pivoted,
            is_a($relationClass, BelongsToMany::class, true) => self::Pivoted,
            is_a($relationClass, MorphTo::class, true) => self::Parental,
            is_a($relationClass, BelongsTo::class, true) => self::Parental,
            is_a($relationClass, MorphOne::class, true) => self::ChildSingle,
            is_a($relationClass, MorphMany::class, true) => self::ChildMultiple,
            is_a($relationClass, HasOneThrough::class, true) => self::Through,
            is_a($relationClass, HasManyThrough::class, true) => self::Through,
            is_a($relationClass, HasOne::class, true) => self::ChildSingle,
            is_a($relationClass, HasMany::class, true) => self::ChildMultiple,
            default => null,
        };
    }

    /**
     * Determine if the relation points at records owned by the model.
     */
    public function isChild(): bool
    {
        return $this === self::ChildSingle || $this === self::ChildMultiple;
    }

    /**
     * Determine if the relation is backed by a pivot table.
     */
    public function isPivoted(): bool
    {
        return $this === self::Pivoted;
    }

    /**
     * Determine if the relation points at a record that owns the model.
     */
    public function isParent(): bool
    {
        return $this === self::Parental;
    }

    /**
     * Determine if the relation may be duplicated safely.
     *
     * Duplicating a parent or a "through" relation would create rows that the
     * duplicate does not own, so only children and pivoted relations qualify.
     */
    public function isDuplicatable(): bool
    {
        return $this->isChild() || $this->isPivoted();
    }
}
