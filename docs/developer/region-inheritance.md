# Region inheritance (candidate)

`RegionInheritanceResolver` implements the B2 candidate `larena.frontend.region-inheritance` from Specs revision `07fab889`. It consumes a derived `larena.layout.region_inheritance_request.v1`, not a new stored page format.

Order is exactly site → section → page. Shell and each registered region resolve independently. Omission or `inherit` keeps earlier state. `replace` uses the supplied ordered list; an empty list explicitly clears it. Append is unsupported.

The constructor requires a trusted source guard. It must resolve the authorized context through the owning application, check scope and actor, verify source revisions, and compare each input layer with canonical owner data. A callback that merely returns true is unsafe in production. The artifact catalog independently enforces artifact access and exact revision existence.

Output contains references and a provenance receipt, not HTML or a Framework document. Feed it into existing artifact resolution and Recipe projection; do not add a second renderer. Nested cycles, registered component slots and type manifests must still pass the existing artifact and Framework gates before publication. Exact historical artifact revisions are valid; never silently substitute latest versions.

All traversed dependencies, including earlier overridden layers, remain in the receipt. Cache identity must additionally include owner inputs, settings, localization and exact frontend pair. This resolver does not activate snapshots. Failed resolution must leave the existing active snapshot untouched at the caller boundary.

No database migration or live activation is included. The integration guard and complete browser/publication acceptance remain pending.
