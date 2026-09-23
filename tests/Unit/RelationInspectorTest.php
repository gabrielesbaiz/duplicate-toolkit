<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\Enums\RelationKind;
use Gabrielesbaiz\DuplicateToolkit\Exceptions\RelationNotFoundException;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;
use Workbench\App\Models\Category;
use Workbench\App\Models\Product;
use Workbench\App\Models\Version;

beforeEach(function (): void {
    $this->inspector = app(RelationInspector::class);
});

it('discovers relations from return types', function (): void {
    $relations = $this->inspector->for(new Product);

    expect(array_keys($relations))->toContain('versions', 'setting', 'notes', 'tags', 'labels', 'supplier', 'documents');
});

it('never invokes non relation methods', function (): void {
    // Product::explode() throws if called.
    expect(fn (): array => $this->inspector->for(new Product))->not->toThrow(RuntimeException::class);
});

it('classifies each relation kind', function (): void {
    $relations = $this->inspector->for(new Product);

    expect($relations['versions']->kind)->toBe(RelationKind::ChildMultiple)
        ->and($relations['setting']->kind)->toBe(RelationKind::ChildSingle)
        ->and($relations['notes']->kind)->toBe(RelationKind::ChildMultiple)
        ->and($relations['tags']->kind)->toBe(RelationKind::Pivoted)
        ->and($relations['labels']->kind)->toBe(RelationKind::Pivoted)
        ->and($relations['supplier']->kind)->toBe(RelationKind::Parental);
});

it('excludes parents from the duplicatable set', function (): void {
    expect(array_keys($this->inspector->duplicatableFor(new Product)))
        ->not->toContain('supplier')
        ->toContain('versions');
});

it('resolves the related model class', function (): void {
    expect($this->inspector->get(new Product, 'versions')->relatedClass)->toBe(Version::class);
});

// The 1.x RelationHelper kept a single static accumulator, so the relations of
// the first inspected model leaked into every later one.
it('does not leak relations between model classes', function (): void {
    $product = array_keys($this->inspector->for(new Product));
    $version = array_keys($this->inspector->for(new Version));

    expect($version)->not->toContain('tags')
        ->and($version)->not->toContain('setting')
        ->and($version)->toContain('descriptions')
        ->and($product)->toContain('tags');
});

it('throws for an unknown relation', function (): void {
    $this->inspector->get(new Product, 'nope');
})->throws(RelationNotFoundException::class);

it('stops the relation tree at a cycle', function (): void {
    $tree = $this->inspector->tree(new Category, depth: 5);

    expect($tree)->toHaveKey('children')
        ->and($tree['children']['children'])->toBe([]);
});

it('can be flushed per class', function (): void {
    $this->inspector->for(new Product);
    $this->inspector->flush(Product::class);

    expect($this->inspector->for(new Product))->toHaveKey('versions');
});
