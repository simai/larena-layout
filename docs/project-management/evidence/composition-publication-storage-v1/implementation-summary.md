# Implementation

`PdoCompiledPageSnapshotStore` stores canonical immutable snapshot payloads by digest and keeps the active digest behind an activation revision. Activation and rollback use compare-and-swap semantics and recheck the package authorization policy.

Standalone calls own a PDO transaction. If the connection is already in a transaction, the store participates without committing or rolling it back. This allows Setting and Layout state to be published atomically when the product supplies one shared database connection.

The candidate also preserves the previously accepted region-placement continuation and the effective hybrid catalog correction. No Framework grammar, generic browser apply endpoint, route or live site is changed.

`PdoLayoutArtifactStore::transactional()` owns the outer product publication unit. Artifact create, update and publish operations join it when the PDO connection is already in a transaction. A later snapshot failure therefore rolls the artifact pointer back with the snapshot activation.
