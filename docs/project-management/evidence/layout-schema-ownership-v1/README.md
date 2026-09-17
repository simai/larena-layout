# Layout schema ownership candidate

This isolated candidate moves installation ownership of the three layout-artifact tables and two compiled-snapshot tables into a discoverable `larena/layout` Laravel service provider and package migration. Runtime requests no longer install schema.

SQLite migration, rollback and reapply are verified in the package and in a disposable Larena Root application. The candidate is not committed, published, deployed or accepted as release readiness. MySQL remains unverified without a disposable database.

