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

This developer slice does not claim a theme builder, full page builder,
production renderer or production readiness.

Universal layout and page composition engine for public pages, admin pages, dashboards, forms, lists, detail pages, documentation pages and widgets in Larena.

Package implementation is limited to typed contracts and in-memory validation.
It contains no routes, renderer, persistence or frontend assets.

Canonical specifications are in `simai/larena-specs`.
