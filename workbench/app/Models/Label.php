<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Label extends Model
{
    protected $guarded = [];

    /**
     * @return MorphToMany<Product, $this, MorphPivot>
     */
    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'labelable');
    }
}
