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
