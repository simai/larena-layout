# Structured content on a server

Owner decision 2026-11-01: SIMAI Framework structured content (a Composition Document,
`simai.composition.document`, profile `structured-content`, with `content.*` nodes) is drawn on a server by
Larena, here in Layout. Static Docara has no PHP renderer; dynamic Docara is built on Larena.

```php
use Larena\Layout\StructuredContent\Renderer;

$document = json_decode($json, false);           // objects, not arrays: {} and [] stay apart
$page = (new Renderer())->render($document);      // a layout with a content.outlet: render($layout, content: $page)
$page->html;         // '' when the Document is invalid
$page->assets;       // the Framework assets the drawn types need, sorted
$page->diagnostics;  // [{code, path, message}]
```

`Validator::validate($document)` alone gives what validation finds.

## The contract

The Framework's conformance kit is the contract. A page drawn here and a page drawn by the Framework's
JS renderer are the same page. The kit lives in `tests/Fixtures/structured-content-conformance`. `PIN.json` names
the ui-source revision it was copied from and the sha256 of every file. It also records the sha256 of
`resources/structured-content/built-in-types.json`, the Framework's built-in type manifests at that revision.

- `StructuredContentKitTest` keeps the copy as pinned, and checks the PHP reading of the kit's comparison rule
  (`tests/Support/ConformanceHtml.php`) against `normalization.json`;
- `StructuredContentConformanceTest` runs every case. Each case is checked for validity, HTML under the comparison
  rule, the diagnostics as a multiset of (code, path), and the assets as a set.

To move to a new kit, copy `contracts/conformance/structured-content` from ui-source at the released revision.
Regenerate `built-in-types.json` from that revision's `builtins.mjs` (`BUILTIN_TYPE_MANIFESTS`,
`CONTENT_ITEM_PARENTS`), rewrite `PIN.json`, and make the cases pass. A case that looks wrong is a question for the
Framework, not a place to diverge.

## What is not here

- Registered types (`content.diagram`, `content.math`) are drawn by a host. Here they draw nothing and report
  `renderer_unavailable` at `node:<id>`; their assets are still named.
- A ui-layout `layout.scope`: its ports and routes are the Framework's. It is not validated or drawn here.
- File sizes use ICU (`ext-intl`) for the locale's digits and grouping. Without it they read as plain numbers.
