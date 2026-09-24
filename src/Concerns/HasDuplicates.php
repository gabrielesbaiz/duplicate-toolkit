<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Concerns;

use Closure;
use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Duplicator;
use Gabrielesbaiz\DuplicateToolkit\PendingDuplicate;
use Gabrielesbaiz\DuplicateToolkit\Results\DuplicateResult;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\QueuedClosure;

/**
 * Adds duplication to an Eloquent model.
 *
 * The model should also implement the Duplicatable contract. This trait
 * already supplies the default duplicateOptions() implementation it asks for.
 *
 * @phpstan-require-extends Model
 */
trait HasDuplicates
{
    /**
     * Get the options describing how this model should be duplicated.
     */
    public function duplicateOptions(): DuplicateOptions
    {
        return DuplicateOptions::make();
    }

    /**
     * Duplicate this record and return the new model.
     *
     * A callback may be given to refine the options for this call alone:
     *
     *   $product->duplicate(fn (DuplicateOptions $o) => $o->suffix('name', ' - COPY'));
     *
     * @param  DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions|null  $options
     */
    public function duplicate(DuplicateOptions|Closure|null $options = null): static
    {
        /** @var static $model */
        $model = $this->duplicator($options)->execute()->model;

        return $model;
    }

    /**
     * Duplicate this record and return the full result of the run.
     *
     * @param  DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions|null  $options
     * @return DuplicateResult<static>
     */
    public function duplicateWithResult(DuplicateOptions|Closure|null $options = null): DuplicateResult
    {
        /** @var DuplicateResult<static> $result */
        $result = $this->duplicator($options)->execute();

        return $result;
    }

    /**
     * Begin fluently configuring a duplication of this record.
     *
     * @param  DuplicateOptions|Closure(DuplicateOptions): DuplicateOptions|null  $options
     * @return PendingDuplicate<static>
     */
    public function duplicator(DuplicateOptions|Closure|null $options = null): PendingDuplicate
    {
        /** @var PendingDuplicate<static> $pending */
        $pending = new PendingDuplicate($this, app(Duplicator::class));

        if ($options !== null) {
            $pending->withOptions($options);
        }

        return $pending;
    }

    /**
     * Get the duplicatable relations of this model, keyed by relation name.
     *
     * @return array<string, RelationMeta>
     */
    public function duplicatableRelations(): array
    {
        return app(RelationInspector::class)->duplicatableFor($this);
    }

    /**
     * Register a "duplicating" model event listener.
     *
     * Returning false from the listener aborts the duplication.
     *
     * @param  QueuedClosure|(callable(static): mixed)|array{0: object|string, 1: string}|class-string  $callback
     */
    public static function duplicating(QueuedClosure|callable|array|string $callback): void
    {
        static::registerModelEvent('duplicating', $callback);
    }

    /**
     * Register a "duplicated" model event listener.
     *
     * @param  QueuedClosure|(callable(static): mixed)|array{0: object|string, 1: string}|class-string  $callback
     */
    public static function duplicated(QueuedClosure|callable|array|string $callback): void
    {
        static::registerModelEvent('duplicated', $callback);
    }

    /**
     * Fire one of the custom duplication model events.
     *
     * @internal
     */
    public function fireDuplicateEvent(string $event, bool $halt = true): mixed
    {
        return $this->fireModelEvent($event, $halt);
    }
}
