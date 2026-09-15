# Larena Layout

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
production renderer or production readiness.

Universal layout and page composition engine for public pages, admin pages, dashboards, forms, lists, detail pages, documentation pages and widgets in Larena.

The package contains no routes or frontend assets. Its PDO descriptor
persistence, headless projection and compiled-snapshot adapter are package-level
candidates awaiting application adoption.

Canonical specifications are in `simai/larena-specs`.
