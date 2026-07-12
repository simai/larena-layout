# Tests

Environment: ServBay PHP 8.4, 2026-07-12.

Passed:

- `php -d zend.assertions=1 -d assert.exception=1 tests/Unit/AdminLayoutRecipeTest.php`;
- `php -d zend.assertions=1 -d assert.exception=1 scripts/run-unit-tests.php`;
- `php scripts/validate-larena-package.php`;
- `php scripts/lint.php`;
- `php scripts/analyse.php` with PHPStan level 5;
- `php scripts/check-evidence.php`;
- `php tools/larena-scope-check.php`;
- `composer quality:gate` with ServBay PHP 8.4.

Semantic coverage includes valid defaults, deterministic projection, legacy
collection compatibility, required and optional regions, bounded form fields,
unknown recipe/region, invalid and duplicate invocation IDs, malformed maps,
missing required regions, overflow, invalid profile/version, registry collision
and invalid registration.
