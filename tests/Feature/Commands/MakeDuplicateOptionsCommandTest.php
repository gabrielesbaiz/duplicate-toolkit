<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\ConfiguredProduct;
use Workbench\App\Models\Product;

it('prints a duplicateOptions method', function (): void {
    Artisan::call('duplicate-toolkit:make-options', [
        'model' => Product::class,
        '--print' => true,
        '--no-interaction' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('public function duplicateOptions(): DuplicateOptions')
        ->and($output)->toContain("->excludeColumns('versions_count')")
        ->and($output)->toContain('return DuplicateOptions::make()');
});

it('refuses to overwrite an existing method without --force', function (): void {
    $this->artisan('duplicate-toolkit:make-options', [
        'model' => ConfiguredProduct::class,
        '--no-interaction' => true,
    ])->assertFailed();
});
