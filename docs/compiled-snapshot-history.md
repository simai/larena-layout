# Compiled snapshot discovery

`CompiledPageSnapshotCatalog` is an optional Layout-owned read capability implemented by both `PdoCompiledPageSnapshotStore` and `FileCompiledPageSnapshotStore`. Existing activation/rollback contracts are unchanged; consumers must check this capability rather than read owner tables directly.

`history(scopeRef, pageId, actor, limit = 20, afterDigest = null)` applies the existing page READ authorization before discovery. Limits are 1–100; cursors are validated SHA-256 digests. Results contain `snapshots` and `next_cursor`; each summary contains only `snapshot_digest`, `source_revision` and `active`. It excludes HTML, dependency receipts, content and actor identities.

Entries use ascending digest keyset order, **not publication chronology**. A returned cursor resumes strictly after the last digest of the preceding page. Read the full selected page before returning it: integrity errors fail the operation without partial success. The active pointer is validated as well. PDO uses the existing transaction helper; files use the existing shared lock. Discovery neither activates a snapshot nor rewrites sources.

The editor may use source revisions as friendly labels, explicitly show the active result and send a selected digest to the existing rollback operation with an expected activation revision. Rollback restores the compiled result only; current Layout/Content/Setting source revisions are not restored implicitly. Stale activation is rejected and the current result remains active.

No schema migration or separate history registry is introduced. File discovery enumerates names within the authorized page directory before selecting a bounded page; it is not a scalable database listing claim.

Tests: `tests/Unit/CompiledPageSnapshotCatalogTest.php` covers both backends, cursor pages, summary projection, actor/scope denial, bounds, rollback and reopened-store persistence, and tampered historical entries. Existing snapshot activation/restart/transaction tests remain mandatory.
