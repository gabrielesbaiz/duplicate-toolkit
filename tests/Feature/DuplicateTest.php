<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\DuplicateManager;
use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Enums\UniqueStrategy;
use Gabrielesbaiz\DuplicateToolkit\Exceptions\MaxDepthExceededException;
use Gabrielesbaiz\DuplicateToolkit\Exceptions\NotDuplicatableException;
use Gabrielesbaiz\DuplicateToolkit\Facades\Duplicate;
use Workbench\App\Models\Category;
use Workbench\App\Models\ConfiguredProduct;
use Workbench\App\Models\Description;
use Workbench\App\Models\Document;
use Workbench\App\Models\Label;
use Workbench\App\Models\Note;
use Workbench\App\Models\Product;
use Workbench\App\Models\Setting;
use Workbench\App\Models\Tag;
use Workbench\App\Models\TraitOnlyProduct;
use Workbench\App\Models\Version;

function makeProduct(): Product
{
    $product = Product::create(['name' => 'Widget', 'sku' => 'W-1', 'versions_count' => 7]);

    $v1 = $product->versions()->create(['name' => 'v1']);
    $v1->descriptions()->create(['body' => 'first']);
    $v1->descriptions()->create(['body' => 'second']);

    $product->versions()->create(['name' => 'v2']);
    $product->setting()->create(['locale' => 'it']);
    $product->notes()->create(['body' => 'a note']);

    $product->tags()->attach(Tag::create(['name' => 'alpha'])->id, ['position' => 3]);
    $product->labels()->attach(Label::create(['name' => 'hot'])->id, ['note' => 'pivot note']);

    return $product->fresh();
}

it('duplicates the model itself', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate();

    expect($copy->exists)->toBeTrue()
        ->and($copy->id)->not->toBe($product->id)
        ->and($copy->name)->toBe('Widget')
        ->and($copy->sku)->toBe('W-1');
});

it('refuses to duplicate an unsaved model', function (): void {
    (new Product(['name' => 'x']))->duplicate();
})->throws(NotDuplicatableException::class);

it('copies child relations', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate();

    expect($copy->versions()->count())->toBe(2)
        ->and($copy->setting()->count())->toBe(1)
        ->and($copy->notes()->count())->toBe(1)
        ->and(Version::count())->toBe(4)
        ->and(Setting::count())->toBe(2)
        ->and(Note::count())->toBe(2);
});

it('leaves the source relations untouched', function (): void {
    $product = makeProduct();

    $product->duplicate();

    expect($product->versions()->count())->toBe(2)
        ->and($product->notes()->count())->toBe(1);
});

it('recurses into grandchildren', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate();

    $copiedV1 = $copy->versions()->where('name', 'v1')->firstOrFail();

    expect($copiedV1->descriptions()->count())->toBe(2)
        ->and(Description::count())->toBe(4);
});

it('references pivoted relations by default', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate();

    expect(Tag::count())->toBe(1)
        ->and($copy->tags()->count())->toBe(1)
        ->and($copy->tags()->first()->id)->toBe($product->tags()->first()->id);
});

it('carries pivot payload across a referenced relation', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate();

    expect($copy->tags()->first()->pivot->position)->toBe(3)
        ->and($copy->labels()->first()->pivot->note)->toBe('pivot note');
});

it('can copy a pivoted relation instead of referencing it', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->copyRelations('tags'));

    expect(Tag::count())->toBe(2)
        ->and($copy->tags()->first()->id)->not->toBe($product->tags()->first()->id)
        ->and($copy->tags()->first()->pivot->position)->toBe(3);
});

it('skips excluded relations', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->excludeRelations('versions', 'notes'));

    expect($copy->versions()->count())->toBe(0)
        ->and($copy->notes()->count())->toBe(0)
        ->and($copy->setting()->count())->toBe(1);
});

it('duplicates only the allow listed relations', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->onlyRelations('versions'));

    expect($copy->versions()->count())->toBe(2)
        ->and($copy->setting()->count())->toBe(0)
        ->and($copy->tags()->count())->toBe(0);
});

it('stops at the requested depth', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->depth(1));

    expect($copy->versions()->count())->toBe(2)
        ->and(Description::count())->toBe(2);
});

it('duplicates nothing but the root when shallow', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->shallow());

    expect($copy->versions()->count())->toBe(0)
        ->and(Version::count())->toBe(2);
});

it('excludes columns', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->excludeColumns('sku'));

    expect($copy->sku)->toBeNull();
});

it('excludes columns by pattern', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->excludeColumnsMatching('/_count$/'));

    expect($copy->fresh()->versions_count)->toBe(0)
        ->and($product->versions_count)->toBe(7);
});

it('never copies timestamps', function (): void {
    $product = makeProduct();
    $product->forceFill(['created_at' => now()->subYear()])->saveQuietly();

    $copy = $product->fresh()->duplicate();

    expect($copy->created_at->isToday())->toBeTrue();
});

it('applies attribute overrides before the insert', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o
        ->suffix('name', ' - COPY')
        ->withAttributes(['is_active' => false]));

    expect($copy->name)->toBe('Widget - COPY')
        ->and($copy->is_active)->toBeFalse()
        ->and($copy->wasRecentlyCreated)->toBeTrue();
});

it('supports prefix, replace and closure mutators', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o
        ->prefix('name', '[new] ')
        ->replace('sku', 'W-', 'X-')
        ->mutate('is_active', fn (mixed $value): bool => ! $value));

    expect($copy->name)->toBe('[new] Widget')
        ->and($copy->sku)->toBe('X-1')
        ->and($copy->is_active)->toBeFalse();
});

it('makes unique columns unique', function (): void {
    $product = makeProduct();

    $first = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->uniqueColumns('name'));
    $second = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->uniqueColumns('name'));

    expect($first->name)->toBe('Widget (1)')
        ->and($second->name)->toBe('Widget (2)');
});

it('supports alternative unique strategies', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o
        ->uniqueColumns('name')
        ->uniqueUsing(UniqueStrategy::Timestamp));

    expect($copy->name)->toStartWith('Widget (')
        ->and($copy->name)->not->toBe('Widget (1)');
});

it('honours the model own options', function (): void {
    $product = makeProduct();
    $configured = ConfiguredProduct::findOrFail($product->id);

    $copy = $configured->duplicate();

    expect($copy->sku)->toBeNull()
        ->and($copy->name)->toBe('Widget (1)')
        ->and($copy->notes()->count())->toBe(0)
        ->and($copy->versions()->count())->toBe(2);
});

it('lets call time options override the model options', function (): void {
    $product = makeProduct();
    $configured = ConfiguredProduct::findOrFail($product->id);

    $copy = $configured->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->relation('notes', RelationStrategy::Copy));

    expect($copy->notes()->count())->toBe(1);
});

it('handles uuid keyed models', function (): void {
    $product = makeProduct();
    $product->documents()->create(['title' => 'spec']);

    $copy = $product->fresh()->duplicate();

    expect($copy->documents()->count())->toBe(1)
        ->and(Document::count())->toBe(2)
        ->and($copy->documents()->first()->id)->not->toBe($product->documents()->first()->id);
});

it('records provenance when the column exists', function (): void {
    $product = makeProduct();

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->trackProvenance());

    expect($copy->duplicated_from_id)->toBe($product->id);
});

it('ignores trashed children by default', function (): void {
    $product = makeProduct();
    $product->versions()->firstOrFail()->delete();

    $copy = $product->fresh()->duplicate();

    expect($copy->versions()->count())->toBe(1);
});

it('copies trashed children on request', function (): void {
    $product = makeProduct();
    $product->versions()->firstOrFail()->delete();

    $copy = $product->fresh()->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->withTrashed());

    expect($copy->versions()->withTrashed()->count())->toBe(2);
});

it('exposes the old key to new key map', function (): void {
    $product = makeProduct();
    $originalVersionIds = $product->versions()->pluck('id')->all();

    $result = $product->duplicateWithResult();

    $map = $result->idMap(Version::class);

    expect($map)->toHaveCount(2)
        ->and(array_keys($map))->toEqualCanonicalizing($originalVersionIds)
        ->and($result->newKeyFor($product))->toBe($result->model->id);
});

it('counts the records it created', function (): void {
    $product = makeProduct();

    $result = $product->duplicateWithResult();

    expect($result->count(Product::class))->toBe(1)
        ->and($result->count(Version::class))->toBe(2)
        ->and($result->count(Description::class))->toBe(2)
        ->and($result->count())->toBe(7);
});

it('writes nothing during a preview', function (): void {
    $product = makeProduct();

    $plan = Duplicate::of($product)->preview();

    expect($plan->dryRun)->toBeTrue()
        ->and($plan->count())->toBe(7)
        ->and(Product::count())->toBe(1)
        ->and(Version::count())->toBe(2);
});

it('does not recurse forever on a self referencing model', function (): void {
    $parent = Category::create(['name' => 'root']);
    $child = $parent->children()->create(['name' => 'child']);
    $child->children()->create(['name' => 'grandchild']);

    $copy = $parent->duplicate();

    expect($copy->children()->count())->toBe(1)
        ->and(Category::count())->toBe(6);
});

it('throws in strict depth mode when the tree is deeper than allowed', function (): void {
    $product = makeProduct();

    $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->depth(1)->strictDepth());
})->throws(MaxDepthExceededException::class);

it('does not throw in strict mode when nothing is left to copy', function (): void {
    $product = Product::create(['name' => 'Bare']);

    $copy = $product->duplicate(fn (DuplicateOptions $o): DuplicateOptions => $o->depth(0)->strictDepth());

    expect($copy->name)->toBe('Bare');
});

it('hands a relation over to a custom handler', function (): void {
    $product = makeProduct();

    $seen = null;

    $copy = $product->duplicate(function (DuplicateOptions $options) use (&$seen): DuplicateOptions {
        return $options->relationUsing('versions', function ($source, $duplicate, $relation) use (&$seen): void {
            $seen = $relation->name;
        });
    });

    expect($seen)->toBe('versions')
        ->and($copy->versions()->count())->toBe(0);
});

it('resolves model class names for the commands', function (): void {
    config()->set('duplicate-toolkit.model_namespaces', ['Workbench\\App\\Models']);

    $manager = app(DuplicateManager::class);

    expect($manager->resolveModelClass('Product'))->toBe(Product::class)
        ->and($manager->resolveModelClass(Product::class))->toBe(Product::class);
});

it('honours duplicateOptions on a model that only uses the trait', function (): void {
    $product = makeProduct();
    $traitOnly = TraitOnlyProduct::findOrFail($product->id);

    $copy = $traitOnly->duplicate();

    expect($copy->sku)->toBeNull()
        ->and($copy->versions()->count())->toBe(0)
        ->and($copy->setting()->count())->toBe(1);
});
