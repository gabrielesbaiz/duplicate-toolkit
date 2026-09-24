<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Workbench\App\Models\LegacyProduct;

it('keeps a 1.x model booting and duplicating', function (): void {
    $product = makeProduct();
    $legacy = LegacyProduct::findOrFail($product->id);

    // The 1.x entry point, the 1.x options method and the 1.x option names,
    // exactly as an unmigrated application would still be calling them.
    $copy = $legacy->saveAsDuplicate();

    // A null sku proves getDuplicateOptions() was honoured.
    expect($copy->exists)->toBeTrue()
        ->and($copy->sku)->toBeNull()
        ->and($copy->versions()->count())->toBe(0);
});

it('aliases the 1.x options class to the 2.0 one', function (): void {
    // The alias is registered by the service provider, before any model of
    // the application has been touched.
    expect(class_exists('Gabrielesbaiz\DuplicateToolkit\Options\DuplicateOptions'))->toBeTrue()
        ->and(Gabrielesbaiz\DuplicateToolkit\Options\DuplicateOptions::instance())
        ->toBeInstanceOf(DuplicateOptions::class);
});

it('keeps the 1.x option method names working', function (): void {
    $options = DuplicateOptions::make()->saveQuietly()->disableDeepDuplication();

    expect($options->shouldSaveQuietly())->toBeTrue()
        ->and($options->getDepth())->toBe(0);
});
