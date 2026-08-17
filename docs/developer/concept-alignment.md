# Concept alignment: Larena Layout

## Accepted role

Layout owns stored composition for `site -> page -> region -> section -> block` and emits a deterministic normalized render plan. Blocks reference Smart Component identifiers without invoking a renderer.

Accepted Target State: `larena.target.minimal_cms_v1` at semantic digest `sha256:2793f61ba9563839831d57e87ac5cd6399c37a3183f1a68981b6fc7f941a1ad2`.

## Dependency and ownership boundary

- Mandatory Larena dependency: Core.
- UI consumes the render plan; Layout must not depend on UI or call renderers.
- Component manifests, props, assets and allowlists belong to UI.

## Continuation strategy

Existing composition contracts continue where compatible. B3 adds the lower Core edge; B11 completes the accepted hierarchy and normalized-plan failure behavior.

## Current alignment

B11 adds an explicit site envelope over the existing page descriptor and emits a deterministic normalized render plan. Region, component and source references fail closed. Layout returns identifiers and bindings only; it imports no UI class and invokes no renderer.

## Install and rollback baseline

Install through the Root Composer lock and run only Layout-owned migrations. B0 is documentation-only. Later migration rollback must preserve or explicitly reject used page compositions; application rollback restores the previous verified Root lock.

## Verification

Run package tests, `composer validate`, dependency reporting and normalized-plan fixtures including unknown region/source rejection.
