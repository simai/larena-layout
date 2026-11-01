// The reference reading of the kit's comparison rule (README.md, "Comparing
// HTML"): two HTML fragments are equal when their canonical forms are equal.
// A renderer in another language ports this rule, not this file; it may read
// the fragment with any conforming HTML parser. normalization.json holds the
// pairs every port must decide the same way.
//
// This reader is small on purpose and reads what a renderer of structured
// content writes: elements, attributes (quoted, unquoted or bare), text,
// comments, the void elements, script and style as raw text, and the
// character references &amp; &lt; &gt; &quot; &apos; &nbsp; and numeric ones.
// It does not repair markup the way a browser does (no implied end tags but
// the void ones); a renderer never needs that.

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
const RAW = new Set(['script', 'style']);
// Phrasing elements: white space next to them is text. Every other element
// stands as a block, and white space at its edges is layout, not content.
export const INLINE_ELEMENTS = Object.freeze(new Set([
  'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'img', 'kbd', 'mark', 'q',
  's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
  'sf-badge', 'sf-button', 'sf-icon',
]));
const NAMED = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };
const WHITE = /[\t\n\f\r ]+/gu;

function decode(text) {
  return text.replace(/&(?:#(\d+)|#[xX]([0-9a-fA-F]+)|([a-zA-Z]+));/gu, (whole, decimal, hex, name) => {
    if (decimal) return String.fromCodePoint(Number(decimal));
    if (hex) return String.fromCodePoint(Number.parseInt(hex, 16));
    return Object.hasOwn(NAMED, name) ? NAMED[name] : whole;
  });
}

/** The fragment as a tree: {type: 'element', name, attributes: [[name, value]], children} and {type: 'text', value}. */
export function parseFragment(html) {
  const root = { type: 'element', name: '#fragment', attributes: [], children: [] };
  const stack = [root];
  const top = () => stack[stack.length - 1];
  const text = (value) => {
    const siblings = top().children;
    const last = siblings[siblings.length - 1];
    if (last?.type === 'text') last.value += value;
    else siblings.push({ type: 'text', value });
  };
  let at = 0;
  while (at < html.length) {
    const open = html.indexOf('<', at);
    if (open === -1) { text(decode(html.slice(at))); break; }
    if (open > at) text(decode(html.slice(at, open)));
    if (html.startsWith('<!--', open)) {
      const close = html.indexOf('-->', open + 4);
      at = close === -1 ? html.length : close + 3;
      continue;
    }
    if (html.startsWith('<!', open) || html.startsWith('<?', open)) {
      const close = html.indexOf('>', open);
      at = close === -1 ? html.length : close + 1;
      continue;
    }
    const end = /^<\/([a-zA-Z][^\s/>]*)[^>]*>/u.exec(html.slice(open));
    if (end) {
      const name = end[1].toLowerCase();
      const index = stack.map((node) => node.name).lastIndexOf(name);
      if (index > 0) stack.length = index;
      at = open + end[0].length;
      continue;
    }
    const start = /^<([a-zA-Z][^\s/>]*)/u.exec(html.slice(open));
    if (!start) { text('<'); at = open + 1; continue; }
    const element = { type: 'element', name: start[1].toLowerCase(), attributes: [], children: [] };
    let cursor = open + start[0].length;
    const attribute = /^[\t\n\f\r ]*(?:(\/?>)|([^\s"'>/=]+)(?:[\t\n\f\r ]*=[\t\n\f\r ]*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?|\/)/u;
    for (;;) {
      const match = attribute.exec(html.slice(cursor));
      if (!match) { cursor = html.length; break; }
      cursor += match[0].length;
      if (match[1]) break;
      if (!match[2]) continue;
      const name = match[2].toLowerCase();
      // The first of two attributes of one name wins, as a parser keeps it.
      if (!element.attributes.some(([existing]) => existing === name)) element.attributes.push([name, decode(match[3] ?? match[4] ?? match[5] ?? '')]);
    }
    top().children.push(element);
    at = cursor;
    if (VOID.has(element.name)) continue;
    if (RAW.has(element.name)) {
      const close = html.toLowerCase().indexOf(`</${element.name}`, at);
      const body = html.slice(at, close === -1 ? html.length : close);
      if (body) element.children.push({ type: 'text', value: body });
      at = close === -1 ? html.length : html.indexOf('>', close) + 1;
      continue;
    }
    // A parser drops one line feed right after <pre>.
    if (element.name === 'pre' && html[at] === '\n') at += 1;
    stack.push(element);
  }
  return root;
}

const isBlock = (node) => node?.type === 'element' && !INLINE_ELEMENTS.has(node.name);

// White space outside pre: runs fold to one space; a space at an edge that
// touches a block (a block sibling, or the start or end of a block parent)
// goes; a text left empty goes.
function settle(node, inPre) {
  if (node.type !== 'element') return node;
  const pre = inPre || node.name === 'pre' || RAW.has(node.name);
  const children = node.children.map((child) => settle(child, pre));
  if (pre) return { ...node, children };
  const settled = [];
  children.forEach((child, index) => {
    if (child.type !== 'text') { settled.push(child); return; }
    let value = child.value.replace(WHITE, ' ');
    const before = index > 0 ? children[index - 1] : node;
    const after = index < children.length - 1 ? children[index + 1] : node;
    if (isBlock(before)) value = value.replace(/^ /u, '');
    if (isBlock(after)) value = value.replace(/ $/u, '');
    if (value) settled.push({ type: 'text', value });
  });
  return { ...node, children: settled };
}

const escapeText = (value) => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
const escapeAttribute = (value) => value.replaceAll('&', '&amp;').replaceAll('"', '&quot;');

function attributeValue(name, value) {
  if (name === 'class') return [...new Set(value.split(WHITE).filter(Boolean))].sort().join(' ');
  if (name === 'style') {
    return value.split(';').map((declaration) => declaration.replace(WHITE, ' ').trim()).filter(Boolean).map((declaration) => {
      const colon = declaration.indexOf(':');
      if (colon === -1) return declaration.toLowerCase();
      return `${declaration.slice(0, colon).trim().toLowerCase()}: ${declaration.slice(colon + 1).trim()}`;
    }).sort().join('; ');
  }
  return value;
}

function serialize(node, inRaw = false) {
  if (node.type === 'text') return inRaw ? node.value : escapeText(node.value);
  const inner = node.children.map((child) => serialize(child, RAW.has(node.name))).join('');
  if (node.name === '#fragment') return inner;
  const attributes = [...node.attributes].sort(([left], [right]) => (left < right ? -1 : left > right ? 1 : 0))
    .map(([name, value]) => ` ${name}="${escapeAttribute(attributeValue(name, value))}"`).join('');
  return VOID.has(node.name) ? `<${node.name}${attributes}>` : `<${node.name}${attributes}>${inner}</${node.name}>`;
}

/** The canonical form of an HTML fragment under the kit's comparison rule. */
export function canonicalHtml(html) {
  return serialize(settle(parseFragment(String(html)), false));
}

/** Whether two fragments are the same under the kit's comparison rule. */
export function sameHtml(left, right) {
  return canonicalHtml(left) === canonicalHtml(right);
}

/** Diagnostics as the kit compares them: "code path", sorted, the messages left out. */
export function diagnosticKeys(diagnostics) {
  return diagnostics.map(({ code, path }) => `${code} ${path}`).sort();
}
