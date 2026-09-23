<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Support;

use Gabrielesbaiz\DuplicateToolkit\Enums\RelationKind;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Immutable description of a single relation discovered on a model class.
 */
final readonly class RelationMeta
{
    /**
     * @param  class-string<Model>  $parentClass
     * @param  class-string<Relation<*, *, *>>  $type
     * @param  class-string<Model>|null  $relatedClass
     */
    public function __construct(
        public string $name,
        public string $parentClass,
        public string $type,
        public ?string $relatedClass,
        public RelationKind $kind,
    ) {}

    public function shortType(): string
    {
        return class_basename($this->type);
    }

    public function relatedBasename(): string
    {
        return $this->relatedClass === null ? '?' : class_basename($this->relatedClass);
    }

    /**
     * The strategy applied when the user expressed no preference.
     */
    public function defaultStrategy(): RelationStrategy
    {
        if ($this->kind->isPivoted()) {
            return RelationStrategy::Reference;
        }

        if ($this->kind->isChild()) {
            return RelationStrategy::Copy;
        }

        return RelationStrategy::Skip;
    }

    /**
     * @return array{name: string, type: string, related: string|null, kind: string, default: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->shortType(),
            'related' => $this->relatedClass,
            'kind' => $this->kind->value,
            'default' => $this->defaultStrategy()->value,
        ];
    }
}
