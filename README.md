# Larena Layout

Larena Frontend Composition 1.0 adds one recursive artifact model for pages,
sections and blocks. Package-owned artifacts come from pinned Git revisions;
site-owned artifacts use `LayoutArtifactStore`; compiled Framework Documents,
HTML and dependency receipts remain immutable snapshots. The same artifact can
be placed in several parents because parent-child rows are a query projection,
not an ownership tree.

`PdoLayoutArtifactStore` provides immutable revision history, optimistic
updates, draft publication, restoration, queries by kind/parent/child and a
revision-pinned placement index on SQLite and MySQL. `LayoutArtifactResolver`
checks the component, view, variant, named slot, cardinality, kind and optional
component allowlist supplied by `larena/ui`, then emits
`simai.composition.recipe.v1`. Unknown references and manifests fail before a
snapshot can be activated.

`PackageLayoutArtifactCatalog` reads immutable package artifacts from a pinned
catalog without allowing paths to escape the package. `HybridLayoutArtifactCatalog`
prefers a site-owned database revision and falls back to the packaged system
revision. Database overrides may reference pinned package children; the store
checks the combined graph for cycles before committing the override.

`FrameworkNodeRecipeResolver` is the PHP request-time adapter for the bounded
node-only Recipe profile emitted by `LayoutArtifactResolver`. It reproduces the
Framework node identity and canonical digest rules, emits a Framework Document
and dependency receipt, and performs no Node.js process or external I/O.

The typed Admin recipe contract defines package-owned `admin.collection` and
`admin.form` composition without taking ownership of UI components, rendering,
records or effects. `admin.collection` preserves the existing heading, toolbar,
content and pagination layout used by Pages and Users and exposes the exact
Simai Framework utility families/classes required by its content wrapper.
`admin.form` adds bounded
heading, notifications, fields and actions regions.

Layout validates recipe identity, profile, named regions, invocation identity
and cardinality. Larena UI validates component keys, props, slots and rendering;
backend packages remain responsible for scoped data, permissions and effects.
Unknown recipes and invalid assignments fail closed.

Page Assembly v1 is the canonical backend composition envelope. It normalizes
site, page, region, section and block order while retaining only stable Smart
Component view, preset, modifier and binding identifiers. A bounded adapter
accepts the already supported legacy page descriptor shape. This authoring
contract does not contain renderer code or HTML.

The optional Composition Recipe adapter passes one authorized projection and
its settings to an exactly pinned Simai Framework runtime. A successful build
can be stored as one immutable snapshot containing Document, HTML and its
dependency receipt. `FileCompiledPageSnapshotStore` changes the active snapshot
only when the caller supplies the current activation revision. Rollback points
to an earlier verified snapshot while increasing that revision, and every read
checks the scope policy again. The store is a database-free integration surface;
the application still owns its production cache and publication policy.

Layout also owns a persistent declarative JSON page descriptor and a typed
headless projection boundary. Descriptor create, update, read and projection
require an explicit actor/operation/scope policy. Owner adapters return one of
four exact result contracts (`content_document`, `storage_record`,
`logical_file`, `tree_node`); projection canonicalizes object keys, preserves
list order, enforces aggregate bounds and rejects paths, storage locators,
HTML/code and private fields. Public failures retain no owner exception chain.

Schema installation remains an explicit lifecycle step. Rejected first writes
therefore cannot create tables, and rejected writes against an installed schema
remain transactionally atomic.

This developer slice does not claim a theme builder, visual page builder,
mass page migration or production readiness. Application adoption starts with
the isolated Auth login page and remains a separate acceptance stage.

Universal layout and page composition engine for public pages, admin pages, dashboards, forms, lists, detail pages, documentation pages and widgets in Larena.

The package contains no routes or frontend assets. Its package/database catalog,
headless projection and compiled-snapshot adapters are used by application
owners; application-specific types and HTML remain outside Layout.

Canonical specifications are in `simai/larena-specs`.
