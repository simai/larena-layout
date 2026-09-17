# Implementation

RegionInheritanceResolver merges authorized site, section and page layers independently for shell and registered regions. Explicit empty replacement clears a region. Exact catalog revisions and placement identity are preserved. Receipt includes source provenance and all traversed artifact revisions. No persistence, publication or renderer is added.

Existing artifact resolver now projects inherited region placements through its same recursive entry implementation. CLI test compiles page → section → externally bound Unicode paragraph to meaningful HTML on the exact old pair. Its lock is 1.0.0 / 894de36…, differing from accepted Specs 1.0.1 / 63daf55…; release conformance remains pending, see framework-contract-delivery-gap.json.

`ArtifactRegionInheritanceModes` reads the namespaced
`larena.layout:region_modes` artifact extension. Registered defaults remain
server-owned. An artifact may override a registered region with `inherit`,
`replace` or `empty`; reset removes only that local key and restores the
registered default. Unknown regions and modes fail closed, and input artifacts
are not mutated.
