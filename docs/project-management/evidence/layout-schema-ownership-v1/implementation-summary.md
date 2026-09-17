# Implementation

`Larena\\Layout\\Providers\\LayoutServiceProvider` loads one package migration for:

- `larena_layout_artifacts`;
- `larena_layout_artifact_versions`;
- `larena_layout_artifact_links`;
- `larena_layout_compiled_snapshots`;
- `larena_layout_active_snapshots`.

The migration preserves existing helper-created tables, is repeatable, and drops its five tables in dependency-safe reverse order. The PDO stores retain `install()` and `uninstall()` solely for standalone tests and non-Laravel adapters.

The isolated Larena Root candidate removes request-time schema installation from the storage provider, owner-bound composition runtime and editor service. Existing PageDescriptor tables remain under their current Root migration in this bounded batch.

