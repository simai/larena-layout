# Implementation summary

- A layout.regions artifact declares its regions; each region name is one artifact slot, so region inheritance keeps the existing slot model.
- The Recipe carries each region as a layout.region node with its declared props.
- Children of a layout.scope named by its routes receive the simai.composition:endpoint extension with the placement id.
- The Recipe adapter passes product port bindings as compositionPorts and render ports.
