<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit;

use Gabrielesbaiz\DuplicateToolkit\Commands\DuplicateModelCommand;
use Gabrielesbaiz\DuplicateToolkit\Commands\InspectRelationsCommand;
use Gabrielesbaiz\DuplicateToolkit\Commands\MakeDuplicateOptionsCommand;
use Gabrielesbaiz\DuplicateToolkit\Commands\UpgradeCommand;
use Gabrielesbaiz\DuplicateToolkit\Support\Cast;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DuplicateToolkitServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('duplicate-toolkit')
            ->hasConfigFile()
            ->hasCommands([
                InspectRelationsCommand::class,
                DuplicateModelCommand::class,
                MakeDuplicateOptionsCommand::class,
                UpgradeCommand::class,
            ]);
    }

    /**
     * The class aliases kept for the 1.x names.
     *
     * These are registered here rather than through Composer so that a 1.x
     * application boots far enough to run duplicate-toolkit:upgrade, the very
     * command that removes the need for them. They go away in 3.0.
     *
     * @var array<class-string, string>
     */
    protected array $aliases = [
        DuplicateOptions::class => 'Gabrielesbaiz\\DuplicateToolkit\\Options\\DuplicateOptions',
    ];

    /**
     * Register the package services.
     */
    public function register(): void
    {
        foreach ($this->aliases as $class => $legacy) {
            if (! class_exists($legacy, false)) {
                class_alias($class, $legacy);
            }
        }

        parent::register();
    }

    /**
     * Register the bindings the package resolves out of the container.
     */
    public function packageRegistered(): void
    {
        $this->app->singleton(RelationInspector::class, function (Application $app): RelationInspector {
            $config = $app->make(Config::class);

            return new RelationInspector(
                invokeUntyped: Cast::toBool($config->get('duplicate-toolkit.discovery.invoke_untyped')),
                shouldCache: Cast::toBool($config->get('duplicate-toolkit.discovery.cache'), true),
            );
        });

        $this->app->singleton(Duplicator::class);
        $this->app->singleton(DuplicateManager::class);
        $this->app->alias(DuplicateManager::class, 'duplicate-toolkit');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            RelationInspector::class,
            Duplicator::class,
            DuplicateManager::class,
            'duplicate-toolkit',
        ];
    }
}
