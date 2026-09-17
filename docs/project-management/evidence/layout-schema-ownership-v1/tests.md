# Tests

- Composer metadata discovers the Layout service provider.
- The migration adopts tables first created by both PDO compatibility helpers.
- Migration `up()` is idempotent; `down()` removes all five owned tables; reapply recreates them.
- Complete Layout package quality gate: contract validation, lint, PHPStan, package tests, evidence and scope checks.
- Disposable Larena Root SQLite `artisan migrate`, rollback and reapply using the package migration.
- Adjacent Larena Root composition cohort after removing request-time DDL: 36 tests and 389 assertions.

MySQL is not verified because no disposable database credentials were available. No production or user database was used as a substitute.

