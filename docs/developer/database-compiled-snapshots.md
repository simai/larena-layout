# Database compiled page snapshots

`PdoCompiledPageSnapshotStore` implements the existing `CompiledPageSnapshotStore` contract with immutable snapshot rows and a separate compare-and-swap active pointer. It does not introduce another compiled document format.

Laravel applications discover `Larena\\Layout\\Providers\\LayoutServiceProvider`; its package migration owns creation and rollback of the artifact and snapshot tables. Product requests must never call `install()` or run DDL.

The explicit `install()` and `uninstall()` methods remain compatibility helpers for standalone package tests and non-Laravel adapters. `uninstall()` removes only the two tables owned by this snapshot store and belongs exclusively to an explicit rollback or disposable test environment.

The store starts and completes its own transaction for a standalone activation or rollback. When the supplied PDO connection is already inside a transaction, the store joins that transaction and leaves commit or rollback to the caller. A product can therefore apply owner settings and activate the matching page snapshot as one database operation when both use the same connection.

The caller still owns compilation, authorization context and orchestration. The store refuses malformed compiled results, invalid identities, stale activation revisions, unknown rollback digests and corrupted immutable payloads. It never accepts arbitrary setting keys or browser action URLs.

The file-backed store remains available for independent snapshot publication. It must not be used when another package claims atomic publication with database state, because a filesystem pointer cannot be committed or rolled back by the database transaction.

The package tests prove Laravel provider discovery, adoption of tables created by the compatibility helpers, SQLite migration rollback/reapply, persistence across restart, compare-and-swap activation, rollback and participation in an outer transaction. MySQL requires separate acceptance with a disposable database before release.
