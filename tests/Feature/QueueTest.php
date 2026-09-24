<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Duplicator;
use Gabrielesbaiz\DuplicateToolkit\Facades\Duplicate;
use Gabrielesbaiz\DuplicateToolkit\Jobs\DuplicateModel;
use Illuminate\Support\Facades\Queue;
use Workbench\App\Models\Product;

it('dispatches a job instead of duplicating inline', function (): void {
    Queue::fake();

    $product = makeProduct();

    Duplicate::of($product)->onQueue('duplicates')->dispatch();

    Queue::assertPushedOn('duplicates', DuplicateModel::class);
    expect(Product::count())->toBe(1);
});

it('duplicates when the job runs', function (): void {
    $product = makeProduct();

    (new DuplicateModel($product, DuplicateOptions::make()->suffix('name', ' - QUEUED')))
        ->handle(app(Duplicator::class));

    expect(Product::count())->toBe(2)
        ->and(Product::latest('id')->firstOrFail()->name)->toBe('Widget - QUEUED');
});

it('tags the job with the source model', function (): void {
    $product = makeProduct();

    expect((new DuplicateModel($product))->tags())
        ->toContain('duplicate-toolkit')
        ->toContain(Product::class.':'.$product->id);
});

it('keeps the configured queue when none is given', function (): void {
    Queue::fake();

    config()->set('duplicate-toolkit.queue.queue', 'configured');

    Duplicate::of(makeProduct())->dispatch();

    Queue::assertPushedOn('configured', DuplicateModel::class);
});
