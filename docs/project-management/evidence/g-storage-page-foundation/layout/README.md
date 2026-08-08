# S-LAYOUT-01-CORRECTION evidence

Layout owns a persistent, revisioned JSON page descriptor with bounded regions,
sections, blocks and typed owner bindings. The package canonicalizes object
keys, orders structural nodes by declared sort plus stable ID, and preserves
binding-list order as page semantics.

Every create, update, read and projection is guarded by an explicit
package-owned actor/operation/scope policy. There is no default allow binding.
Validation and authorization precede persistence access, and a rejected first
create leaves a fresh database with zero Layout tables or rows.

Only registered components and four typed binding kinds are accepted: Content
document, Storage record, logical file and File Manager tree node. Targets
remain logical identities. Executable code, rendered HTML and physical blob
paths are outside canonical state. Owner failures become the single public
`layout_binding_unavailable` diagnostic without retaining a raw previous
exception. Each binding kind has an exact result schema; associative maps are
canonicalized while list, binding and block order remain semantic. Aggregate
depth, fan-out, node count and encoded-byte limits fail closed.

Readiness claim: `TESTED`, pending independent audit. This is not Root
integration, adoption, merge readiness, browser rendering or production
readiness.
