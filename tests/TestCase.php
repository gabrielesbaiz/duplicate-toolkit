<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Tests;

use Gabrielesbaiz\DuplicateToolkit\DuplicateToolkitServiceProvider;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        // Relation discovery is memoised per model class, so here we clear it
        // to keep the discovery of one test out of the next.
        $this->app->make(RelationInspector::class)->flush();
    }

    /**
     * Get the package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DuplicateToolkitServiceProvider::class,
        ];
    }

    /**
     * Define the environment setup.
     */
    protected function defineEnvironment($app): void
    {
        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ]);
        });
    }

    /**
     * Define the database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }
}
