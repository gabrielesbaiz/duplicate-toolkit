<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;

/**
 * Declares options through the trait alone, without implementing Duplicatable
 * — the shape the 1.x upgrade codemod produces.
 */
class TraitOnlyProduct extends Product
{
    protected $table = 'products';

    public function getMorphClass(): string
    {
        return Product::class;
    }

    public function duplicateOptions(): DuplicateOptions
    {
        return DuplicateOptions::make()->excludeColumns('sku')->excludeRelations('versions');
    }
}
