# Changelog

## Unreleased

### Added

- Add the Minimal CMS site descriptor and deterministic normalized render plan.
- Reject unknown pages, regions, Smart Component identifiers and binding sources before rendering.
- Preserve an enclosing database transaction while persisting page descriptors through a savepoint.
- Add the canonical Page Assembly v1 normalizer and a compatibility adapter for accepted legacy page descriptors.
- Persist only canonical Page Assembly descriptors, retain immutable revision history and support optimistic-concurrency rollback without reviving the legacy runtime model.

### Documentation

- Record the accepted Minimal CMS v1 composition ownership and renderer-independent render-plan boundary.
- Document the stable component/view/preset/modifier composition contract used by backend page assembly.

### Non-claims

- No runtime behavior, public contract or package version changes are introduced by the B0 preparation.
