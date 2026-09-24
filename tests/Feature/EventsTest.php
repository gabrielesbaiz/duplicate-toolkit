<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Events\Duplicated;
use Gabrielesbaiz\DuplicateToolkit\Events\Duplicating;
use Gabrielesbaiz\DuplicateToolkit\Events\RelationDuplicated;
use Gabrielesbaiz\DuplicateToolkit\Events\RelationDuplicating;
use Gabrielesbaiz\DuplicateToolkit\Exceptions\DuplicateToolkitException;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\Product;
use Workbench\App\Models\Version;

it('dispatches the lifecycle events', function (): void {
    Event::fake([Duplicating::class, Duplicated::class, RelationDuplicating::class, RelationDuplicated::class]);

    $product = makeProduct();
    $product->duplicate();

    Event::assertDispatched(Duplicating::class);
    Event::assertDispatched(Duplicated::class);
    Event::assertDispatched(RelationDuplicating::class);
    Event::assertDispatched(RelationDuplicated::class);
});

it('carries the result on the duplicated event', function (): void {
    $captured = null;

    Event::listen(Duplicated::class, function (Duplicated $event) use (&$captured): void {
        $captured = $event;
    });

    $product = makeProduct();
    $copy = $product->duplicate();

    expect($captured)->toBeInstanceOf(Duplicated::class)
        ->and($captured->duplicate->id)->toBe($copy->id)
        ->and($captured->result->idMap(Version::class))->toHaveCount(2);
});

it('reports the relation strategy on the relation events', function (): void {
    $strategies = [];

    Event::listen(RelationDuplicated::class, function (RelationDuplicated $event) use (&$strategies): void {
        $strategies[$event->relation->name] = $event->strategy->value;
    });

    makeProduct()->duplicate();

    expect($strategies['versions'])->toBe('copy')
        ->and($strategies['tags'])->toBe('reference');
});

it('fires the duplicating and duplicated model events', function (): void {
    $fired = [];

    Product::duplicating(function () use (&$fired): void {
        $fired[] = 'duplicating';
    });

    Product::duplicated(function () use (&$fired): void {
        $fired[] = 'duplicated';
    });

    makeProduct()->duplicate();

    expect($fired)->toBe(['duplicating', 'duplicated']);

    Product::flushEventListeners();
});

it('aborts when a duplicating listener returns false', function (): void {
    Product::duplicating(fn (): bool => false);

    $product = makeProduct();

    try {
        expect(fn (): Product => $product->duplicate())->toThrow(DuplicateToolkitException::class);
        expect(Product::count())->toBe(1);
    } finally {
        Product::flushEventListeners();
    }
});

it('runs the before and after save callbacks', function (): void {
    $seen = [];

    // A full closure rather than an arrow function, because an arrow function
    // captures by value and would bind the nested callbacks to a copy of $seen.
    $copy = makeProduct()->duplicate(function (DuplicateOptions $options) use (&$seen): DuplicateOptions {
        return $options
            ->beforeSave(function ($duplicate) use (&$seen): void {
                $seen[] = 'before:'.($duplicate->exists ? 'saved' : 'new');
                $duplicate->sku = 'TOUCHED';
            })
            ->afterSave(function ($duplicate) use (&$seen): void {
                $seen[] = 'after:'.($duplicate->exists ? 'saved' : 'new');
            });
    });

    expect($copy->sku)->toBe('TOUCHED')
        ->and($seen)->toBe(['before:new', 'after:saved']);
});

it('can save without firing eloquent events', function (): void {
    $saved = 0;

    Product::created(function () use (&$saved): void {
        $saved++;
    });

    try {
        makeProduct()->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->quietly());

        // Only the fixture fired the event, never the duplicate.
        expect($saved)->toBe(1);
    } finally {
        Product::flushEventListeners();
    }
});
