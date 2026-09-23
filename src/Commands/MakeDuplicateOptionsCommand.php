<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Commands;

use Gabrielesbaiz\DuplicateToolkit\Commands\Concerns\ResolvesModels;
use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates;
use Gabrielesbaiz\DuplicateToolkit\Contracts\Duplicatable;
use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Support\Cast;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

use ReflectionClass;

/**
 * Generate a duplicateOptions() method on a model from an interactive
 * relation picker.
 */
class MakeDuplicateOptionsCommand extends Command
{
    use ResolvesModels;

    protected $signature = 'duplicate-toolkit:make-options
                            {model? : The model class, fully qualified or short (e.g. Product)}
                            {--print : Print the generated code instead of writing it}
                            {--force : Overwrite an existing duplicateOptions() method}';

    protected $description = 'Generate a duplicateOptions() method for a model';

    public function handle(RelationInspector $inspector): int
    {
        $argument = $this->argument('model');

        $class = is_string($argument)
            ? $this->resolveModelClass($argument)
            : $this->askForModel(duplicatableOnly: false);

        /** @var Model $model */
        $model = new $class;

        $relations = $inspector->duplicatableFor($model);

        $copy = [];
        $reference = [];
        $skip = [];

        if ($relations !== [] && $this->shouldPrompt()) {
            $choices = [];
            $default = [];

            foreach ($relations as $name => $meta) {
                $choices[$name] = sprintf('%s  (%s → %s)', $name, $meta->shortType(), $meta->relatedBasename());

                if ($meta->defaultStrategy() === RelationStrategy::Copy) {
                    $default[] = $name;
                }
            }

            /** @var array<int, string> $selected */
            $selected = multiselect(
                label: 'Which relations should be duplicated?',
                options: $choices,
                default: $default,
                hint: 'Pivoted relations left unselected will be referenced instead of skipped.',
                scroll: 15,
            );

            foreach ($relations as $name => $meta) {
                if (in_array($name, $selected, true)) {
                    $copy[] = $name;
                } elseif ($meta->kind->isPivoted()) {
                    $reference[] = $name;
                } else {
                    $skip[] = $name;
                }
            }
        }

        $code = $this->render($class, $model, $copy, $reference, $skip);

        if ($this->option('print')) {
            $this->newLine();
            $this->line($code);

            return self::SUCCESS;
        }

        return $this->write($class, $code);
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
     * @param  class-string<Model>  $class
     * @param  array<int, string>  $copy
     * @param  array<int, string>  $reference
     * @param  array<int, string>  $skip
     */
    protected function render(string $class, Model $model, array $copy, array $reference, array $skip): string
    {
        $lines = ['        return DuplicateOptions::make()'];

        $excluded = $this->aggregateColumns($model);

        if ($excluded !== []) {
            $lines[] = '            ->excludeColumns('.$this->exportList($excluded).')';
        }

        if ($copy !== []) {
            $lines[] = '            ->copyRelations('.$this->exportList($copy).')';
        }

        if ($reference !== []) {
            $lines[] = '            ->referenceRelations('.$this->exportList($reference).')';
        }

        if ($skip !== []) {
            $lines[] = '            ->excludeRelations('.$this->exportList($skip).')';
        }

        $lines[count($lines) - 1] .= ';';

        return implode(PHP_EOL, [
            '    public function duplicateOptions(): DuplicateOptions',
            '    {',
            ...$lines,
            '    }',
        ]);
    }

    /**
     * Denormalised counter columns are almost never worth copying.
     *
     * @return array<int, string>
     */
    protected function aggregateColumns(Model $model): array
    {
        $columns = Cast::toStringList(
            $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable())
        );

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => str_ends_with($column, '_count'),
        ));
    }

    /**
     * @param  array<int, string>  $values
     */
    protected function exportList(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'".$value."'", $values));
    }

    /**
     * @param  class-string<Model>  $class
     */
    protected function write(string $class, string $code): int
    {
        $file = (new ReflectionClass($class))->getFileName();

        if ($file === false || ! is_writable($file)) {
            $this->components->error(sprintf('Cannot write to the source file of [%s].', $class));

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($file);

        if (str_contains($contents, 'function duplicateOptions(') && ! $this->option('force')) {
            $this->components->warn(sprintf('[%s] already defines duplicateOptions(). Use --force to replace it.', $class));

            return self::FAILURE;
        }

        $updated = $this->ensureImports($contents);
        $updated = $this->ensureTrait($updated, $class);
        $updated = $this->ensureInterface($updated, $class);
        $updated = $this->insertMethod($updated, $code);

        if ($updated === $contents) {
            $this->components->warn('Nothing to change.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($code);
        $this->newLine();

        if ($this->shouldPrompt() && ! confirm(sprintf('Write this into %s?', $file), default: true)) {
            return self::SUCCESS;
        }

        file_put_contents($file, $updated);

        $this->components->info(sprintf('Updated [%s].', $class));

        return self::SUCCESS;
    }

    protected function ensureImports(string $contents): string
    {
        foreach ([Duplicatable::class, HasDuplicates::class, DuplicateOptions::class] as $import) {
            if (str_contains($contents, 'use '.$import.';')) {
                continue;
            }

            $contents = Str::replaceFirst(
                "\nuse ",
                "\nuse ".$import.";\nuse ",
                $contents,
            );
        }

        return $contents;
    }

    /**
     * @param  class-string<Model>  $class
     */
    protected function ensureTrait(string $contents, string $class): string
    {
        if (in_array(HasDuplicates::class, class_uses_recursive($class), true)) {
            return $contents;
        }

        return (string) preg_replace(
            '/(class\s+\w+[^{]*\{\s*\n)/',
            '$1    use HasDuplicates;'.PHP_EOL.PHP_EOL,
            $contents,
            1,
        );
    }

    /**
     * @param  class-string<Model>  $class
     */
    protected function ensureInterface(string $contents, string $class): string
    {
        if (is_a($class, Duplicatable::class, true)) {
            return $contents;
        }

        $short = class_basename($class);

        if (preg_match('/class\s+'.preg_quote($short, '/').'\s+extends\s+[\w\\\\]+\s+implements\s+/', $contents) === 1) {
            return (string) preg_replace(
                '/(class\s+'.preg_quote($short, '/').'\s+extends\s+[\w\\\\]+\s+implements\s+)/',
                '$1Duplicatable, ',
                $contents,
                1,
            );
        }

        return (string) preg_replace(
            '/(class\s+'.preg_quote($short, '/').'\s+extends\s+[\w\\\\]+)/',
            '$1 implements Duplicatable',
            $contents,
            1,
        );
    }

    protected function insertMethod(string $contents, string $code): string
    {
        $position = strrpos($contents, '}');

        if ($position === false) {
            return $contents;
        }

        return rtrim(substr($contents, 0, $position)).PHP_EOL.PHP_EOL.$code.PHP_EOL.'}'.PHP_EOL;
    }
}
