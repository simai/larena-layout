# Changelog

## Snapshot catalog candidate — 2026-09-17

- Add optional authorized digest-keyset history for PDO and file compiled snapshots, without changing activation or rollback interfaces.
- Validate retained payload integrity before returning summaries; never expose rendered HTML or dependency data in catalog results.

## Unreleased

- A site page's blocks in its record (larena-specs `docs/architecture/2026-10-29-sites-work-plan.md`, step 1.5; owner decision q-4ede0beabe): `Runtime\PageContentBlockValidator` implements Property's `PropertyContentBlockValidator` for content@1 values. A block type is a block component a page assembly may hold (`PageDescriptorComponentRegistry::hasBlock()`, the host's registry through `PageAssemblyDescriptorNormalizer::components()`); version 1 only (`layout_block_version_unknown`); params follow the assembly's props rules (`PageAssemblyDescriptorNormalizer::props()`, `layout_block_param_invalid:<param>`, `layout_block_params_invalid`); `larena.list` carries its binding's `target` (required, `StorageListBindingQuery::TARGET_PATTERN`) and `query` (`StorageListBindingQuery::normalize()`) as params. An unknown type is Property's `property_content_block_unknown:<type>`. `LayoutServiceProvider` extends the container's `PropertyTypeRegistry` with it (`withContentBlocks`) where Property is installed: `larena/property` is suggested and a development requirement, so Property stays independent of Layout. `Runtime\PageAssemblyContentBlocks::fromAssembly(?array $assembly)` converts a page assembly's content region (`region.main`) to the content@1 list `{type, version: 1, params}` in page order, a list block's binding folded into its params; what content@1 cannot carry (a view, preset or modifiers, other bindings, a list without one `storage_list` binding, list props named `target`/`query`) is refused with `layout_content_block_*:<block id>`. Test `tests/Unit/PageContentBlocksTest.php`.
- Add the `storage_list` page binding (block `larena.list`): a live list of published storage records with a validated query, a host save-time `PageBindingValidator`, and request-time resolution outside the page projection.
- Project layout.regions artifacts into Framework regions, derive layout.scope endpoints from routes and pass product port bindings to the Recipe runtime.

### Added

- Add a versioned `LayoutArtifactStore` and query catalog for reusable page, section and block JSON artifacts.
- Add immutable revision-pinned placement links with many-parent lookup, publication and restoration.
- Project recursively nested artifacts through UI-owned named-slot manifests to Framework Composition Recipe 1.0.1.
- Verify artifact persistence and reinstall on SQLite and disposable MySQL 8.4.
- Read immutable system artifacts through a traversal-safe package catalog and
  resolve database overrides over that catalog.
- Add a no-I/O PHP adapter for the node-only Framework Recipe profile with
  cross-language canonical JSON and Framework node identities.

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
