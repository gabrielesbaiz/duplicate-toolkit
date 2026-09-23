<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

class Product extends Model
{
    use HasDuplicates;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'bool',
        'versions_count' => 'int',
    ];

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<Version, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class, 'product_id');
    }

    /**
     * @return HasOne<Setting, $this>
     */
    public function setting(): HasOne
    {
        return $this->hasOne(Setting::class, 'product_id');
    }

    /**
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /**
     * @return BelongsToMany<Tag, $this, Pivot>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag', 'product_id', 'tag_id')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * @return MorphToMany<Label, $this, MorphPivot>
     */
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable')->withPivot('note');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'product_id');
    }

    /**
     * Not a relation: proves discovery ignores non-relation methods and never
     * invokes them.
     */
    public function explode(): string
    {
        throw new RuntimeException('Discovery must never call this method.');
    }
}
