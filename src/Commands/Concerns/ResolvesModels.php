<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Commands\Concerns;

use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates;
use Gabrielesbaiz\DuplicateToolkit\DuplicateManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

use function Laravel\Prompts\search;

use SplFileInfo;
use Symfony\Component\Finder\Finder;

trait ResolvesModels
{
    /**
     * Resolve a short or fully qualified model name into a class name.
     *
     * @return class-string<Model>
     */
    protected function resolveModelClass(string $name): string
    {
        return app(DuplicateManager::class)->resolveModelClass($name);
    }

    /**
     * Ask the user to pick a model, filtered to duplicatable ones by default.
     *
     * @return class-string<Model>
     */
    protected function askForModel(bool $duplicatableOnly = true): string
    {
        $models = $this->discoverModels($duplicatableOnly);

        if ($models === []) {
            $this->components->error('No Eloquent models found. Pass a class name explicitly.');

            exit(self::FAILURE);
        }

        /** @var class-string<Model> $choice */
        $choice = search(
            label: 'Which model do you want to work with?',
            options: fn (string $value): array => array_values(array_filter(
                $models,
                static fn (string $model): bool => $value === '' || Str::contains(Str::lower($model), Str::lower($value)),
            )),
            placeholder: 'Start typing a model name...',
            scroll: 15,
        );

        return $choice;
    }

    /**
     * Get every Eloquent model class found under the configured namespaces.
     *
     * @return array<int, class-string<Model>>
     */
    protected function discoverModels(bool $duplicatableOnly = false): array
    {
        /** @var array<int, string> $namespaces */
        $namespaces = (array) config('duplicate-toolkit.model_namespaces', ['App\\Models']);

        $models = [];

        foreach ($this->modelDirectories($namespaces) as $namespace => $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (Finder::create()->files()->name('*.php')->in($directory) as $file) {
                $class = $this->classFromFile($file, $namespace, $directory);

                if ($class === null || ! is_a($class, Model::class, true)) {
                    continue;
                }

                if ($duplicatableOnly && ! in_array(HasDuplicates::class, class_uses_recursive($class), true)) {
                    continue;
                }

                $models[] = $class;
            }
        }

        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }

    /**
     * Get the directory backing each of the given model namespaces.
     *
     * @param  array<int, string>  $namespaces
     * @return array<string, string>
     */
    protected function modelDirectories(array $namespaces): array
    {
        $appNamespace = rtrim($this->getLaravel()->getNamespace(), '\\');
        $directories = [];

        foreach ($namespaces as $namespace) {
            $namespace = rtrim($namespace, '\\');

            if (! Str::startsWith($namespace.'\\', $appNamespace.'\\')) {
                continue;
            }

            $relative = trim(Str::after($namespace, $appNamespace), '\\');

            $directories[$namespace] = rtrim(
                app_path($relative === '' ? '' : str_replace('\\', DIRECTORY_SEPARATOR, $relative)),
                DIRECTORY_SEPARATOR,
            );
        }

        return $directories;
    }

    /**
     * Get the class a discovered file declares, if it declares one at all.
     *
     * @return class-string|null
     */
    protected function classFromFile(SplFileInfo $file, string $namespace, string $directory): ?string
    {
        $relative = Str::of($file->getRealPath() === false ? $file->getPathname() : $file->getRealPath())
            ->after($directory.DIRECTORY_SEPARATOR)
            ->replace(['/', '\\'], '\\')
            ->beforeLast('.php')
            ->toString();

        $class = $namespace.'\\'.$relative;

        return class_exists($class) ? $class : null;
    }
}
