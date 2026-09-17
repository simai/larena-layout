# Smoke

The candidate was exercised against a disposable SQLite database. The active snapshot survived reopening the database, and an activation made inside an outer transaction disappeared after that outer transaction rolled back.

No server, browser route or live site was changed.
