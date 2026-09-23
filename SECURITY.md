# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 2.x | Yes |
| 1.x | No — upgrade, see [UPGRADE.md](UPGRADE.md) |

## Reporting a vulnerability

Email **gabriele@sbaiz.com** with a description, the package version, and a
reproduction if you have one. Please do not open a public issue.

You will get an acknowledgement within 5 working days and an assessment within
15. If the report is valid you will be credited in the release notes unless you
ask not to be.

## Scope

In scope:

- Duplicating a record that writes rows the caller could not otherwise write.
- A relation strategy that attaches records across a tenant or ownership
  boundary it should not cross.
- The Artisan commands executing model code that is not a relation method while
  `discovery.invoke_untyped` is disabled.
- The upgrade codemod writing outside the directory it was given.

Out of scope:

- Enabling `discovery.invoke_untyped` and having your own untyped methods
  invoked. That is the documented behaviour of the setting.
- Observers, listeners or queued jobs firing for duplicated records when
  `quietly()` is not used.
- Duplicating a record the authenticated user should not have been able to read.
  Authorisation is the application's responsibility; this package duplicates
  whatever model it is handed.
- Running out of memory or exceeding a query limit on an unbounded relation
  tree. Use `depth()` and `preview()`.
