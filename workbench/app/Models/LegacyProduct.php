<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Gabrielesbaiz\DuplicateToolkit\Options\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates;

/**
 * A model written against the 1.x API and never touched by the codemod.
 *
 * It has to keep working, or an application could never boot far enough to
 * run the codemod that would have migrated it.
 *
 * @deprecated Fixture for the 1.x compatibility layer; uses it deliberately.
 */
class LegacyProduct extends Product
{
    use HasDuplicates;

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
    public function getDuplicateOptions(): DuplicateOptions
    {
        return DuplicateOptions::instance()
            ->excludeColumns('sku')
            ->excludeRelations('versions')
            ->saveQuietly();
    }
}
