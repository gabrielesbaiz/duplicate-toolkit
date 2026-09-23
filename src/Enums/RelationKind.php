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
 * Classification of a relation, used to pick a sane default strategy.
 */
enum RelationKind: string
{
    /** hasOne, morphOne */
    case ChildSingle = 'child_single';

    /** hasMany, morphMany */
    case ChildMultiple = 'child_multiple';

    /** belongsTo, morphTo */
    case Parental = 'parent';

    /** belongsToMany, morphToMany */
    case Pivoted = 'pivoted';

    /** hasOneThrough, hasManyThrough */
    case Through = 'through';

    /**
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

    public function isChild(): bool
    {
        return $this === self::ChildSingle || $this === self::ChildMultiple;
    }

    public function isPivoted(): bool
    {
        return $this === self::Pivoted;
    }

    public function isParent(): bool
    {
        return $this === self::Parental;
    }

    /**
     * Relations that can never be duplicated safely: duplicating a parent or a
     * "through" relation would create rows the duplicate does not own.
     */
    public function isDuplicatable(): bool
    {
        return $this->isChild() || $this->isPivoted();
    }
}
