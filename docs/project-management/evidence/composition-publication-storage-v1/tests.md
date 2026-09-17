# Tests

- Focused SQLite test: initial activation, immutable payload readback, stale revision refusal, participation in an outer transaction, rollback and restart persistence.
- Artifact publication transaction test: a forced later failure restores the previous published revision; a successful outer transaction commits the new revision.
- Package PHP lint over all source and test files.
- PHPStan 2.2.1 at package level with zero errors.
- Complete package test runner, including region placement, hybrid catalog and snapshot regression tests.
- Package metadata, evidence and changed-file scope gates.

MySQL is intentionally not claimed by this package-local evidence. Root integration is recorded separately by the consuming isolated candidate.
