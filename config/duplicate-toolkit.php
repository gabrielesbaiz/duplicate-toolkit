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
    | Here you may list the namespaces the Artisan commands scan when they
    | resolve a short model name such as "Product" into a fully qualified
    | class name. They are searched in the order they are given.
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
    | This value controls how many levels of relations are followed. A depth
    | of zero duplicates the root model alone, a depth of one also follows its
    | direct relations, and so on.
    |
    | The default of one is what most applications mean by "duplicate this
    | record". Raise it deliberately, since on a real schema a second level
    | often reaches transactional tables that merely reference the record
    | without belonging to it. A preview will show you before you commit.
    |
    */

    'max_depth' => 1,

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | Related records are streamed in chunks of this size so that even a very
    | large relation tree is never loaded into memory all at once.
    |
    */

    'chunk_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | Save Quietly
    |--------------------------------------------------------------------------
    |
    | When enabled, duplicated records are persisted without firing any of the
    | Eloquent model events. This may be overridden for a single duplication
    | with the "quietly" option.
    |
    */

    'save_quietly' => false,

    /*
    |--------------------------------------------------------------------------
    | Unique Columns
    |--------------------------------------------------------------------------
    |
    | Here you may configure the strategy used to make the value of a column
    | declared as unique unique again, along with the format that the numeric
    | suffix strategy appends to the original value.
    |
    */

    'unique_strategy' => UniqueStrategy::NumericSuffix,

    'unique_format' => ' (%d)',

    /*
    |--------------------------------------------------------------------------
    | Globally Excluded Columns
    |--------------------------------------------------------------------------
    |
    | These columns and column patterns are never copied onto a duplicate. The
    | timestamps and the soft delete column are always excluded by the engine
    | itself, so they do not need to be listed here.
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
    | These strategies apply to any relation that the model itself says
    | nothing about. Child relations are duplicated, pivoted relations are
    | re-attached, and parent relations are always left where they are.
    |
    */

    'default_relation_strategy' => RelationStrategy::Copy,

    'default_pivoted_relation_strategy' => RelationStrategy::Reference,

    /*
    |--------------------------------------------------------------------------
    | Provenance
    |--------------------------------------------------------------------------
    |
    | When provenance tracking is enabled, the key of the source record is
    | written to this column on the duplicate, provided that the column
    | actually exists on the table being written to.
    |
    */

    'provenance_column' => 'duplicated_from_id',

    /*
    |--------------------------------------------------------------------------
    | Relation Discovery
    |--------------------------------------------------------------------------
    |
    | Relations are discovered by reflecting on method return types, which is
    | fast and entirely free of side effects. You should only enable the
    | "invoke_untyped" option if your models still declare relation methods
    | without a return type, since those methods are then called during
    | discovery in order to identify them.
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
    | This value determines whether trashed related records are copied along
    | with the duplicate, or quietly left behind.
    |
    */

    'copy_trashed' => false,

    /*
    |--------------------------------------------------------------------------
    | Media Library
    |--------------------------------------------------------------------------
    |
    | When enabled, the media library collections of the source record are
    | copied onto the duplicate. This option is quietly ignored when the media
    | library package is not installed.
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
    | Here you may configure the connection and the queue that a duplication
    | dispatched to the background is sent to. Leaving them null falls back to
    | the defaults of your application.
    |
    */

    'queue' => [
        'connection' => null,
        'queue' => null,
    ],

];
