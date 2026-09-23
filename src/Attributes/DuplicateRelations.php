<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Attributes;

use Attribute;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;

/**
 * Declares, on the model class itself, how its relations should be treated.
 *
 * #[DuplicateRelations(copy: ['versions'], reference: ['tags'], skip: ['logs'])]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DuplicateRelations
{
    /**
     * @param  array<int, string>  $copy
     * @param  array<int, string>  $reference
     * @param  array<int, string>  $skip
     */
    public function __construct(
        public array $copy = [],
        public array $reference = [],
        public array $skip = [],
    ) {}

    /**
     * @return array<string, RelationStrategy>
     */
    public function toStrategies(): array
    {
        $strategies = [];

        foreach ($this->copy as $relation) {
            $strategies[$relation] = RelationStrategy::Copy;
        }

        foreach ($this->reference as $relation) {
            $strategies[$relation] = RelationStrategy::Reference;
        }

        foreach ($this->skip as $relation) {
            $strategies[$relation] = RelationStrategy::Skip;
        }

        return $strategies;
    }
}
