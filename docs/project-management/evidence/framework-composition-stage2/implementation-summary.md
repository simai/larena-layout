# Implementation

`FrameworkCompositionProjector` maps the existing `PageComposition` to `simai.composition.document.v1`, preserving IDs, order, settings, bindings, revisions, logical asset references and enabled state in extensions. It never mutates the source.
