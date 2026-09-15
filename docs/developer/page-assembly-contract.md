# Page Assembly Descriptor v1

`larena/layout` owns the canonical declarative page assembly document. The
document describes placement and bindings; it never contains PHP classes,
SIMAI Framework tags, templates, HTML, JavaScript, asset paths or executable
callbacks.

The canonical hierarchy is:

```text
site -> page -> region -> section -> block -> Smart component key
```

Every section and block chooses a stable component key, named view, optional
preset, allowlisted modifier keys, inert props and typed owner bindings. Layout
normalizes this data and returns a deterministic plan. `larena/ui` validates
the chosen view, preset, modifiers, props, slots, renderer and assets against
the component manifest.

The JSON Schema is `resources/schemas/page-assembly.schema.json`. The example
`resources/examples/admin-collection.page-assembly.json` is a reference input,
not runtime data.

The earlier `larena.layout.page_descriptor` document remains accepted through
`LegacyPageDescriptorAdapter`. It is a compatibility input, not a second
canonical authoring model. The adapter supplies `view=default`, no preset, no
modifiers and no instance props, preserving the old deterministic meaning.

Unknown keys, components, binding kinds, unsafe props, executable-looking
keys, duplicate IDs and aggregate limits fail closed. A future constructor
edits this descriptor through validated forms and preview; raw JSON remains an
advanced developer surface.

Migration notes: additive contract only. No database migration is introduced
in this batch. Existing stored page descriptors remain readable through the
adapter. Rollback removes the new normalizer, schema, example and adapter.

## Recipe and active result

Page Assembly remains Larena's editable source. `FrameworkRecipeRequestFactory`
can map an authorized projection to a Simai Framework Recipe request. Framework
then produces a complete Document, HTML and dependency receipt.

These three values may be saved together as an immutable compiled-page
snapshot. The active pointer has its own increasing revision. Publication and
rollback must name the revision they observed; a stale request fails instead of
overwriting a newer result. Rollback selects an older verified snapshot and
creates a new pointer revision. Reading or switching the result repeats the
Larena scope-policy check, so revoking access also affects cached output.

The file store proves this lifecycle without a database migration. A Larena
application may provide another implementation of `CompiledPageSnapshotStore`,
but it must preserve the same whole-snapshot, revision and authorization rules.
