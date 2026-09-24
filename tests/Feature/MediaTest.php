<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Workbench\App\Models\Gallery;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * Create a gallery holding a single media item.
 */
function galleryWithMedia(string $name = 'Original'): Gallery
{
    $gallery = Gallery::create(['name' => $name]);

    $gallery->addMediaFromString('the file')
        ->usingFileName('file.txt')
        ->toMediaCollection('files');

    return $gallery->fresh();
}

it('copies each media item once, with its file', function (): void {
    $gallery = galleryWithMedia();

    $copy = $gallery->duplicate();

    expect(Media::count())->toBe(2)
        ->and($copy->getMedia('files'))->toHaveCount(1);

    $copied = $copy->getMedia('files')->first();

    expect($copied)->not->toBeNull()
        ->and($copied->id)->not->toBe($gallery->getMedia('files')->first()->id)
        ->and(Storage::disk('public')->exists($copied->id.'/file.txt'))->toBeTrue();
});

it('creates no media at all when media copying is disabled', function (): void {
    $gallery = galleryWithMedia();

    $copy = $gallery->duplicate(fn ($options) => $options->withMedia(false));

    expect(Media::count())->toBe(1)
        ->and($copy->getMedia('files'))->toHaveCount(0);
});

it('turns the media pass on when the media relation is marked as copied', function (): void {
    config()->set('duplicate-toolkit.media.enabled', false);

    $gallery = galleryWithMedia();

    $copy = $gallery->duplicate(fn ($options) => $options->copyRelations('media'));

    expect(Media::count())->toBe(2)
        ->and($copy->getMedia('files'))->toHaveCount(1);
});

it('turns the media pass off when the media relation is excluded', function (): void {
    $gallery = galleryWithMedia();

    $copy = $gallery->duplicate(fn ($options) => $options->excludeRelations('media'));

    expect(Media::count())->toBe(1)
        ->and($copy->getMedia('files'))->toHaveCount(0);
});

it('lets withMedia win over the strategy named on the relation', function (): void {
    $gallery = galleryWithMedia();

    $copy = $gallery->duplicate(fn ($options) => $options->copyRelations('media')->withMedia(false));

    expect(Media::count())->toBe(1)
        ->and($copy->getMedia('files'))->toHaveCount(0);
});

it('reports the media relation as skipped in the relation tree', function (): void {
    $exitCode = Artisan::call('duplicate-toolkit:relations', ['model' => Gallery::class, '--json' => true]);

    expect($exitCode)->toBe(0);

    $rows = json_decode(Artisan::output(), true);

    $media = collect($rows)->firstWhere('relation', 'media');

    expect($media)->not->toBeNull()
        ->and($media['strategy'])->toBe('skip');
});
