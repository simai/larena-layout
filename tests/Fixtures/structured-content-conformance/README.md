# Structured content: conformance kit 1.0.0

Page content in the SIMAI Framework is a Composition Document
(`simai.composition.document`, schema version 1) with the profile
`structured-content` (`docs/developer/composition-content-types.md`). This kit
is the set of Documents any renderer of structured content must draw, and what
it must draw. The SIMAI Framework's JS renderer (`SF.Composition.validate` and
`SF.Composition.render`, `src/core/js/composition/`) is the reference: the
expectations are its output. A renderer written in another language, on a
server for one, passes the same kit; then a page drawn by either is the same
page.

The kit is language-neutral: JSON in, HTML and diagnostics out, and a
comparison rule written down below. Nothing in it depends on JavaScript but
`normalize-html.mjs`, which is one reading of that rule, not the rule.

## Files

| File | What it holds |
|:---|:---|
| `manifest.json` | the kit's version, the Document format, the version of every content type the cases were written against, counts, and the ordered list of case ids |
| `cases/<id>.json` | one case: the input Document (and, for an outlet, the page Document) and what a renderer answers |
| `normalization.json` | pairs of HTML fragments and whether the comparison rule holds them equal, with the canonical form of each |
| `normalize-html.mjs` | the reference reading of the comparison rule (JavaScript, no dependencies) |
| `README.md` | this text, normative |

## A case

```json
{
  "schema": "simai.composition.structured-content-conformance-case",
  "schema_version": 1,
  "id": "alert-status-warning",
  "summary": "status warning and its icon.",
  "covers": ["content.alert"],
  "input": {"document": {"schema": "simai.composition.document", "...": "..."}},
  "expect": {
    "valid": true,
    "html": "<article data-sf-composition-id=\"root\">...</article>",
    "diagnostics": [],
    "assets": ["component/alerts"]
  }
}
```

- `input.document` is the Document to draw. `input.content`, present only in
  the outlet cases, is the page Document a layout's `content.outlet` draws
  (the JS call is `render(document, {content})`).
- `expect.valid` is whether validating `input.document` alone finds nothing.
- `expect.html` is what rendering draws. An invalid Document draws nothing:
  the empty string.
- `expect.diagnostics` is what rendering reports: for an invalid Document the
  validation diagnostics; for a valid one what drawing found (a registered
  type with no renderer, an outlet's page that is not structured content, the
  page's own diagnostics under `$.content`). Each is `{code, path}`.
- `expect.assets` is the set of assets the drawn Document needs
  (`component/alerts`, `composition/diagram`, ...), the page's included.
- `covers` names what the case is about: a type, `inline`, `escaping` or
  `document`. It is for reading, not for comparing.

A case uses no registry but the built-in one and registers no renderer: the
registered types (`content.diagram`, `content.math`) are drawn by a host, so
the kit pins only that a renderer without one reports `renderer_unavailable`
at `node:<id>`, draws nothing in its place and still names the type's assets.

## Passing a case

A renderer passes a case when all four hold:

1. **valid**: its validation of `input.document` agrees with `expect.valid`;
2. **html**: its HTML and `expect.html` are equal under the comparison rule
   below;
3. **diagnostics**: the multiset of `(code, path)` pairs is the same. Order
   does not count; messages do not count; every code and every path does.
   Paths are JSONPath-like, from the Document root (`$.root.slots.default[0].data.href`),
   `$.content...` for the page of an outlet, and `node:<id>` for a node a
   renderer could not draw;
4. **assets**: the set is the same; order does not count.

A renderer passes the kit when it passes every case listed in
`manifest.json`, at the type versions listed there.

## Comparing HTML

Both fragments are read as HTML (the HTML Living Standard's fragment parsing,
in the context of a `div`) and written in a canonical form; they are equal
when the canonical forms are equal. The canonical form is:

1. **Comments** are removed, as is a doctype.
2. **Element names** are lowercase.
3. **Attributes** are sorted by name (lowercase, code point order), each
   written `name="value"`. A value is compared after character references
   are decoded: `&#39;`, `&apos;` and `'` are one character. An attribute
   written bare (`open`) has the empty value, as `open=""` does; any other
   value (`open="open"`) is a different value. The first of two attributes of
   one name is the one that counts, as a parser keeps it.
4. **`class`** is a set: its tokens are split on ASCII white space, repeats
   dropped, sorted, joined by one space. The order of classes does not count;
   every class does.
5. **`style`** is a set of declarations: split on `;`, empty ones dropped,
   white space folded, the property lowercase, written `property: value`,
   sorted, joined by `; `.
6. **Void elements** (`area base br col embed hr img input link meta param
   source track wbr`) have no end tag; `<hr>` and `<hr/>` are one element.
7. **Text** is compared after character references are decoded, and written
   with `&`, `<` and `>` escaped. Outside `pre` (and outside `script` and
   `style`):
   - every run of ASCII white space (tab, line feed, form feed, carriage
     return, space) folds to one space;
   - a space at the start of a text is removed when what stands before it is
     a block, or when it is the first child of a block; a space at its end is
     removed when what stands after it is a block, or when it is the last
     child of a block;
   - a text left empty is removed.

   An element is **inline** when it is one of `a abbr b bdi bdo br cite code
   data dfn em i img kbd mark q s samp small span strong sub sup time u var
   wbr` or one of the inline atoms' elements `sf-badge sf-button sf-icon`;
   every other element, and the fragment itself, is a **block**. So white
   space between two `li` or at the edges of a `p` is layout, while the space
   between `<strong>a</strong>` and `<em>b</em>` is text.
8. **Inside `pre`** every character counts, except the one line feed a parser
   drops right after the `<pre>` start tag.

What counts, then: every element, its place and its order; every attribute
and its value (`data-sf-composition-id` included); every class; every
character of text, white space between inline content included. What does not:
attribute order, class order, quoting, character reference spelling, comments,
self-closing slashes, and white space a reader never sees.

`normalization.json` holds the vectors of this rule; a port decides each the
same way. `normalize-html.mjs` exports `canonicalHtml(html)`, `sameHtml(left,
right)` and `diagnosticKeys(diagnostics)`; it reads well-formed markup of the
kind a renderer writes and does not repair broken markup as a browser would.

## Rules a renderer reproduces

The cases pin each of these; the summaries here say where to look.

- **Escaping**: text escapes `& < > " '`; attribute values escape the same.
  The kit compares decoded characters, so any correct spelling passes.
- **Inline content**: a text run's marks nest in the order listed, the first
  innermost (`["strong", "em"]` is `<em><strong>x</strong></em>`); a link is
  `<a href>`; the atoms draw their element with their declared props as
  attributes, `true` bare, `false` left out, `kbd` with its text as content.
  An inline node of an unknown type, an unknown atom name, an undeclared prop
  or an atom inside a link makes the Document invalid (`schema_one_of`): it is
  never drawn, and never silently dropped.
- **Addresses**: a link, an image source and a file address are relative
  (`/`, `./`, `../`), a fragment, or `http:`, `https:`, `mailto:` or `tel:`;
  `//host` and a backslash path are not relative. Anything else is
  `unsafe_url`.
- **`content.html`** is cleaned at render by the html@1 subset of
  sf-rich-text (`src/core/js/composition/html-subset.mjs`): `p h2 h3 h4
  strong em a[href] ul ol li blockquote br`, no other attribute, `href` only
  `https:`, `mailto:`, `tel:` or a site path. Every vector of
  `tests/fixtures/rich-text-vectors.json` is a case here.
- **`content.file`** writes its size in the Document's `locale` (English when
  there is none): the bytes are divided by 1024 while the amount is 1024 or
  more, up to gigabytes; bytes and amounts of 10 or more have no fraction
  digit, smaller amounts at most one; the number is formatted for the locale
  as CLDR says (ICU `NumberFormatter`, or `Intl.NumberFormat`, with grouping),
  then one space and the unit word: `B KB MB GB` in English, `Б КБ МБ ГБ` in
  Russian, the English words in any other language. Its icon follows the
  media type (the cases list each family).
- **`content.outlet`** draws the page Document in its place; an invalid page
  draws nothing there and its diagnostics come under `$.content`.

## Keeping the kit current

The inputs are written in `scripts/generate-structured-content-conformance.mjs`;
the expectations are the JS renderer's.

```bash
node scripts/generate-structured-content-conformance.mjs           # write
node scripts/generate-structured-content-conformance.mjs --check   # verify
```

`tests/structured-content-conformance.contract.test.mjs` runs the check, runs
the JS renderer over every case under the comparison rule, and asks that the
cases reach every content type, every value of every parameter (each choice,
both booleans, the least and the greatest integer) and every data key.

A change of rendering shows as a drift of the kit. When the change is meant,
the kit is regenerated and its version moves: a patch for a case added or a
summary corrected, a minor for new types or parameters, a major when an
existing expectation changes. A type's own version moves with it as the
registry's rules say.
