<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Commands;

use Gabrielesbaiz\DuplicateToolkit\Commands\Concerns\ResolvesModels;
use Gabrielesbaiz\DuplicateToolkit\DuplicateManager;
use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Duplicator;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Results\DuplicateResult;
use Gabrielesbaiz\DuplicateToolkit\Support\Cast;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

use Throwable;

class DuplicateModelCommand extends Command
{
    use ResolvesModels;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'duplicate-toolkit:duplicate
                            {model? : The model class, fully qualified or short (e.g. Product)}
                            {id? : Primary key of the record to duplicate}
                            {--only=* : Relations to copy; every other relation is skipped}
                            {--reference=* : Relations to attach instead of copying}
                            {--exclude=* : Relations to skip}
                            {--exclude-column=* : Columns to leave at their default value}
                            {--unique=* : Columns that must stay unique}
                            {--set=* : Attribute overrides as column=value}
                            {--suffix=* : Append a string to a column, as column=suffix}
                            {--replace=* : Search and replace in a column, as column=search:replace}
                            {--depth= : How deep to follow relations}
                            {--quiet-events : Save without firing Eloquent model events}
                            {--dry-run : Show what would be created without keeping it}
                            {--queue : Dispatch the duplication to the queue instead}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Duplicate an Eloquent record, choosing interactively which relations to include';

    /**
     * Execute the console command.
     */
    public function handle(DuplicateManager $manager, RelationInspector $inspector): int
    {
        $argument = $this->argument('model');

        $class = is_string($argument) ? $this->resolveModelClass($argument) : $this->askForModel();

        $model = $this->resolveRecord($class);

        if (! $model instanceof Model) {
            return self::FAILURE;
        }

        $options = $this->buildOptions($model, $inspector);

        if ($this->option('queue')) {
            $manager->of($model)->withOptions($options)->dispatch();

            $this->components->info(sprintf(
                'Duplication of [%s #%s] dispatched to the queue.',
                class_basename($class),
                Cast::toString($model->getKey()),
            ));

            return self::SUCCESS;
        }

        if (! $this->preview($manager, $model, $options)) {
            return self::SUCCESS;
        }

        try {
            $result = $manager->of($model)->withOptions($options)->execute();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderResult($result, committed: true);

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
     * Resolve the record that should be duplicated.
     */
    protected function resolveRecord(string $class): ?Model
    {
        /** @var Model $instance */
        $instance = new $class;

        $id = $this->argument('id');

        if ($id !== null) {
            $model = $instance->newQuery()->find($id);

            if (! $model instanceof Model) {
                $this->components->error(sprintf('No [%s] found with key [%s].', class_basename($class), Cast::toString($id)));

                return null;
            }

            return $model;
        }

        if (! $this->shouldPrompt()) {
            $this->components->error('A record id is required when running non-interactively.');

            return null;
        }

        $label = $this->labelColumn($instance);

        $key = search(
            label: sprintf('Which %s do you want to duplicate?', class_basename($class)),
            options: function (string $value) use ($instance, $label): array {
                $query = $instance->newQuery()->limit(25);

                if ($value !== '' && $label !== null) {
                    $query->where($label, 'like', '%'.$value.'%');
                } elseif ($value !== '') {
                    $query->whereKey($value);
                }

                $options = [];

                foreach ($query->get() as $record) {
                    $key = Cast::toString($record->getKey());
                    $options[$key] = $label === null
                        ? '#'.$key
                        : sprintf('#%s — %s', $key, Cast::toString($record->getAttribute($label)));
                }

                return $options;
            },
            scroll: 15,
        );

        return $instance->newQuery()->find($key);
    }

    /**
     * Get the column best suited to naming a record in a prompt.
     */
    protected function labelColumn(Model $model): ?string
    {
        foreach (['name', 'title', 'label', 'version', 'code', 'slug'] as $column) {
            if ($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Build the duplication options from the command input.
     */
    protected function buildOptions(Model $model, RelationInspector $inspector): DuplicateOptions
    {
        $options = DuplicateOptions::make();

        $options = $this->applyRelationOptions($model, $inspector, $options);
        $options = $this->applyColumnOptions($options);

        $depth = $this->option('depth');

        if (is_string($depth) && $depth !== '') {
            $options = $options->depth(Cast::toInt($depth));
        }

        if ($this->option('quiet-events')) {
            return $options->quietly();
        }

        return $options;
    }

    /**
     * Apply the relation choices, prompting for them when none were given.
     */
    protected function applyRelationOptions(Model $model, RelationInspector $inspector, DuplicateOptions $options): DuplicateOptions
    {
        $only = Cast::toStringList($this->option('only'));
        $reference = Cast::toStringList($this->option('reference'));
        $exclude = Cast::toStringList($this->option('exclude'));

        $explicit = $only !== [] || $reference !== [] || $exclude !== [];

        if ($explicit || ! $this->shouldPrompt()) {
            if ($only !== []) {
                $options = $options->onlyRelations($only);
            }

            if ($reference !== []) {
                $options = $options->referenceRelations($reference);
            }

            if ($exclude !== []) {
                return $options->excludeRelations($exclude);
            }

            return $options;
        }

        $relations = $inspector->duplicatableFor($model);

        if ($relations === []) {
            return $options;
        }

        $choices = [];
        $default = [];

        foreach ($relations as $name => $meta) {
            $choices[$name] = sprintf('%s  (%s → %s)', $name, $meta->shortType(), $meta->relatedBasename());

            if ($this->currentStrategy($model, $meta) !== RelationStrategy::Skip) {
                $default[] = $name;
            }
        }

        /** @var array<int, string> $selected */
        $selected = multiselect(
            label: 'Which relations should be included?',
            options: $choices,
            default: $default,
            hint: 'Unselected relations are skipped entirely.',
            scroll: 15,
        );

        foreach ($relations as $name => $meta) {
            if (! in_array($name, $selected, true)) {
                $options = $options->excludeRelations($name);

                continue;
            }

            if (! $meta->kind->isPivoted()) {
                $options = $options->copyRelations($name);

                continue;
            }

            /** @var string $strategy */
            $strategy = select(
                label: sprintf('How should [%s] be handled?', $name),
                options: [
                    RelationStrategy::Reference->value => 'Reference — attach the existing '.$meta->relatedBasename().' records',
                    RelationStrategy::Copy->value => 'Copy — duplicate the '.$meta->relatedBasename().' records too',
                ],
                default: $this->currentStrategy($model, $meta)->value,
            );

            $options = $options->relation($name, RelationStrategy::from($strategy));
        }

        return $options;
    }

    /**
     * Get the strategy a relation would use without any further input.
     */
    protected function currentStrategy(Model $model, RelationMeta $meta): RelationStrategy
    {
        return app(Duplicator::class)
            ->modelOptionsFor($model)
            ->strategyFor($meta->name) ?? $meta->defaultStrategy();
    }

    /**
     * Apply the column options given on the command line.
     */
    protected function applyColumnOptions(DuplicateOptions $options): DuplicateOptions
    {
        $excluded = Cast::toStringList($this->option('exclude-column'));

        if ($excluded !== []) {
            $options = $options->excludeColumns($excluded);
        }

        $unique = Cast::toStringList($this->option('unique'));

        if ($unique !== []) {
            $options = $options->uniqueColumns($unique);
        }

        foreach (Cast::toStringList($this->option('set')) as $pair) {
            [$column, $value] = $this->splitPair($pair);

            if ($column !== null) {
                $options = $options->withAttributes([$column => $value]);
            }
        }

        foreach (Cast::toStringList($this->option('suffix')) as $pair) {
            [$column, $value] = $this->splitPair($pair);

            if ($column !== null) {
                $options = $options->suffix($column, $value);
            }
        }

        foreach (Cast::toStringList($this->option('replace')) as $pair) {
            [$column, $value] = $this->splitPair($pair);

            if ($column === null || ! str_contains($value, ':')) {
                continue;
            }

            $options = $options->replace($column, Str::before($value, ':'), Str::after($value, ':'));
        }

        return $options;
    }

    /**
     * Split a "column=value" argument into its two halves.
     *
     * @return array{0: string|null, 1: string}
     */
    protected function splitPair(string $pair): array
    {
        if (! str_contains($pair, '=')) {
            $this->components->warn(sprintf('Ignoring [%s]: expected the form column=value.', $pair));

            return [null, ''];
        }

        return [Str::before($pair, '='), Str::after($pair, '=')];
    }

    /**
     * Run a dry run, show the plan, and ask whether to commit it.
     */
    protected function preview(DuplicateManager $manager, Model $model, DuplicateOptions $options): bool
    {
        try {
            $plan = $manager->of($model)->withOptions($options)->preview();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return false;
        }

        $this->renderResult($plan, committed: false);

        if ($this->option('dry-run')) {
            return false;
        }

        if (! $this->shouldPrompt()) {
            return true;
        }

        return confirm(
            label: sprintf('Create %d record(s)?', $plan->count()),
            default: true,
        );
    }

    /**
     * Render the outcome of a duplication run.
     *
     * @param  DuplicateResult<covariant Model>  $result
     */
    protected function renderResult(DuplicateResult $result, bool $committed): void
    {
        $this->newLine();
        $this->components->info($committed
            ? sprintf('Created %d record(s) in %.0fms.', $result->count(), $result->durationMs)
            : sprintf('Plan: %d record(s) would be created.', $result->count()));

        $rows = [];

        foreach ($result->counts() as $class => $count) {
            $rows[] = [class_basename($class), (string) $count];
        }

        if ($rows !== []) {
            $this->table(['Model', 'Records'], $rows);
        }

        if ($committed) {
            $this->components->twoColumnDetail(
                'New '.class_basename($result->model::class),
                '#'.Cast::toString($result->model->getKey()),
            );
        }
    }
}
