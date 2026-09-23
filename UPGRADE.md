# Upgrading from 1.x to 2.0

Version 2.0 is a rewrite. The concepts are the same; the names and the entry points changed.

## Requirements

| | 1.x | 2.0 |
| --- | --- | --- |
| PHP | 8.0+ | **8.3+** |
| Laravel | 10, 11, 12 | **12, 13** |

## Run the codemod

```bash
composer require gabrielesbaiz/duplicate-toolkit:^2.0
php artisan duplicate-toolkit:upgrade app --dry-run
php artisan duplicate-toolkit:upgrade app
```

It rewrites the mechanical changes and **reports** the two cases that need a human decision (see below).

## What changed

| 1.x | 2.0 |
| --- | --- |
| `Gabrielesbaiz\DuplicateToolkit\Traits\HasDuplicates` | `Gabrielesbaiz\DuplicateToolkit\Concerns\HasDuplicates` |
| `Gabrielesbaiz\DuplicateToolkit\Options\DuplicateOptions` | `Gabrielesbaiz\DuplicateToolkit\DuplicateOptions` |
| `abstract getDuplicateOptions(): DuplicateOptions` | `duplicateOptions(): DuplicateOptions` — optional, defaulted by the trait |
| `DuplicateOptions::instance()` | `DuplicateOptions::make()` (`instance()` still works) |
| `$model->saveAsDuplicate()` | `$model->duplicate()` |
| `->disableDeepDuplication()` | `->shallow()` (or `->depth(0)`) |
| `->saveQuietly()` | `->quietly()` |
| `Helpers\RelationHelper` | `Support\RelationInspector` (resolve it from the container) |

`excludeColumns()`, `uniqueColumns()`, `excludeRelations()`, `excludeRelationColumns()` and `uniqueRelationColumns()` keep their names and behaviour.

### The options object is now immutable

Every method returns a **new** instance. Chained calls are unaffected, but this no longer works:

```php
$options = DuplicateOptions::make();
$options->excludeColumns('name');   // discarded
```

```php
$options = DuplicateOptions::make()->excludeColumns('name');   // correct
```

### No more magic property access

`$options->excludedColumns` is gone. Use the typed getters: `getExcludedColumns()`, `getUniqueColumns()`, `strategyFor()`, and so on.

### Relations are discovered from return types

1.x read the model's source file and guessed. 2.0 reflects on method return types, which is faster, opcache safe, and never invokes your methods. **Relation methods must declare a return type.** If some of yours do not, either add it (recommended) or set:

```php
// config/duplicate-toolkit.php
'discovery' => ['invoke_untyped' => true],
```

### Pivoted relations are referenced by default

In 1.x `belongsToMany`/`morphToMany` relations were re-attached to the same related records. That is still the default, now named explicitly:

```php
DuplicateOptions::make()->referenceRelations('tags');   // 1.x behaviour, explicit
DuplicateOptions::make()->copyRelations('tags');        // duplicate the related records too
```

## Two things the codemod will not rewrite

### 1. A model that already defines `duplicate()`

The 2.0 entry point is `duplicate()`. A method of the same name on your class silently shadows the trait — no error, just the wrong behaviour. Rename yours, or route through the facade:

```php
Duplicate::of($model)->execute();
```

### 2. `saveAsDuplicate()` followed by `update()`

This pattern is the main reason 2.0 exists:

```php
// 1.x — two writes, and the update fires events that saveQuietly() meant to avoid
$copy = $model->saveAsDuplicate();
$copy->update(['name' => $copy->name.' - COPIA']);
```

```php
// 2.0 — one write, applied before the insert
$copy = $model->duplicate(fn (DuplicateOptions $o) => $o->suffix('name', ' - COPIA'));
```

The same applies to search-and-replace and to re-parenting:

```php
$copy = $model->duplicate(fn (DuplicateOptions $o) => $o
    ->replace('version', 'v1', 'v2')
    ->withAttributes(['product_id' => $target->product_id]));
```

## Worth adopting after the upgrade

### Stop matching records by name

If you rebuilt an old-id → new-id map after duplicating, use the result instead:

```php
$result = $product->duplicateWithResult();

foreach ($result->idMap(Version::class) as $oldId => $newId) {
    // ...
}
```

### Drop hand-listed aggregate columns

```php
->excludeColumns('a_count', 'b_count', 'c_count')
```

becomes

```php
->excludeColumnsMatching('/_count$/')
```

which is also the shipped default in `duplicate-toolkit.excluded_column_patterns`.

### Preview before committing

```php
$plan = Duplicate::of($product)->preview();   // writes, then rolls back
$plan->counts();
```

### Inspect what a model would duplicate

```bash
php artisan duplicate-toolkit:relations "App\Models\Product" --depth=2 --with-counts
```
