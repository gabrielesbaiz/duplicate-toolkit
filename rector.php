<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/workbench',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ])
    ->withImportNames(importShortClasses: false)
    ->withPhpSets(php83: true)
    ->withSkip([
        // Named arguments are ordered for readability, not alphabetically.
        SortCallLikeNamedArgsRector::class,
        // The upgrade codemod matches 1.x class names that no longer exist.
        StringClassNameToClassConstantRector::class => [
            __DIR__.'/src/Commands/UpgradeCommand.php',
        ],
    ]);
