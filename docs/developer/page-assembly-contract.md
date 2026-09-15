# Page Assembly Descriptor v1

The product standard is `larena.frontend.composition` 1.0.0. New reusable
structure uses `larena.layout.artifact.v1`: page, section and block share one
envelope and differ by `kind`. Existing Page Assembly and legacy Page
Descriptor documents remain bounded compatibility inputs during page-by-page
migration.

An artifact placement names a child artifact, exact child revision (or the
published pointer), instance, slot, order, enabled state and inert parameter
overrides. Revisions are immutable. The relationship table indexes every
parent revision, so a child may have several current parents and a published
tree cannot silently change when a child draft changes.

`LayoutArtifactResolver` receives UI-owned manifests and validates every view,
variant and named slot before projecting the recursive tree to Framework
Recipe. Artifact `parameters.data` and `parameters.props` become literal Recipe
data and props; typed content, storage, setting and logical-file bindings remain
references until their owner resolves the exact revision. Auth request values
such as CSRF, passwords, one-time codes, entered identity and personalized
errors are never artifact or shared-snapshot data.

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

Migration notes: additive contract only. Existing stored page descriptors
remain readable through the adapter and are not rewritten at installation.
The artifact store creates its three tables explicitly; uninstall drops only
those tables, and reapply recreates an empty store. Application migration and
data backup remain Root responsibilities.

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
