# Verification

Passed on PHP 8.3.31:

- package PHP syntax lint;
- PHPStan with no errors;
- all existing and new package tests;
- SQLite create, update, publish, history, restore, restart, uninstall and reapply;
- reusable block referenced by two current sections;
- queries by kind, parent and child;
- stale-revision and cross-scope rejection without partial writes;
- disposable MySQL 8.4 create, publish, update, restore, uninstall and reapply;
- recursive page to section to columns block to paragraph block resolution;
- repeated reuse of the same artifact with path-scoped Framework instance IDs and one dependency receipt entry;
- exact Framework source runtime compilation and server HTML;
- existing Recipe compiler and immutable snapshot regression tests;
- PHP-encoded artifact validation against the accepted JSON Schema.
- Framework canonical JSON vectors including Unicode UTF-16 key order, empty
  object/array distinction and JavaScript array-index ordering;
- immutable package catalog path containment, parent/child queries and database
  override precedence;
- node-only PHP Recipe resolution, Framework node IDs and receipt digests.
