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
