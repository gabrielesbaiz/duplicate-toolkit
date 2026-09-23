<?php

declare(strict_types=1);

use Gabrielesbaiz\DuplicateToolkit\Enums\RelationStrategy;
use Gabrielesbaiz\DuplicateToolkit\Enums\UniqueStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Model Namespaces
    |--------------------------------------------------------------------------
    |
    | Namespaces scanned by the Artisan commands when resolving a short model
    | name such as "Product" into a fully qualified class name.
    |
    */

    'model_namespaces' => [
        'App\\Models',
        'App',
    ],

    /*
    |--------------------------------------------------------------------------
    | Depth
    |--------------------------------------------------------------------------
    |
    | How many levels of relations are followed. 0 duplicates only the root
    | model. Exceeding this limit throws a MaxDepthExceededException.
    |
    */

    'max_depth' => 3,

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Related records are streamed with lazyById() in chunks of this size so
    | that huge relation trees never load entirely into memory.
    |
    */

    'chunk_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | Save Quietly
    |--------------------------------------------------------------------------
    |
    | When true, duplicated records are persisted without firing Eloquent
    | model events. Can be overridden per duplication with ->quietly().
    |
    */

    'save_quietly' => false,

    /*
    |--------------------------------------------------------------------------
    | Unique Columns
    |--------------------------------------------------------------------------
    |
    | Strategy used to make columns declared via ->uniqueColumns() unique, and
    | the format applied by the NumericSuffix strategy.
    |
    */

    'unique_strategy' => UniqueStrategy::NumericSuffix,

    'unique_format' => ' (%d)',

    /*
    |--------------------------------------------------------------------------
    | Globally Excluded Columns
    |--------------------------------------------------------------------------
    |
    | Columns and column patterns never copied onto the duplicate. Timestamps
    | and soft delete columns are always excluded by the engine itself.
    |
    */

    'excluded_columns' => [],

    'excluded_column_patterns' => [
        '/_count$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Relation Strategy
    |--------------------------------------------------------------------------
    |
    | Fallback strategy for relation types without an explicit configuration.
    | Child relations default to Copy, pivoted relations to Reference and
    | parent relations are always referenced.
    |
    */

    'default_relation_strategy' => RelationStrategy::Copy,

    'default_pivoted_relation_strategy' => RelationStrategy::Reference,

    /*
    |--------------------------------------------------------------------------
    | Provenance
    |--------------------------------------------------------------------------
    |
    | When ->trackProvenance() is used, the source model key is written to this
    | column on the duplicate, provided the column exists on the table.
    |
    */

    'provenance_column' => 'duplicated_from_id',

    /*
    |--------------------------------------------------------------------------
    | Relation Discovery
    |--------------------------------------------------------------------------
    |
    | Relations are discovered by reflecting on method return types, which is
    | fast and free of side effects. Enable "invoke_untyped" only if you have
    | legacy relation methods without a return type; those methods will then
    | be invoked during discovery.
    |
    */

    'discovery' => [
        'invoke_untyped' => false,
        'cache' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | Whether trashed related records are copied alongside the duplicate.
    |
    */

    'copy_trashed' => false,

    /*
    |--------------------------------------------------------------------------
    | Media Library
    |--------------------------------------------------------------------------
    |
    | Copy spatie/laravel-medialibrary collections onto the duplicate. Silently
    | ignored when the package is not installed.
    |
    */

    'media' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Connection and queue used by Gabrielesbaiz\DuplicateToolkit\Jobs\DuplicateModel.
    |
    */

    'queue' => [
        'connection' => null,
        'queue' => null,
    ],

];
