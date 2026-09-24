<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->legacyDir = base_path('legacy');

    if (! is_dir($this->legacyDir)) {
        mkdir($this->legacyDir, 0777, true);
    }
});

afterEach(function (): void {
    foreach (glob($this->legacyDir.'/*.php') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($this->legacyDir);
});

function writeLegacy(string $name, string $contents): string
{
    $path = base_path('legacy/'.$name);
    file_put_contents($path, $contents);

    return $path;
}

it('rewrites the 1.x api', function (): void {
    $path = writeLegacy('LegacyModel.php', <<<'PHP'
    <?php

    use Gabrielesbaiz\DuplicateToolkit\Options\DuplicateOptions;
    use Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates;

    class LegacyModel
    {
        use HasDuplicates;

        public function getDuplicateOptions(): DuplicateOptions
        {
            return DuplicateOptions::instance()
                ->excludeColumns('a')
                ->disableDeepDuplication()
                ->saveQuietly();
        }
    }
    PHP);

    Artisan::call('duplicate-toolkit:upgrade', ['path' => 'legacy', '--no-interaction' => true]);

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain(HasDuplicates::class)
        ->toContain('use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;')
        ->toContain('function duplicateOptions(')
        ->toContain('DuplicateOptions::make()')
        ->toContain('->shallow()')
        ->toContain('->quietly()')
        ->not->toContain('::instance()')
        ->not->toContain('disableDeepDuplication');
});

it('rewrites call sites', function (): void {
    $path = writeLegacy('LegacyAction.php', <<<'PHP'
    <?php

    class LegacyAction
    {
        public function handle($model)
        {
            return $model->saveAsDuplicate();
        }
    }
    PHP);

    Artisan::call('duplicate-toolkit:upgrade', ['path' => 'legacy', '--no-interaction' => true]);

    expect((string) file_get_contents($path))->toContain('$model->duplicate()');
});

it('writes nothing on a dry run', function (): void {
    $path = writeLegacy('LegacyDry.php', <<<'PHP'
    <?php

    use Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates;
    PHP);

    $before = (string) file_get_contents($path);

    Artisan::call('duplicate-toolkit:upgrade', [
        'path' => 'legacy',
        '--dry-run' => true,
        '--no-interaction' => true,
    ]);

    expect((string) file_get_contents($path))->toBe($before);
});

it('reports a duplicate() method that would shadow the trait', function (): void {
    writeLegacy('Shadowed.php', <<<'PHP'
    <?php

    use Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates;

    class Shadowed
    {
        use HasDuplicates;

        public function duplicate()
        {
            return null;
        }
    }
    PHP);

    Artisan::call('duplicate-toolkit:upgrade', ['path' => 'legacy', '--no-interaction' => true]);

    expect(Artisan::output())->toContain('would shadow the trait');
});

it('reports a post-hoc update that should be folded into the options', function (): void {
    writeLegacy('PostUpdate.php', <<<'PHP'
    <?php

    class PostUpdate
    {
        public function handle($model)
        {
            $copy = $model->saveAsDuplicate();
            $copy->update(['name' => 'x']);
        }
    }
    PHP);

    Artisan::call('duplicate-toolkit:upgrade', ['path' => 'legacy', '--no-interaction' => true]);

    expect(Artisan::output())->toContain('withAttributes');
});

it('fails for a missing directory', function (): void {
    $this->artisan('duplicate-toolkit:upgrade', ['path' => 'nope', '--no-interaction' => true])
        ->assertFailed();
});

it('does not rewrite Eloquent saveQuietly outside the options method', function (): void {
    $path = writeLegacy('MixedSaveQuietly.php', <<<'PHP'
    <?php

    use Gabrielesbaiz\DuplicateToolkit\Options\DuplicateOptions;
    use Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates;

    class MixedSaveQuietly
    {
        use HasDuplicates;

        public function getDuplicateOptions(): DuplicateOptions
        {
            return DuplicateOptions::instance()->saveQuietly();
        }

        public function touchSomething($model)
        {
            // Eloquent's own method, which must survive untouched.
            $model->forceFill(['x' => 1])->saveQuietly();
        }
    }
    PHP);

    Artisan::call('duplicate-toolkit:upgrade', ['path' => 'legacy', '--no-interaction' => true]);

    $contents = (string) file_get_contents($path);

    expect($contents)
        ->toContain('DuplicateOptions::make()->quietly()')
        ->toContain("forceFill(['x' => 1])->saveQuietly()");
});
