<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;

it('is immutable', function (): void {
    $base = DuplicateOptions::make();
    $derived = $base->excludeColumns('name');

    expect($base->getExcludedColumns())->toBe([])
        ->and($derived->getExcludedColumns())->toBe(['name'])
        ->and($derived)->not->toBe($base);
});

it('flattens and de-duplicates column arguments', function (): void {
    $options = DuplicateOptions::make()
        ->excludeColumns('a', ['b', 'c'])
        ->excludeColumns('a');

    expect($options->getExcludedColumns())->toBe(['a', 'b', 'c']);
});

it('records relation strategies', function (): void {
    $options = DuplicateOptions::make()
        ->copyRelations('versions')
        ->referenceRelations('tags')
        ->excludeRelations('notes');

    expect($options->strategyFor('versions'))->toBe(RelationStrategy::Copy)
        ->and($options->strategyFor('tags'))->toBe(RelationStrategy::Reference)
        ->and($options->strategyFor('notes'))->toBe(RelationStrategy::Skip)
        ->and($options->strategyFor('setting'))->toBeNull();
});

it('skips everything outside the allow list', function (): void {
    $options = DuplicateOptions::make()->onlyRelations('versions');

    expect($options->strategyFor('versions'))->toBe(RelationStrategy::Copy)
        ->and($options->strategyFor('tags'))->toBe(RelationStrategy::Skip);
});

it('rebases dot notation onto the nested relation', function (): void {
    $options = DuplicateOptions::make()
        ->relation('versions.descriptions', RelationStrategy::Skip);

    $nested = $options->forRelation('versions');

    expect($nested)->not->toBeNull()
        ->and($nested->strategyFor('descriptions'))->toBe(RelationStrategy::Skip);
});

it('resolves nested options declared with a callback', function (): void {
    $options = DuplicateOptions::make()
        ->relation('versions', fn (DuplicateOptions $o): DuplicateOptions => $o->excludeColumns('name'));

    expect($options->forRelation('versions')?->getExcludedColumns())->toBe(['name'])
        ->and($options->strategyFor('versions'))->toBe(RelationStrategy::Copy);
});

it('lets the incoming options win on merge', function (): void {
    $merged = DuplicateOptions::make()->quietly(false)->depth(1)
        ->merge(DuplicateOptions::make()->quietly()->depth(5));

    expect($merged->shouldSaveQuietly())->toBeTrue()
        ->and($merged->getDepth())->toBe(5);
});

it('keeps existing values when the incoming options are silent', function (): void {
    $merged = DuplicateOptions::make()->quietly()->excludeColumns('a')
        ->merge(DuplicateOptions::make()->excludeColumns('b'));

    expect($merged->shouldSaveQuietly())->toBeTrue()
        ->and($merged->getExcludedColumns())->toBe(['a', 'b']);
});

it('translates excludeRelationColumns into nested options', function (): void {
    $options = DuplicateOptions::make()
        ->excludeRelationColumns(['versions' => ['name']]);

    expect($options->forRelation('versions')?->getExcludedColumns())->toBe(['name']);
});

it('clamps a negative depth to zero', function (): void {
    expect(DuplicateOptions::make()->depth(-5)->getDepth())->toBe(0);
});
