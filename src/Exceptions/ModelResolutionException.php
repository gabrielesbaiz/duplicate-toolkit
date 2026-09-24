<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Exceptions;

final class ModelResolutionException extends DuplicateToolkitException
{
    /**
     * Create a new exception for a model name that could not be resolved.
     *
     * @param  array<int, string>  $namespaces
     */
    public static function make(string $name, array $namespaces): self
    {
        return new self(sprintf(
            'Unable to resolve model [%s]. Looked in: %s. Pass a fully qualified class name or add the namespace to "duplicate-toolkit.model_namespaces".',
            $name,
            implode(', ', $namespaces),
        ));
    }
}
