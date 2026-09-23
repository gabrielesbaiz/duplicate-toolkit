<p align="center">
    <img src="art/duplicate-toolkit-logo.png" alt="DuplicateToolkit" width="600">
</p>

# DuplicateToolkit

Duplicate an Eloquent record and everything under it — decide per relation whether to copy it, point at the original, or leave it alone, and get back a map of every key you created.

[![Latest version](https://img.shields.io/packagist/v/gabrielesbaiz/duplicate-toolkit.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/duplicate-toolkit)
[![PHP](https://img.shields.io/packagist/dependency-v/gabrielesbaiz/duplicate-toolkit/php?style=flat-square)](composer.json)
[![Laravel](https://img.shields.io/packagist/dependency-v/gabrielesbaiz/duplicate-toolkit/illuminate%2Fsupport?style=flat-square&label=laravel)](composer.json)
[![Downloads](https://img.shields.io/packagist/dt/gabrielesbaiz/duplicate-toolkit.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/duplicate-toolkit)
[![Stars](https://img.shields.io/github/stars/gabrielesbaiz/duplicate-toolkit?style=flat-square&logo=github)](https://github.com/gabrielesbaiz/duplicate-toolkit/stargazers)
[![Sponsor](https://img.shields.io/github/sponsors/gabrielesbaiz?style=flat-square&label=sponsor&logo=github)](https://github.com/sponsors/gabrielesbaiz)

### 📖 [Read the documentation →](https://gabrielesbaiz.github.io/duplicate-toolkit/)

Every option, a builder that writes your `duplicateOptions()` method, a relation
tree explorer, and a playground where each strategy runs against a real schema.

> [!CAUTION]
> **Upgrading from 1.x?** Read [UPGRADE.md](UPGRADE.md) first. 1.x leaked
> relations between model classes, discovered relations by reading your source
> files and calling every public method, and crashed on `morphToMany`. There is
> an Artisan codemod — `php artisan duplicate-toolkit:upgrade` — that rewrites
> the mechanical changes and reports the two that need a human.

> [!IMPORTANT]
> A ⭐ costs you nothing and helps other developers find this package.
> [Sponsoring](https://github.com/sponsors/gabrielesbaiz) keeps it compatible
> with every new Laravel release.

```php
$copy = $product->duplicate();
```

```php
$result = Duplicate::of($product)
    ->suffix('name', ' - COPY')
    ->referenceRelations('rates')
    ->relation('versions.descriptions', fn ($o) => $o->excludeColumns('cached_html'))
    ->trackProvenance()
    ->execute();

$result->idMap(Version::class);   // [12 => 480, 13 => 481]
$result->count();                 // 161
```

---

## Contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Relation strategies](#relation-strategies)
- [Nested relations](#nested-relations)
- [Attribute overrides](#attribute-overrides)
- [Unique columns](#unique-columns)
- [The result object](#the-result-object)
- [Depth and cycles](#depth-and-cycles)
- [Dry run](#dry-run)
- [Events and callbacks](#events-and-callbacks)
- [Queue](#queue)
- [Artisan commands](#artisan-commands)
- [Configuration](#configuration)
- [Relation discovery](#relation-discovery)
- [Soft deletes, UUIDs, provenance, media](#soft-deletes-uuids-provenance-media)
- [Recipes](#recipes)
- [API reference](#api-reference)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)

## What it does

Eloquent ships `replicate()`. It copies one row's attributes and hands you an
unsaved model. If that is all you need, use it — it is one method call and no
dependency.

This package exists for the second half of the job: the rows *underneath* the
one you copied, and the fact that "duplicate" rarely means "copy everything".

- **Deep duplication** of `hasOne`, `hasMany`, `morphOne`, `morphMany`,
  `belongsToMany` and `morphToMany`, to any depth, with cycle detection.
- **Three strategies per relation** — copy it, reference the originals, skip it —
  configurable from the model, from the call site, or with dot notation several
  levels down.
- **Attribute overrides applied before the insert.** `suffix()`, `replace()`,
  `withAttributes()` — so a name suffix costs no second write and does not fire
  the events `quietly()` was meant to suppress.
- **An old key → new key map** for every record created, so you can rewire
  grandchildren without matching on names.
- **Relation discovery by return-type reflection.** No source parsing, no model
  methods invoked, no cache shared between classes.
- **Interactive Artisan commands** to explore a relation tree, duplicate a record
  with a picker, and generate the options method.
- **Queueable and batchable**, chunked with `lazyById()`, one transaction.
- **A dry run** that performs every write and rolls back, so the plan is exact.
- Soft deletes, UUID/ULID keys, a provenance column, and
  [spatie/laravel-medialibrary](https://github.com/spatie/laravel-medialibrary)
  collections.

Nothing is magic: `DuplicateOptions` is immutable and fully typed, there is no
`__get()`, and the whole package is analysed at PHPStan level max.

Originally based on [neurony/laravel-duplicate](https://github.com/neurony/laravel-duplicate).
Version 2.0 shares no code with it.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require gabrielesbaiz/duplicate-toolkit

php artisan vendor:publish --tag=duplicate-toolkit-config
```

The service provider is auto-discovered. There are no migrations, no tables and
no assets — publishing the config is optional, and the defaults duplicate
correctly untouched.

**[Full installation guide →](https://gabrielesbaiz.github.io/duplicate-toolkit/#/install)**

## Quick start

Add the trait. That is the only required step.

```php
use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasDuplicates;
}
```

```php
$copy = $product->duplicate();
```

Out of the box: child relations are copied, pivoted relations are referenced,
parent relations are left alone, and timestamps and soft-delete columns are
never carried over.

### Declaring what your model should always do

Implement `Duplicatable` and return the options. The interface is the documented
contract; the trait alone is enough for the method to be picked up.

```php
use Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates;
use Gabrielesbaiz\DuplicateToolkit\Contracts\Duplicatable;
use Gabrielesbaiz\DuplicateToolkit\DuplicateOptions;

class Product extends Model implements Duplicatable
{
    use HasDuplicates;

    public function duplicateOptions(): DuplicateOptions
    {
        return DuplicateOptions::make()
            ->excludeColumnsMatching('/_count$/')
            ->uniqueColumns('name')
            ->referenceRelations('rates')
            ->excludeRelations('auditLogs')
            ->quietly();
    }
}
```

> [!NOTE]
> `DuplicateOptions` is **immutable**. Every method returns a new instance, so a
> model-level object can be derived from on each call without leaking state
> between duplications. `$options->excludeColumns('x');` on its own does nothing —
> you must use the return value.

### Three ways in

```php
// 1. The model. Returns the new model.
$copy = $product->duplicate();

// 2. A callback, refining the model's own options for this call only.
$copy = $product->duplicate(fn (DuplicateOptions $o) => $o->suffix('name', ' - COPY'));

// 3. The builder, when you want the result object or queueing.
$result = Duplicate::of($product)
    ->withAttributes(['is_active' => false])
    ->depth(2)
    ->execute();
```

`$product->duplicator()` returns the same builder as `Duplicate::of($product)`,
which is useful when a class of yours already defines `duplicate()`.

## Relation strategies

| Strategy | What happens | Default for |
|---|---|---|
| `Copy` | The related records are duplicated and attached to the copy | `hasOne`, `hasMany`, `morphOne`, `morphMany` |
| `Reference` | The copy is attached to the **existing** related records | `belongsToMany`, `morphToMany` |
| `Skip` | The relation is ignored entirely | `belongsTo`, `morphTo`, `hasOneThrough`, `hasManyThrough` |

```php
DuplicateOptions::make()
    ->copyRelations('versions', 'coverages')   // duplicate the related rows
    ->referenceRelations('tags', 'dealers')    // attach the originals instead
    ->excludeRelations('auditLogs');           // ignore
```

`onlyRelations()` inverts the default — everything not listed is skipped:

```php
DuplicateOptions::make()->onlyRelations('versions', 'coverages');
```

Pivot payload declared with `withPivot()` is carried over in **both** `Copy` and
`Reference` mode; the keys and pivot timestamps the relation manages itself are
not.

Parent relations (`belongsTo`, `morphTo`) are never duplicated. Duplicating a
parent would create a row the copy does not own — if you need the copy to point
somewhere else, set the foreign key with `withAttributes()`.

### Declaring strategies with an attribute

```php
use Gabrielesbaiz\DuplicateToolkit\Attributes\DuplicateRelations;

#[DuplicateRelations(
    copy: ['versions'],
    reference: ['tags'],
    skip: ['auditLogs'],
)]
class Product extends Model
{
    use HasDuplicates;
}
```

Call-site options win over the attribute, which wins over the type default.

## Nested relations

Dot notation configures grandchildren from the root:

```php
DuplicateOptions::make()
    ->relation('versions.descriptions', fn (DuplicateOptions $o) => $o->excludeColumns('cached_html'))
    ->relation('versions.attachments', RelationStrategy::Skip);
```

Each related model's own `duplicateOptions()` still applies — nested options are
merged **on top** of it, so a child keeps its own rules unless you override them.

### Custom handlers

When a relation needs logic the options cannot express:

```php
use Gabrielesbaiz\DuplicateToolkit\Support\DuplicateContext;
use Gabrielesbaiz\DuplicateToolkit\Support\RelationMeta;

DuplicateOptions::make()->relationUsing(
    'coverages',
    function (Model $source, Model $duplicate, RelationMeta $relation, DuplicateContext $context) {
        // $context->idMap(Version::class) is already populated here
    },
);
```

A handler takes the relation over completely — no strategy is applied, and no
`RelationDuplicating` / `RelationDuplicated` event fires for it. Classes
implementing `Contracts\DuplicatesRelation` are accepted too.

## Attribute overrides

All of these are applied **before** the insert. That matters: it is one write
instead of two, and it respects `quietly()` instead of firing a `saved` event
straight after suppressing one.

```php
DuplicateOptions::make()
    ->suffix('name', ' - COPY')
    ->prefix('slug', 'copy-')
    ->replace('version', search: 'v1', replace: 'v2')
    ->withAttributes([
        'is_active'  => false,
        'product_id' => $target->id,
        'published_at' => null,
    ])
    ->mutate('code', fn (mixed $value, Model $duplicate, Model $source) => $value.'-'.$source->getKey());
```

`withAttributes()` accepts closures as values, with the same signature as
`mutate()`. Overrides are applied in the order they were registered.

### Excluding columns

```php
DuplicateOptions::make()
    ->excludeColumns('sku', 'external_ref')
    ->excludeColumnsMatching('/_count$/', '/^cached_/');
```

Excluded columns are left at their database default rather than copied. You
never need to list `created_at`, `updated_at` or `deleted_at` — those are always
excluded.

`/_count$/` is a shipped default in
`duplicate-toolkit.excluded_column_patterns`, because denormalised counters are
almost never worth copying.

## Unique columns

```php
DuplicateOptions::make()->uniqueColumns('name');
// "Widget" -> "Widget (1)" -> "Widget (2)"
```

The next free value is resolved in **one** query — 1.x ran a query per attempt
and could loop. The format comes from `duplicate-toolkit.unique_format`.

Other schemes:

```php
use Gabrielesbaiz\DuplicateToolkit\Enums\UniqueStrategy;

DuplicateOptions::make()
    ->uniqueColumns('name')
    ->uniqueUsing(UniqueStrategy::Uuid);   // NumericSuffix | Uuid | Ulid | Timestamp
```

| Strategy | Result |
|---|---|
| `NumericSuffix` (default) | `Widget (1)` |
| `Uuid` | `Widget (3f2a91c7)` |
| `Ulid` | `Widget (7ZK4M2QX)` |
| `Timestamp` | `Widget (1769812345)` |

## The result object

`duplicate()` returns the new model. When you need more, use
`duplicateWithResult()` or the builder's `execute()`:

```php
$result = $product->duplicateWithResult();

$result->model;                   // the new root model
$result->source;                  // what it was copied from
$result->idMap(Version::class);   // [oldId => newId]
$result->idMap();                 // the whole map, keyed by class name
$result->newKeyFor($version);     // the new key for one source record
$result->count();                 // total records created
$result->count(Version::class);   // per model class
$result->counts();                // ['App\Models\Version' => 12, ...]
$result->durationMs;
$result->dryRun;
$result->toArray();
```

The id map is the reason this exists. If you have ever duplicated a tree and
then rebuilt an old-id → new-id lookup by matching names, this replaces it — and
unlike name matching, it does not silently mis-map when two records share a name.

## Depth and cycles

```php
DuplicateOptions::make()->depth(2);   // two levels of relations
DuplicateOptions::make()->shallow();  // the root model only, same as depth(0)
```

The default is `duplicate-toolkit.max_depth`. A model already visited during the
run is never visited again, so self-referencing trees (`Category → children →
Category`) terminate instead of recursing forever.

By default a tree deeper than the limit is quietly truncated. To find out
instead:

```php
DuplicateOptions::make()->depth(2)->strictDepth();
// throws MaxDepthExceededException if anything was left uncopied
```

Strict mode costs one `exists()` query per copyable relation at the boundary, so
it is opt-in.

## Dry run

Performs every write inside a transaction and rolls it back. The numbers are
real, not estimated:

```php
$plan = Duplicate::of($product)->preview();

$plan->dryRun;    // true
$plan->count();   // 161
$plan->counts();  // ['App\Models\Version' => 12, 'App\Models\Description' => 148, ...]
```

Nothing is persisted, and the `Duplicated` event does not fire.

## Events and callbacks

Two Eloquent model events, registered exactly like the framework's own:

```php
protected static function booted(): void
{
    static::duplicating(function (Product $product) {
        // return false to abort the whole duplication
    });

    static::duplicated(function (Product $product) {
        // after the transaction commits
    });
}
```

Returning `false` from a `duplicating` listener throws
`DuplicateToolkitException` and nothing is written.

Four dispatched events carry richer context:

| Event | When | Carries |
|---|---|---|
| `Events\Duplicating` | Before anything is written | source, resolved options |
| `Events\Duplicated` | After the transaction commits | source, duplicate, full `DuplicateResult` |
| `Events\RelationDuplicating` | Before each relation is handled | relation meta, chosen strategy |
| `Events\RelationDuplicated` | After each relation | relation meta, strategy, record count |

```php
Event::listen(Duplicated::class, function (Duplicated $event) {
    Log::info('Duplicated', $event->result->toArray());
});
```

Per-duplication callbacks, if you do not want a listener:

```php
DuplicateOptions::make()
    ->beforeSave(fn (Model $duplicate, Model $source) => $duplicate->forceFill([...]))
    ->afterSave(fn (Model $duplicate, Model $source) => ...);
```

`beforeSave` runs on every model in the tree, not only the root.

## Queue

```php
Duplicate::of($product)->dispatch();
Duplicate::of($product)->onQueue('duplicates')->onConnection('redis')->dispatch();
```

`Jobs\DuplicateModel` is `ShouldQueue` and `Batchable`, tagged for Horizon, and
guarded by `WithoutOverlapping` keyed on the source model, so the same record is
never duplicated twice concurrently. The connection and queue default to
`duplicate-toolkit.queue`.

> [!WARNING]
> Options are serialised with the job, so closures — `mutate()`, `beforeSave()`,
> `afterSave()`, `relationUsing()` — cannot be passed at dispatch time. Put that
> logic on the model's `duplicateOptions()`, or in a listener on the
> `Duplicated` event.

```php
Bus::batch(
    $products->map(fn ($product) => new DuplicateModel($product))->all()
)->dispatch();
```

## Artisan commands

| Command | Purpose |
|---|---|
| `duplicate-toolkit:relations {model?}` | Show a model's relation tree and what would happen to each relation. |
| `duplicate-toolkit:duplicate {model?} {id?}` | Duplicate a record, interactively or from flags. |
| `duplicate-toolkit:make-options {model}` | Generate a `duplicateOptions()` method from a relation picker. |
| `duplicate-toolkit:upgrade {path=app}` | Rewrite 1.x usages to the 2.0 API. |

### Explore a relation tree

```bash
php artisan duplicate-toolkit:relations "App\Models\Product" --depth=2 --with-counts
```

```
  INFO  Product (products) — 7 relation(s).

+-------------------+---------------+-------------+-----------+------+
| Relation          | Type          | Related     | Strategy  | Rows |
+-------------------+---------------+-------------+-----------+------+
| documents         | HasMany       | Document    | copy      | 4    |
| labels            | MorphToMany   | Label       | reference | 9    |
| notes             | MorphMany     | Note        | copy      | 3    |
| setting           | HasOne        | Setting     | copy      | 1    |
| tags              | BelongsToMany | Tag         | reference | 22   |
| versions          | HasMany       | Version     | copy      | 12   |
|   └─ descriptions | HasMany       | Description | copy      | 148  |
+-------------------+---------------+-------------+-----------+------+

  ⇂ copy duplicates the related records
  ⇂ reference attaches the existing related records
  ⇂ skip leaves the relation alone
```

The **Strategy** column reflects your model's `duplicateOptions()`, so this is
what would actually happen, not the framework default.

| Flag | Effect |
|---|---|
| `--depth=N` | How many levels to walk. Default `1`. |
| `--with-counts` | Count rows in each related table. One query per relation, off by default. |
| `--all` | Include parent and through relations, which are never duplicated. |
| `--json` | Machine readable output. |

Omit the model argument for a searchable picker.

### Duplicate a record

```bash
php artisan duplicate-toolkit:duplicate
```

Pick the model, search for the record, tick the relations to include, choose
copy or reference for each pivoted relation, review the dry-run plan, confirm.
The relation checkboxes are pre-ticked from the model's own options, so pressing
Enter through the prompts reproduces production behaviour exactly.

Fully flag-driven for scripts and CI:

```bash
php artisan duplicate-toolkit:duplicate Product 42 \
  --only=versions,coverages \
  --reference=rates \
  --exclude-column=external_ref \
  --unique=name \
  --suffix='name= - COPIA' \
  --set='is_active=0' \
  --replace='version=v1:v2' \
  --depth=2 \
  --quiet-events \
  --no-interaction
```

| Flag | Effect |
|---|---|
| `--only=a,b` | Copy only these relations; skip the rest. |
| `--reference=a` | Attach the originals instead of copying. |
| `--exclude=a` | Skip the relation. |
| `--exclude-column=c` | Leave a column at its default. |
| `--unique=c` | Keep a column unique. |
| `--set=c=v` | Force an attribute value. |
| `--suffix=c=s` | Append to a column. |
| `--replace=c=search:replace` | Search and replace in a column. |
| `--depth=N` | How deep to follow relations. |
| `--quiet-events` | Save without firing Eloquent model events. |
| `--dry-run` | Show the plan, write nothing. |
| `--queue` | Dispatch instead of running inline. |

### Generate the options method

```bash
php artisan duplicate-toolkit:make-options "App\Models\Product"
```

Runs the same relation picker and writes a typed `duplicateOptions()` into the
model — adding the trait, the interface and the imports if they are missing, and
pre-excluding `*_count` columns. It shows the code and asks before writing.
`--print` dumps it to stdout instead; `--force` replaces an existing method.

### Upgrade from 1.x

```bash
php artisan duplicate-toolkit:upgrade app --dry-run
php artisan duplicate-toolkit:upgrade app
```

See [UPGRADE.md](UPGRADE.md).

## Configuration

`config/duplicate-toolkit.php`:

| Key | Default | What it controls |
|---|---|---|
| `model_namespaces` | `['App\Models', 'App']` | Where the commands look when you pass a short model name. |
| `max_depth` | `3` | How many levels of relations are followed. |
| `chunk_size` | `500` | `lazyById()` chunk size when copying relations. |
| `save_quietly` | `false` | Save duplicates without firing Eloquent events. |
| `unique_strategy` | `NumericSuffix` | How `uniqueColumns()` values are made unique. |
| `unique_format` | `' (%d)'` | Format used by the numeric strategy. |
| `excluded_columns` | `[]` | Columns never copied, globally. |
| `excluded_column_patterns` | `['/_count$/']` | Column patterns never copied, globally. |
| `default_relation_strategy` | `Copy` | Fallback for child relations. |
| `default_pivoted_relation_strategy` | `Reference` | Fallback for pivoted relations. |
| `provenance_column` | `'duplicated_from_id'` | Column written by `trackProvenance()`. |
| `discovery.invoke_untyped` | `false` | Allow discovery to call relation methods with no return type. |
| `discovery.cache` | `true` | Memoise discovered relations per class. |
| `copy_trashed` | `false` | Copy soft-deleted children. |
| `media.enabled` | `true` | Copy medialibrary collections when the package is installed. |
| `queue.connection` | `null` | Connection for `DuplicateModel`. |
| `queue.queue` | `null` | Queue for `DuplicateModel`. |

Every key has a per-duplication equivalent on `DuplicateOptions`, and the
options always win.

**[Configuration builder →](https://gabrielesbaiz.github.io/duplicate-toolkit/#/config)**

## Relation discovery

Relations are found by reflecting on **method return types**:

```php
public function versions(): HasMany   // ✅ discovered
{
    return $this->hasMany(Version::class);
}

public function versions()            // ❌ not discovered by default
{
    return $this->hasMany(Version::class);
}
```

Nothing is invoked to find out, no file is read, and results are memoised per
class name — so discovery is opcache-safe, free of side effects, and cannot leak
one model's relations into another.

If you have untyped relation methods, add the return type, or:

```php
// config/duplicate-toolkit.php
'discovery' => ['invoke_untyped' => true],
```

which lets the inspector call untyped, argument-less public methods to identify
them. It is off by default because it executes your code.

You can use the inspector directly:

```php
use Gabrielesbaiz\DuplicateToolkit\Support\RelationInspector;

$inspector = app(RelationInspector::class);

$inspector->for($product);                 // every relation
$inspector->duplicatableFor($product);     // children and pivoted only
$inspector->get($product, 'versions');     // one RelationMeta
$inspector->tree($product, depth: 2);      // nested, cycle-safe
```

Each entry is a `RelationMeta`: `name`, `parentClass`, `type`, `relatedClass`,
`kind`, plus `shortType()`, `relatedBasename()` and `defaultStrategy()`.

## Soft deletes, UUIDs, provenance, media

```php
DuplicateOptions::make()
    ->withTrashed()            // copy soft-deleted children too
    ->trackProvenance()        // write the source key to duplicated_from_id
    ->trackProvenance('cloned_from')
    ->withMedia(false);        // skip medialibrary collections
```

- **Soft deletes** — `deleted_at` is never copied, so a duplicate of a trashed
  record comes back alive. Trashed children are skipped unless you ask for them.
- **UUID / ULID keys** — handled through Eloquent, including `HasUuids`.
- **Provenance** — written only if the column actually exists on the table, so
  enabling it globally is safe.
- **Media** — requires `spatie/laravel-medialibrary`; silently ignored when the
  package is absent or the model is not a `HasMedia`.

## Recipes

### Rename the copy without a second write

```php
// Instead of: $copy = $model->duplicate(); $copy->update(['name' => ...]);
$copy = $model->duplicate(fn (DuplicateOptions $o) => $o->suffix('name', ' - COPIA'));
```

### Re-parent while duplicating

```php
$copy = $description->duplicate(fn (DuplicateOptions $o) => $o->withAttributes([
    'product_id' => $target->product_id,
    'version_id' => $target->id,
]));
```

### Copy a product but keep the original rates

```php
$result = $product->duplicateWithResult(
    fn (DuplicateOptions $o) => $o->referenceRelations('rates')
);
```

The copied coverages are attached to the same rate rows — no rate is duplicated.

### Rewire grandchildren with the id map

```php
$result = $product->duplicateWithResult();

$versionMap = $result->idMap(Version::class);   // [oldId => newId]

foreach ($oldDescriptions as $description) {
    $description->update([
        'version_id' => $versionMap[$description->version_id],
    ]);
}
```

### Bulk duplicate with search and replace

```php
foreach ($rates as $rate) {
    $rate->duplicate(fn (DuplicateOptions $o) => $o->replace('version', '2025', '2026'));
}
```

### See the cost before paying it

```php
$plan = Duplicate::of($product)->preview();

if ($plan->count() > 5_000) {
    Duplicate::of($product)->onQueue('long')->dispatch();
} else {
    Duplicate::of($product)->execute();
}
```

### Duplicate inside a Nova action

```php
public function handle(ActionFields $fields, Collection $models)
{
    $model = $models->first();

    $result = $model->duplicateWithResult(
        fn (DuplicateOptions $o) => $o->suffix('name', ' - COPIA')
    );

    return Action::visit('/resources/products/'.$result->model->getKey());
}
```

## API reference

### `Concerns\HasDuplicates`

| Method | Returns |
|---|---|
| `duplicate(DuplicateOptions\|Closure\|null)` | `static` — the new model |
| `duplicateWithResult(DuplicateOptions\|Closure\|null)` | `DuplicateResult` |
| `duplicator(DuplicateOptions\|Closure\|null)` | `PendingDuplicate` |
| `duplicateOptions()` | `DuplicateOptions` — override this |
| `duplicatableRelations()` | `array<string, RelationMeta>` |
| `static duplicating($callback)` / `static duplicated($callback)` | `void` |

### `DuplicateOptions`

Columns — `excludeColumns()`, `excludeColumnsMatching()`, `uniqueColumns()`,
`uniqueUsing()`.
Attributes — `withAttributes()`, `suffix()`, `prefix()`, `replace()`, `mutate()`.
Relations — `relation()`, `onlyRelations()`, `copyRelations()`,
`referenceRelations()`, `excludeRelations()`, `excludeRelationColumns()`,
`uniqueRelationColumns()`, `relationUsing()`.
Behaviour — `depth()`, `shallow()`, `strictDepth()`, `quietly()`,
`withTrashed()`, `withMedia()`, `trackProvenance()`, `dryRun()`,
`beforeSave()`, `afterSave()`, `merge()`.

Every one returns a new instance. Typed getters (`getExcludedColumns()`,
`strategyFor()`, `forRelation()`, …) replace the 1.x `__get()`.

### `Duplicate` facade

| Method | Returns |
|---|---|
| `of(Model)` | `PendingDuplicate` |
| `run(Model, ?DuplicateOptions)` | `DuplicateResult` |
| `relations(Model)` / `duplicatableRelations(Model)` | `array<string, RelationMeta>` |
| `tree(Model, int $depth = 1)` | nested array |
| `resolveModelClass(string)` | `class-string<Model>` |

### `PendingDuplicate`

`execute()`, `save()`, `preview()`, `dispatch()`, `onQueue()`,
`onConnection()`, `withOptions()`, `options()`, `model()`, plus a typed
passthrough for every `DuplicateOptions` method.

### Exceptions

| Exception | Thrown when |
|---|---|
| `NotDuplicatableException` | The model does not exist in the database yet. |
| `MaxDepthExceededException` | `strictDepth()` is on and the tree is deeper than the limit. |
| `RelationNotFoundException` | A relation name does not exist on the model. |
| `ModelResolutionException` | A command cannot resolve a model name. |
| `DuplicateToolkitException` | Base class; also thrown when a listener halts the run. |

## Troubleshooting

**A relation is not being duplicated.**
Run `php artisan duplicate-toolkit:relations "App\Models\Product"`. If it is
missing entirely, the method has no return type — see
[Relation discovery](#relation-discovery). If it is listed as `skip` or
`reference`, that is your `duplicateOptions()` or the type default.

**My `duplicateOptions()` is ignored.**
It must be `public function duplicateOptions(): DuplicateOptions`. The 1.x name
`getDuplicateOptions()` is not called any more — run the upgrade codemod.

**`duplicate()` does nothing / behaves oddly.**
Your class probably defines its own `duplicate()` method, which silently shadows
the trait. Rename it, or use `Duplicate::of($model)->execute()`.

**`Call to undefined method … ::duplicate()`**
The trait is missing, or you are calling it on a query builder rather than a
model instance.

**Counters are copied.**
Add `->excludeColumnsMatching('/_count$/')`, or rely on the shipped config
default.

**Duplicating is slow on a big tree.**
Lower `depth()`, `skip` the relations you do not need, raise `chunk_size`, and
move the whole thing to the queue with `->dispatch()`. Use `preview()` first to
see how many rows are actually involved.

## Testing

```bash
composer test        # Pest
composer analyse     # PHPStan, level max
composer format      # Pint
composer rector-dry  # Rector
```

CI runs the suite against PHP 8.3 and 8.4 × Laravel 12 and 13, on both
`prefer-lowest` and `prefer-stable`.

## Documentation

| | |
|---|---|
| [Documentation site](https://gabrielesbaiz.github.io/duplicate-toolkit/) | Everything: install, configure, operate. |
| [Playground](https://gabrielesbaiz.github.io/duplicate-toolkit/#/play) | Every strategy, against a real schema, in your browser. |
| [Configuration builder](https://gabrielesbaiz.github.io/duplicate-toolkit/#/config) | Set what you need; it writes the config file. |
| [UPGRADE.md](UPGRADE.md) | Upgrading from 1.x. Read before you start. |
| [CHANGELOG.md](CHANGELOG.md) | What changed, and when. |

## Contributing

Thank you for considering contributing. The guide is in
[CONTRIBUTING.md](CONTRIBUTING.md).

## Security vulnerabilities

Please review [SECURITY.md](SECURITY.md) for reporting a vulnerability. Please
do not open a public issue.

## Credits

Written and maintained by [Gabriele Sbaiz](https://github.com/gabrielesbaiz).

The 1.x line was a fork of
[neurony/laravel-duplicate](https://github.com/neurony/laravel-duplicate) by
Neurony Solutions, whose design informed this one. This package builds on
Laravel and
[spatie/laravel-package-tools](https://github.com/spatie/laravel-package-tools).

## Support this package

If it is useful to you:

- ⭐ **Star the repo.** Free, thirty seconds, and it is the first signal other developers look at.
- ❤️ **[Become a sponsor](https://github.com/sponsors/gabrielesbaiz).** From $5 a month.
- 🐛 **Open a good issue.** A clear reproduction is worth more than you think.
- 🗣️ **Tell another Laravel developer.** Word of mouth is how packages survive.

[![Sponsor on GitHub](https://img.shields.io/badge/Sponsor-gabrielesbaiz-ff69b4?style=for-the-badge&logo=github-sponsors)](https://github.com/sponsors/gabrielesbaiz)

## Disclaimer

This package is provided **as is**, without warranty of any kind, express or
implied, including but not limited to the warranties of merchantability,
fitness for a particular purpose, title and non-infringement. To the fullest
extent permitted by applicable law, in no event shall the authors, copyright
holders or contributors be liable for any claim, damages or other liability —
whether in an action of contract, tort or otherwise — arising from, out of or in
connection with this package or its use, including without limitation any
direct, indirect, incidental, special, exemplary, consequential or punitive
damages, loss of data, loss of profits, business interruption, or corruption of
records.

This package writes to your database. It creates rows, copies relations and,
when you ask it to, attaches existing records to new ones. Whoever deploys it is
responsible for deciding whether the result is correct for their schema. That
responsibility includes, and is not limited to, reviewing what each relation
strategy will do before enabling it, running `preview()` or `--dry-run` against
production-shaped data, keeping backups, understanding that duplicated rows may
trigger observers, listeners, queued jobs and search indexing unless
`quietly()` is used, and reading the code yourself before pointing it at data
you cannot afford to lose. Nothing here constitutes legal or compliance advice.

Use of this package is entirely at your own risk.

## License

MIT. See [LICENSE.md](LICENSE.md). The MIT licence's warranty disclaimer and
limitation of liability apply in full, alongside the disclaimer above.
