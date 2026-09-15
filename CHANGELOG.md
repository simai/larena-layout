# Changelog

## Unreleased

### Added

- Add the Minimal CMS site descriptor and deterministic normalized render plan.
- Reject unknown pages, regions, Smart Component identifiers and binding sources before rendering.
- Preserve an enclosing database transaction while persisting page descriptors through a savepoint.
- Add the canonical Page Assembly v1 normalizer and a compatibility adapter for accepted legacy page descriptors.
- Persist only canonical Page Assembly descriptors, retain immutable revision history and support optimistic-concurrency rollback without reviving the legacy runtime model.
- Compile one approved article scenario through the pinned Simai Framework Composition Recipe runtime.
- Store complete compiled page snapshots, switch them with revision comparison and roll back without changing database schemas.

### Documentation

- Record the accepted Minimal CMS v1 composition ownership and renderer-independent render-plan boundary.
- Document the stable component/view/preset/modifier composition contract used by backend page assembly.
- Explain the boundary between the authored Page Assembly, Framework Recipe and active compiled page snapshot.

### Non-claims

- No runtime behavior, public contract or package version changes are introduced by the B0 preparation.
