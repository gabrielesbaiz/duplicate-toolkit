<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Jobs;

use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;
use Gabrielesbaiz\DuplicateToolkit\Duplicator;
use Gabrielesbaiz\DuplicateToolkit\Support\Cast;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Duplicate a model in the background.
 *
 * The options travel with the job through serialization, so closures given to
 * mutate(), beforeSave() or afterSave() cannot be used here. Declare those on
 * the model's own duplicateOptions() method instead.
 */
class DuplicateModel implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Model $model,
        public ?DuplicateOptions $options = null,
    ) {
        $connection = config('duplicate-toolkit.queue.connection');
        $queue = config('duplicate-toolkit.queue.queue');

        if (is_string($connection)) {
            $this->onConnection($connection);
        }

        if (is_string($queue)) {
            $this->onQueue($queue);
        }
    }

    /**
     * Execute the job.
     */
    public function handle(Duplicator $duplicator): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $duplicator->run($this->model, $this->options);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->lockKey()))->releaseAfter(60)->expireAfter(600),
        ];
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['duplicate-toolkit', $this->lockKey()];
    }

    /**
     * Get the key that prevents two duplications of the same record overlapping.
     */
    protected function lockKey(): string
    {
        return $this->model::class.':'.Cast::toString($this->model->getKey());
    }
}
