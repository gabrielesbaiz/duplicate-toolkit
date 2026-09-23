<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Commands;

use Gabrielesbaiz\DuplicateToolkit\Support\Cast;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

use Symfony\Component\Finder\Finder;

/**
 * Rewrite 1.x usages of the package to the 2.0 API.
 *
 * Anything needing human judgement is reported rather than rewritten.
 */
class UpgradeCommand extends Command
{
    protected $signature = 'duplicate-toolkit:upgrade
                            {path=app : Directory to scan}
                            {--dry-run : Report the changes without writing them}';

    protected $description = 'Upgrade duplicate-toolkit 1.x usages to the 2.0 API';

    /**
     * Straight search and replace rewrites.
     *
     * @var array<string, string>
     */
    protected array $replacements = [
        'Gabrielesbaiz\\DuplicateToolkit\\Traits\\HasDuplicates' => 'Gabrielesbaiz\\DuplicateToolkit\\Concerns\\HasDuplicates',
        'Gabrielesbaiz\\DuplicateToolkit\\Options\\DuplicateOptions' => 'Gabrielesbaiz\\DuplicateToolkit\\DuplicateOptions',
        'function getDuplicateOptions(' => 'function duplicateOptions(',
        'DuplicateOptions::instance()' => 'DuplicateOptions::make()',
        '->disableDeepDuplication()' => '->shallow()',
        '->saveQuietly()' => '->quietly()',
        '->saveAsDuplicate()' => '->duplicate()',
    ];

    public function handle(): int
    {
        $path = base_path(Cast::toString($this->argument('path'), 'app'));

        if (! is_dir($path)) {
            $this->components->error(sprintf('[%s] is not a directory.', $path));

            return self::FAILURE;
        }

        $changed = [];
        $warnings = [];

        foreach (Finder::create()->files()->name('*.php')->in($path) as $file) {
            $realPath = $file->getRealPath();

            if ($realPath === false) {
                continue;
            }

            $contents = file_get_contents($realPath);

            if ($contents === false) {
                continue;
            }

            $original = $contents;

            if (! str_contains($original, 'DuplicateToolkit') && ! str_contains($original, 'saveAsDuplicate')) {
                continue;
            }

            $updated = $original;

            foreach ($this->replacements as $search => $replace) {
                $updated = str_replace($search, $replace, $updated);
            }

            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $realPath);

            $warnings = [...$warnings, ...$this->inspect($relative, $original)];

            if ($updated === $original) {
                continue;
            }

            $changed[$relative] = $updated;
        }

        $this->report($changed, $warnings);

        if ($changed === [] || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        if ($this->shouldPrompt() && ! confirm(sprintf('Rewrite %d file(s)?', count($changed)), default: true)) {
            return self::SUCCESS;
        }

        foreach ($changed as $relative => $contents) {
            file_put_contents(base_path($relative), $contents);
        }

        $this->components->info(sprintf('Rewrote %d file(s).', count($changed)));

        return self::SUCCESS;
    }

    /**
     * Whether the command may prompt. Honours both the Symfony interactivity
     * flag and an explicit --no-interaction.
     */
    protected function shouldPrompt(): bool
    {
        return $this->input->isInteractive() && $this->option('no-interaction') !== true;
    }

    /**
     * Cases the codemod deliberately refuses to touch.
     *
     * @return array<int, string>
     */
    protected function inspect(string $relative, string $contents): array
    {
        $warnings = [];

        if (
            preg_match('/function\s+duplicate\s*\(/', $contents) === 1
            && str_contains($contents, 'HasDuplicates')
        ) {
            $warnings[] = sprintf(
                '%s defines its own duplicate() method, which would shadow the trait. Rename it or use Duplicate::of($model).',
                $relative,
            );
        }

        if (preg_match('/saveAsDuplicate\(\)[^;]*;\s*\n\s*\$\w+->update\(/', $contents) === 1) {
            $warnings[] = sprintf(
                '%s follows saveAsDuplicate() with an update(). Fold those attributes into ->withAttributes() / ->suffix() so they are applied before the insert.',
                $relative,
            );
        }

        if (preg_match('/excludeRelationColumns|uniqueRelationColumns/', $contents) === 1) {
            $warnings[] = sprintf(
                '%s uses excludeRelationColumns()/uniqueRelationColumns(). These still work, but ->relation(\'name\', fn ($o) => ...) is clearer.',
                $relative,
            );
        }

        return $warnings;
    }

    /**
     * @param  array<string, string>  $changed
     * @param  array<int, string>  $warnings
     */
    protected function report(array $changed, array $warnings): void
    {
        $this->newLine();

        if ($changed === []) {
            $this->components->info('No 1.x usages found.');
        } else {
            $this->components->info(sprintf('%d file(s) to rewrite:', count($changed)));

            foreach (array_keys($changed) as $relative) {
                $this->components->twoColumnDetail($relative, '<fg=green>rewrite</>');
            }
        }

        if ($warnings === []) {
            return;
        }

        $this->newLine();
        $this->components->warn('Needs a human decision:');
        $this->components->bulletList($warnings);
    }
}
