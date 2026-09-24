# Changelog

All notable changes to `duplicate-toolkit` will be documented in this file.

## 2.0.0

A full rewrite. See [UPGRADE.md](UPGRADE.md) for the migration path and the `duplicate-toolkit:upgrade` codemod.

### Requirements

- PHP 8.3+ (was 8.0+)
- Laravel 12 and 13 (was 10, 11, 12)

### Fixed

- **The package had no service provider.** `DuplicateToolkitServiceProvider` was referenced by the test suite but never existed, and `composer.json` had no `extra.laravel` block, so nothing auto-discovered.
- **Relations leaked between model classes.** `RelationHelper::$relations` was a static accumulator that was never reset, so duplicating a second model in the same request inherited the first model's relations.
- **Relation discovery read model source files** with `SplFileObject` and matched on `stripos`, then invoked every public method to test it. It broke on opcache, eval'd classes and relations declared in traits, and triggered side effects in accessors. Discovery is now return-type reflection.
- `hasManyThrough` was matched during discovery and then silently dropped.
- Pivot attributes were read through `getOtherKey()`, a deprecated alias, and assumed a loaded pivot — a fatal on `morphToMany`.
- Unique column resolution queried an unsaved replica and never excluded the source row, producing wrong suffixes and a possible infinite loop. It now resolves in a single query.
- `saveAsDuplicate()` wrapped everything in `try { … } catch (Exception $e) { throw $e; }`, which caught nothing and missed `Error`.

### Added

- `Duplicate` facade and a fluent `PendingDuplicate` builder: `Duplicate::of($model)->…->execute()`.
- `DuplicateResult` with the **old key → new key map**, per-model counts and timing.
- Three relation strategies — `Copy`, `Reference`, `Skip` — configurable per relation, with dot notation for nested relations.
- Attribute overrides applied before the insert: `suffix()`, `prefix()`, `replace()`, `withAttributes()`, `mutate()`.
- `excludeColumnsMatching()` for pattern-based column exclusion.
- Configurable depth with cycle detection, plus `strictDepth()` to throw instead of truncating.
- `preview()` / `--dry-run`: performs every write inside a transaction and rolls back.
- Event classes `Duplicating`, `Duplicated`, `RelationDuplicating`, `RelationDuplicated`, plus `beforeSave()` / `afterSave()` callbacks.
- Queued, batchable `Jobs\DuplicateModel` with overlap protection.
- Custom per-relation handlers via `relationUsing()` and the `DuplicatesRelation` contract.
- `#[DuplicateRelations]` attribute for declaring strategies on the model class.
- Artisan commands: `duplicate-toolkit:relations`, `duplicate-toolkit:duplicate`, `duplicate-toolkit:make-options`, `duplicate-toolkit:upgrade`.
- Soft delete awareness (`withTrashed()`), UUID/ULID key support, provenance column (`trackProvenance()`), and optional media library copying.
- The media library relation is left to the media pass: it is never walked as an ordinary relation, so a duplicate gets one media row per original, with its file copied, instead of a second row pointing at the original's directory. A strategy declared for that relation — `copyRelations('media')` or `excludeRelations('media')` — turns the media pass on or off, and `withMedia()` wins over both.
- A publishable config file.

### Changed

- `Traits\HasDuplicates` → `Concerns\HasDuplicates`; `Options\DuplicateOptions` → `DuplicateOptions`.
- `saveAsDuplicate()` → `duplicate()`; `getDuplicateOptions()` → `duplicateOptions()`, now optional.
- `disableDeepDuplication()` → `shallow()`; `saveQuietly()` → `quietly()`.
- `DuplicateOptions` is immutable and fully typed — the `__get()` magic accessor is gone, replaced by explicit getters.
- Relations are copied with `lazyById()` chunking and the whole run is one transaction; the `Duplicated` event fires after commit.
- `declare(strict_types=1)` across the codebase.

### Removed

- `Helpers\RelationHelper`, replaced by `Support\RelationInspector`. Its unused predicates (`isDirect()`, `isParent()`, `isChildSingle()`, `isChildMultiple()`) are gone.
- `.php-cs-fixer.php` and `tlint.json`, superseded by Pint.

### Tooling

- PHPStan (larastan) at level max, Rector, Pint, and CI covering PHP 8.3/8.4 × Laravel 12/13 on both `prefer-lowest` and `prefer-stable`.
- A real Testbench workbench with models covering every relation type, soft deletes, UUID keys and cycles.

## 1.1.0

- Added `saveQuietly()` option.

## 1.0.0

- Initial release, based on [neurony/laravel-duplicate](https://github.com/neurony/laravel-duplicate).
