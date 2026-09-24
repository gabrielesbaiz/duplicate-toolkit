<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Gabrielesbaiz\DuplicateToolkit\Contracts\Duplicatable;
use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;

/**
 * A product that declares its own duplication options.
 */
class ConfiguredProduct extends Product implements Duplicatable
{
    protected $table = 'products';

    /**
     * Get the class name for polymorphic relations.
     *
     * The parent morph identity is shared so that a fixture created as a
     * product is still reachable through this subclass.
     */
    public function getMorphClass(): string
    {
        return Product::class;
    }

    /**
     * Get the options describing how this model should be duplicated.
     */
    public function duplicateOptions(): DuplicateOptions
    {
        return DuplicateOptions::make()
            ->excludeColumns('sku')
            ->uniqueColumns('name')
            ->excludeRelations('notes')
            ->quietly();
    }
}
