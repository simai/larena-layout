# Implementation summary

- Added an explicit site descriptor with allowed pages and regions.
- Added a normalized render-plan contract with a stable digest.
- Reused the bounded page normalizer for section, block and binding validation.
- Kept UI and renderer code outside the Layout package.
