<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Commands;

use Gabrielesbaiz\DuplicateToolkit\Support\Cast;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

use Symfony\Component\Finder\Finder;

/**
 * Rewrites 1.x usages of the package to the 2.0 API.
 *
 * Anything that needs human judgement is reported rather than rewritten.
 */
class UpgradeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'duplicate-toolkit:upgrade
                            {path=app : Directory to scan}
                            {--dry-run : Report the changes without writing them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upgrade duplicate-toolkit 1.x usages to the 2.0 API';

    /**
     * The rewrites that are a straight search and replace.
     *
     * @var array<string, string>
     */
    protected array $replacements = [
        'Gabrielesbaiz\\DuplicateToolkit\\Traits\\HasDuplicates' => 'Gabrielesbaiz\\DuplicateToolkit\\Concerns\\HasDuplicates',
        'Gabrielesbaiz\\DuplicateToolkit\\Options\\DuplicateOptions' => 'Gabrielesbaiz\\DuplicateToolkit\\DuplicateOptions',
        'function getDuplicateOptions(' => 'function duplicateOptions(',
        'DuplicateOptions::instance()' => 'DuplicateOptions::make()',
        '->saveAsDuplicate()' => '->duplicate()',
    ];

    /**
     * The rewrites confined to the body of the options method.
     *
     * Eloquent has a saveQuietly() method of its own, so replacing that name
     * across a whole file would silently rewrite a real call that has nothing
     * to do with this package.
     *
     * @var array<string, string>
     */
    protected array $optionsReplacements = [
        '->disableDeepDuplication()' => '->shallow()',
        '->saveQuietly()' => '->quietly()',
    ];

    /**
     * Execute the console command.
     */
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

            $updated = $this->rewriteOptionsMethod($updated);

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
     * Determine if the command is allowed to prompt for input.
     */
    protected function shouldPrompt(): bool
    {
        return $this->input->isInteractive() && $this->option('no-interaction') !== true;
    }

    /**
     * Apply the options-only rewrites inside the duplicateOptions() body.
     */
    protected function rewriteOptionsMethod(string $contents): string
    {
        $pattern = '/(function\s+(?:get)?[dD]uplicateOptions\s*\([^)]*\)[^{]*\{)(.*?)(\n\s{0,8}\})/s';

        $result = preg_replace_callback($pattern, function (array $matches): string {
            $body = $matches[2];

            foreach ($this->optionsReplacements as $search => $replace) {
                $body = str_replace($search, $replace, $body);
            }

            return $matches[1].$body.$matches[3];
        }, $contents);

        return $result ?? $contents;
    }

    /**
     * Get the warnings for the cases the codemod refuses to touch.
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
     * Report what the codemod would do, or has just done.
     *
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
