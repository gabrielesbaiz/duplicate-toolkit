<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;

/**
 * A product declaring options through the trait alone, without implementing
 * the Duplicatable contract, which is the shape the upgrade codemod produces.
 */
class TraitOnlyProduct extends Product
{
    protected $table = 'products';

    /**
     * Get the class name for polymorphic relations.
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
        return DuplicateOptions::make()->excludeColumns('sku')->excludeRelations('versions');
    }
}
