# Smoke

A disposable SQLite Larena Root database created exactly the five package-owned tables, removed them on rollback, and restored them on reapply. The adjacent owner-bound composition and storage-query tests passed without request-time `install()` calls.

No live server, working site, production database, route, `main` branch or remote repository was changed.

