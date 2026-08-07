# S-LAYOUT-01 evidence

Layout owns a persistent, revisioned JSON page descriptor with bounded regions,
sections, blocks and typed owner bindings. The package canonicalizes object
keys, orders structural nodes by declared sort plus stable ID, and preserves
binding-list order as page semantics.

Only registered components and four typed binding kinds are accepted: Content
document, Storage record, logical file and File Manager tree node. Targets
remain logical identities. Executable code, rendered HTML and physical blob
paths are outside canonical state. Owner failures become the single public
`layout_binding_unavailable` diagnostic.

Readiness claim: `TESTED`, pending independent audit. This is not Root
integration, adoption, merge readiness, browser rendering or production
readiness.
