# Implementation

LayoutArtifactNormalizer enforces one inert JSON envelope for page, section
and block artifacts. PdoLayoutArtifactStore stores immutable revisions,
separate publication pointers and revision-pinned placement projections.
Current parents and children can be queried without assigning an exclusive
parent to a reusable artifact.

LayoutArtifactResolver accepts UI-owned component manifests, verifies kind,
view, variant, named slot, cardinality and optional component restrictions, and
projects an exact artifact tree to simai.composition.recipe.v1. The existing
Framework compiler and immutable snapshot store remain the only compile and
activation path.

PackageLayoutArtifactCatalog supplies immutable Git-owned artifacts while
HybridLayoutArtifactCatalog selects database overrides without copying system
artifacts. PdoLayoutArtifactStore resolves pinned package children and checks
the combined graph before committing an override. FrameworkNodeRecipeResolver
implements the request-time, node-only PHP adapter and produces Framework
Document identities and receipts without spawning Node.js.
