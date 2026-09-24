<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A single attribute override applied to a duplicate before it is saved.
 */
final readonly class AttributeMutator
{
    /**
     * Create a new attribute mutator instance.
     */
    private function __construct(
        public string $column,
        /** @var Closure(mixed, Model, Model): mixed */
        public Closure $mutator,
    ) {}

    /**
     * Create a mutator that forces a column to the given value.
     */
    public static function set(string $column, mixed $value): self
    {
        return new self(
            $column,
            $value instanceof Closure
                ? $value
                : static fn (): mixed => $value,
        );
    }

    /**
     * Create a mutator that prepends a string to a column.
     */
    public static function prefix(string $column, string $prefix): self
    {
        return new self($column, static fn (mixed $value): string => $prefix.Cast::toString($value));
    }

    /**
     * Create a mutator that appends a string to a column.
     */
    public static function suffix(string $column, string $suffix): self
    {
        return new self($column, static fn (mixed $value): string => Cast::toString($value).$suffix);
    }

    /**
     * Create a mutator that searches and replaces within a column.
     */
    public static function replace(string $column, string $search, string $replace): self
    {
        return new self($column, static fn (mixed $value): string => str_replace($search, $replace, Cast::toString($value)));
    }

    /**
     * Create a mutator that defers to the given callback.
     *
     * @param  Closure(mixed, Model, Model): mixed  $callback
     */
    public static function using(string $column, Closure $callback): self
    {
        return new self($column, $callback);
    }

    /**
     * Apply the mutator to the duplicate.
     */
    public function apply(Model $duplicate, Model $source): void
    {
        $duplicate->setAttribute(
            $this->column,
            ($this->mutator)($duplicate->getAttribute($this->column), $duplicate, $source),
        );
    }
}
