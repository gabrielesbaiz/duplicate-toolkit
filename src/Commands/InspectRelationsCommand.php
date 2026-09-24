<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Commands;

use Gabrielesbaiz\DuplicateToolkit\Commands\Concerns\ResolvesModels;
use Gabrielesbaiz\DuplicateToolkit\Duplicator;
use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Support\Cast;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class InspectRelationsCommand extends Command
{
    use ResolvesModels;

    /**
     * Whether one of the printed relations belongs to the media library.
     */
    protected bool $sawMediaRelation = false;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'duplicate-toolkit:relations
                            {model? : The model class, fully qualified or short (e.g. Product)}
                            {--depth=1 : How many levels of nested relations to display}
                            {--with-counts : Count the related records (one query per relation)}
                            {--all : Include parent and through relations, which are never duplicated}
                            {--json : Output machine readable JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List the relations of a model and how each would be duplicated';

    /**
     * Execute the console command.
     */
    public function handle(RelationInspector $inspector): int
    {
        $argument = $this->argument('model');

        $class = is_string($argument)
            ? $this->resolveModelClass($argument)
            : $this->askForModel(duplicatableOnly: false);

        $model = new $class;
        $depth = max(0, Cast::toInt($this->option('depth'), 1));

        $rows = $this->rowsFor($inspector, $model, $depth, '');

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->warn(sprintf('No relations found on [%s].', $class));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s (%s) — %d relation(s)',
            class_basename($class),
            $model->getTable(),
            count($rows),
        ));

        $headers = ['Relation', 'Type', 'Related', 'Strategy'];

        if ($this->option('with-counts')) {
            $headers[] = 'Rows';
        }

        $this->table($headers, array_map(
            fn (array $row): array => $this->option('with-counts')
                ? [$row['label'], $row['type'], $row['related'], $row['strategy'], $row['rows'] ?? '-']
                : [$row['label'], $row['type'], $row['related'], $row['strategy']],
            $rows,
        ));

        $this->newLine();
        $bullets = [
            '<fg=green>copy</> duplicates the related records',
            '<fg=yellow>reference</> attaches the existing related records',
            '<fg=gray>skip</> leaves the relation alone',
        ];

        if ($this->sawMediaRelation) {
            $bullets[] = 'media collections are copied by the media library pass, not by their relation';
        }

        $this->components->bulletList($bullets);

        return self::SUCCESS;
    }

    /**
     * Build the table rows for a model and, recursively, its relations.
     *
     * @param  array<int, class-string<Model>>  $seen
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFor(RelationInspector $inspector, Model $model, int $depth, string $prefix, array $seen = []): array
    {
        $seen[] = $model::class;
        $rows = [];

        $relations = $this->option('all')
            ? $inspector->for($model)
            : $inspector->duplicatableFor($model);

        foreach ($relations as $name => $meta) {
            $strategy = $this->strategyFor($model, $meta);

            $row = [
                'relation' => $prefix === '' ? $name : $prefix.'.'.$name,
                'label' => $prefix === '' ? $name : str_repeat('  ', substr_count($prefix, '.') + 1).'└─ '.$name,
                'type' => $meta->shortType(),
                'related' => $meta->relatedBasename(),
                'strategy' => $strategy->value,
            ];

            if ($this->option('with-counts')) {
                $row['rows'] = $this->countFor($meta);
            }

            $rows[] = $row;

            if (
                $depth > 0
                && $meta->relatedClass !== null
                && $strategy === RelationStrategy::Copy
                && ! in_array($meta->relatedClass, $seen, true)
            ) {
                $rows = [...$rows, ...$this->rowsFor(
                    $inspector,
                    new $meta->relatedClass,
                    $depth - 1,
                    $prefix === '' ? $name : $prefix.'.'.$name,
                    $seen,
                )];
            }
        }

        return $rows;
    }

    /**
     * Get the strategy a relation would use without any further input.
     */
    protected function strategyFor(Model $model, RelationMeta $meta): RelationStrategy
    {
        $declared = app(Duplicator::class)
            ->modelOptionsFor($model)
            ->strategyFor($meta->name);

        if ($declared instanceof RelationStrategy) {
            return $declared;
        }

        if (app(RelationInspector::class)->isMediaLibraryRelation($meta)) {
            $this->sawMediaRelation = true;

            return RelationStrategy::Skip;
        }

        return $meta->defaultStrategy();
    }

    /**
     * Count the rows in the related table.
     *
     * A whole-table count is cheap and enough to gauge the size of a
     * duplication before anyone commits to running it.
     */
    protected function countFor(RelationMeta $meta): string
    {
        if ($meta->relatedClass === null) {
            return '-';
        }

        try {
            return (string) (new $meta->relatedClass)->newQuery()->count();
        } catch (Throwable) {
            return '-';
        }
    }
}
