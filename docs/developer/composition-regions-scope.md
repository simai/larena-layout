# Composition regions and scope

A `layout.regions` artifact declares its regions in `parameters.props.regions`
(1–12 entries with `name`, `landmark` and optional `placement`, `label`,
`sticky`, `accepts`, `min_items`, `max_items`). Each region name is one artifact
slot, so region inheritance keeps mapping slots to regions. The Recipe carries
every region as a Framework `layout.region` node in declaration order; the
Framework validates landmarks, labels and cardinality.

Inside a `layout.scope` artifact, each child placement whose id is named by a
route in `parameters.props.routes` becomes a Framework endpoint with that name.
Children no route names get no endpoint. Products bind their own types to
published port elements by exporting `createCompositionPorts(framework)` from
their registry module; the Recipe adapter passes it as `compositionPorts` and
as render ports.

Nothing here extends the Framework grammar or the artifact schema.
