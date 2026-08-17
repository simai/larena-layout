# Tests

Passed on PHP 8.4.20:

- Composer validation on PHP 8.4.20;
- focused MinimalCmsDependencyContractTest;
- workspace dependency report with zero violations and no selected-package cycle;
- Root dependency closure test with exactly twelve runtime packages and deferred packages only in packages-dev.
- full package quality gate, including lint, static analysis, package regression tests, metadata/evidence validation and scope enforcement.
