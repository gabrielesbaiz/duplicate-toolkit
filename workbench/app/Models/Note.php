<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Note extends Model
{
    use HasDuplicates;

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }
}
