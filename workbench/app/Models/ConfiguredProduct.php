<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Gabrielesbaiz\DuplicateToolkit\Contracts\Duplicatable;
use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;

/**
 * A Product that declares its own duplication options.
 */
class ConfiguredProduct extends Product implements Duplicatable
{
    protected $table = 'products';

    /**
     * Share the parent's morph identity so fixtures created as a Product are
     * still reachable from this subclass.
     */
    public function getMorphClass(): string
    {
        return Product::class;
    }

    public function duplicateOptions(): DuplicateOptions
    {
        return DuplicateOptions::make()
            ->excludeColumns('sku')
            ->uniqueColumns('name')
            ->excludeRelations('notes')
            ->quietly();
    }
}
