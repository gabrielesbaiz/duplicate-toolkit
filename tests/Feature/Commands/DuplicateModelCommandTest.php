<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\Jobs\DuplicateModel;
use Illuminate\Support\Facades\Queue;
use Workbench\App\Models\Product;
use Workbench\App\Models\Tag;
use Workbench\App\Models\Version;

it('duplicates non interactively', function (): void {
    $product = makeProduct();

    $this->artisan('duplicate-toolkit:duplicate', [
        'model' => Product::class,
        'id' => $product->id,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Product::count())->toBe(2)
        ->and(Version::count())->toBe(4);
});

it('accepts a short model name', function (): void {
    config()->set('duplicate-toolkit.model_namespaces', ['Workbench\\App\\Models']);

    $product = makeProduct();

    $this->artisan('duplicate-toolkit:duplicate', [
        'model' => 'Product',
        'id' => $product->id,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Product::count())->toBe(2);
});

it('limits the duplication to the given relations', function (): void {
    $product = makeProduct();

    $this->artisan('duplicate-toolkit:duplicate', [
        'model' => Product::class,
        'id' => $product->id,
        '--only' => ['versions'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    $copy = Product::latest('id')->firstOrFail();

    expect($copy->versions()->count())->toBe(2)
        ->and($copy->setting()->count())->toBe(0)
        ->and($copy->tags()->count())->toBe(0);
});

it('applies attribute overrides from flags', function (): void {
    $product = makeProduct();

    $this->artisan('duplicate-toolkit:duplicate', [
        'model' => Product::class,
        'id' => $product->id,
        '--suffix' => ['name= - COPIA'],
        '--set' => ['sku=NEW'],
        '--replace' => ['is_active=1:0'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    $copy = Product::latest('id')->firstOrFail();

    expect($copy->name)->toBe('Widget - COPIA')
        ->and($copy->sku)->toBe('NEW');
});

it('writes nothing on a dry run', function (): void {
    $product = makeProduct();

    $this->artisan('duplicate-toolkit:duplicate', [
        'model' => Product::class,
        'id' => $product->id,
        '--dry-run' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Product::count())->toBe(1)
        ->and(Version::count())->toBe(2);
});

it('dispatches to the queue when asked', function (): void {
    Queue::fake();

    $product = makeProduct();

    $this->artisan('duplicate-toolkit:duplicate', [
        'model' => Product::class,
        'id' => $product->id,
        '--queue' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    Queue::assertPushed(DuplicateModel::class);
    expect(Product::count())->toBe(1);
});

it('can copy a pivoted relation from flags', function (): void {
    $product = makeProduct();

    $this->artisan('duplicate-toolkit:duplicate', [
        'model' => Product::class,
        'id' => $product->id,
        '--only' => ['tags'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(Tag::count())->toBe(2);
});

it('fails when the record does not exist', function (): void {
    $this->artisan('duplicate-toolkit:duplicate', [
        'model' => Product::class,
        'id' => 999,
        '--no-interaction' => true,
    ])->assertFailed();
});

it('requires an id when not interactive', function (): void {
    $this->artisan('duplicate-toolkit:duplicate', [
        'model' => Product::class,
        '--no-interaction' => true,
    ])->assertFailed();
});
