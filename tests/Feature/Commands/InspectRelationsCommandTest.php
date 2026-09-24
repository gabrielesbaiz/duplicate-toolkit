<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\Exceptions\ModelResolutionException;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Product;

it('lists the duplicatable relations of a model', function (): void {
    $this->artisan('duplicate-toolkit:relations', ['model' => Product::class, '--depth' => 0])
        ->assertSuccessful();
});

it('emits machine readable json', function (): void {
    $exitCode = Artisan::call('duplicate-toolkit:relations', [
        'model' => Product::class,
        '--depth' => 0,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);

    /** @var array<int, array<string, string>> $rows */
    $rows = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    $byName = collect($rows)->keyBy('relation');

    expect($byName->keys()->all())->toContain('versions', 'setting', 'notes', 'tags', 'labels')
        ->and($byName['versions']['strategy'])->toBe('copy')
        ->and($byName['versions']['related'])->toBe('Version')
        ->and($byName['tags']['strategy'])->toBe('reference')
        ->and($byName->keys()->all())->not->toContain('supplier');
});

it('includes parent relations with --all', function (): void {
    Artisan::call('duplicate-toolkit:relations', [
        'model' => Product::class,
        '--depth' => 0,
        '--all' => true,
        '--json' => true,
    ]);

    $rows = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect(collect($rows)->pluck('relation')->all())->toContain('supplier');
});

it('walks nested relations', function (): void {
    Artisan::call('duplicate-toolkit:relations', [
        'model' => Product::class,
        '--depth' => 2,
        '--json' => true,
    ]);

    $rows = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect(collect($rows)->pluck('relation')->all())->toContain('versions.descriptions');
});

it('counts related rows on request', function (): void {
    makeProduct();

    Artisan::call('duplicate-toolkit:relations', [
        'model' => Product::class,
        '--depth' => 0,
        '--with-counts' => true,
        '--json' => true,
    ]);

    $rows = collect(json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR))->keyBy('relation');

    expect($rows['versions']['rows'])->toBe('2');
});

it('fails clearly for an unknown model', function (): void {
    $this->artisan('duplicate-toolkit:relations', ['model' => 'Nope']);
})->throws(ModelResolutionException::class);
