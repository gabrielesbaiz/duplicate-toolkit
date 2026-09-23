# Contributing

Thank you for considering a contribution.

## Before you open a pull request

Run all four. They are the contract, and CI runs the same:

```bash
composer test        # Pest
composer analyse     # PHPStan, level max — must stay clean
composer format      # Pint
composer rector-dry  # Rector
```

PHPStan runs at **level max** with no baseline. Please do not add
`@phpstan-ignore` comments, baseline entries or `assert()` calls to silence an
error — fix the underlying type instead. If an error genuinely cannot be fixed,
say so in the pull request and we will look at it together.

## Tests

The suite runs against a real Testbench application in `workbench/`, with models
covering every relation type, soft deletes, UUID keys and self-referencing
cycles.

- A bug fix should come with a test that fails without it.
- A new option needs a unit test on `DuplicateOptions` and a feature test that
  exercises it end to end.
- A new relation behaviour needs a workbench model or migration if the existing
  ones do not cover it.

Watch out for one trap in Pest: **arrow functions capture by value**, so
`fn ($o) => $o->beforeSave(function () use (&$seen) { ... })` binds the inner
closure to a *copy* of `$seen`. Use a full `function () use (&$seen)` when you
need a reference.

## Style

- `declare(strict_types=1)` in every file.
- Typed properties, parameters and returns. No magic accessors.
- Generics in docblocks where PHPStan needs them (`@return HasMany<Version, $this>`).
- Pint with the project's `pint.json`. Run `composer format` rather than
  hand-formatting.
- Comments explain *why*, not *what*. If the code needs a comment to say what it
  does, the code is the thing to change.

## Reporting a bug

A good report has: the package version, the Laravel and PHP versions, the model
and relation definitions involved, what you expected, and what happened. The
output of

```bash
php artisan duplicate-toolkit:relations "App\Models\YourModel" --depth=2 --json
```

is usually the single most useful thing you can paste.

## Security

Do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).
