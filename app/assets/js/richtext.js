/**
 * richtext.js — formatted text: the cleaner and a small editor for it.
 *
 * Researchers write the instructions shown above the chat (settings, Step 1)
 * in the editor of this file; participants see the result in aiTeam.php.
 *
 * The stored format is a small, fixed subset of HTML:
 *
 *   <p>…</p>  <ul>/<ol> with <li>  <br>
 *   <strong>  <em>  <u>  <span style="color:#rrggbb;font-size:13|18|24px">
 *
 * sanitizeRichText() turns any HTML into that format. It never copies markup:
 * it reads the input, keeps the text and the few kinds of formatting above,
 * and writes the result from scratch, so nothing else can get through
 * (scripts, links, pictures, styles of other programs). Whatever a browser
 * leaves in an editable area (<b>, <font size>, <div>, style attributes) and
 * whatever arrives from the clipboard comes out in the same form.
 *
 * The same rules exist in PHP (includes/richtext.php richtext_sanitize), which
 * cleans the text again when it is saved and when it is shown. The unit test
 * app/tests/richtext.test.mjs runs both on the same inputs, so a change on
 * one side that is not made on the other fails there.
 *
 * @author Marc Becker
 */

/** The longest text that is stored (characters of the cleaned HTML). */
export const RICHTEXT_MAX_CHARS = 20000;
/** The text color when none is chosen (--ink in app.css). It is never written into the text. */
export const RICHTEXT_DEFAULT_COLOR = '#0f172a';
/**
 * The text sizes: what participants see in pixels, and the legacy <font size>
 * the browser's editing commands work with. "normal" is the size of the
 * surrounding text and is never written into the text.
 */
export const RICHTEXT_SIZES = Object.freeze({
  small:  Object.freeze({ label: 'Small',       px: 13, legacy: '2' }),
  normal: Object.freeze({ label: 'Normal',      px: 15, legacy: '3' }),
  large:  Object.freeze({ label: 'Large',       px: 18, legacy: '4' }),
  xl:     Object.freeze({ label: 'Extra large', px: 24, legacy: '5' }),
});

// =============================================================================
// The cleaner
// =============================================================================

/** Elements without content or end tag. */
const VOID = new Set(['br', 'hr', 'img', 'input', 'meta', 'link', 'wbr', 'col', 'area', 'base', 'embed', 'source', 'track', 'param']);
/** Elements whose content is not markup: everything up to their end tag is dropped. */
const RAW_TEXT = new Set(['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext']);
/** Elements that are dropped with everything inside them. */
const DROP = new Set(['head', 'template', 'noscript', 'object', 'applet', 'svg', 'math', 'canvas', 'audio', 'video', 'select', 'button', 'datalist', 'map', 'frameset', 'xml']);
/** Elements that start a new line; in the result each stretch of text between them is a paragraph. */
const BLOCK = new Set(['address', 'article', 'aside', 'blockquote', 'body', 'caption', 'center', 'dd', 'details', 'dialog', 'div', 'dl', 'dt',
  'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'html', 'legend', 'main',
  'nav', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'tfoot', 'thead', 'tr']);
const LIST = new Map([['ul', 'ul'], ['ol', 'ol'], ['menu', 'ul'], ['dir', 'ul']]);
const MAX_DEPTH = 60;     // open elements that are followed; deeper ones are read as plain content
const MAX_LIST_DEPTH = 3; // lists inside lists

const NAMED_ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: '\u00A0' };
const COLOR_NAMES = new Map([['black', '#000000'], ['white', '#ffffff'], ['red', '#ff0000'], ['green', '#008000'], ['blue', '#0000ff'],
  ['yellow', '#ffff00'], ['orange', '#ffa500'], ['purple', '#800080'], ['gray', '#808080'], ['grey', '#808080'], ['silver', '#c0c0c0'],
  ['maroon', '#800000'], ['navy', '#000080'], ['teal', '#008080'], ['olive', '#808000'], ['lime', '#00ff00'], ['aqua', '#00ffff'],
  ['cyan', '#00ffff'], ['fuchsia', '#ff00ff'], ['magenta', '#ff00ff'], ['pink', '#ffc0cb'], ['brown', '#a52a2a']]);
const SIZE_KEYWORDS = new Map([['xx-small', 'small'], ['x-small', 'small'], ['small', 'small'], ['smaller', 'small'], ['medium', 'normal'],
  ['large', 'large'], ['larger', 'large'], ['x-large', 'xl'], ['xx-large', 'xl'], ['xxx-large', 'xl'], ['-webkit-xxx-large', 'xl']]);

// ASCII-only helpers, so that both implementations treat every character alike.
const lower = (s) => s.replace(/[A-Z]+/g, (m) => m.toLowerCase());
const trimWs = (s) => s.replace(/^[ \t\n\r\f]+|[ \t\n\r\f]+$/g, '');

/** &amp; &lt; &gt; &quot; &apos; &nbsp; and numeric references → characters. Anything else stays as written. */
function decodeEntities(s) {
  if (s.indexOf('&') === -1) return s;
  return s.replace(/&(#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|amp|lt|gt|quot|apos|nbsp);/g, (all, ref) => {
    if (ref[0] !== '#') return NAMED_ENTITIES[ref];
    const code = (ref[1] === 'x' || ref[1] === 'X') ? parseInt(ref.slice(2), 16) : parseInt(ref.slice(1), 10);
    if (!code || code > 0x10FFFF || (code >= 0xD800 && code <= 0xDFFF)) return '\uFFFD';
    return String.fromCodePoint(code);
  });
}

/** Text as it is kept: no control or zero-width characters, every run of white space one space. */
function cleanText(s) {
  return s
    .replace(/\p{Cs}/gu, '\uFFFD') // half of a surrogate pair cannot be stored
    .replace(/[\u0000-\u0008\u000B\u000E-\u001F\u007F-\u009F\u200B\u2060\uFEFF]/g, '')
    .replace(/[ \t\n\r\f\u2028\u2029]+/g, ' ');
}

function escapeText(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\u00A0/g, '&nbsp;');
}

/** A CSS or <font> color (lower case, trimmed) → "#rrggbb", or null when it is none we read. */
export function parseColor(v) {
  let m = /^#([0-9a-f]{3,8})$/.exec(v);
  if (m) {
    const h = m[1];
    if (h.length === 3 || h.length === 4) {
      if (h.length === 4 && h[3] === '0') return null; // fully transparent
      return '#' + h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    }
    if (h.length === 6) return '#' + h;
    if (h.length === 8) return h.slice(6) === '00' ? null : '#' + h.slice(0, 6);
    return null;
  }
  m = /^rgba?\(([^()]*)\)$/.exec(v);
  if (m) {
    const parts = m[1].split(/[ \t\n\r\f,\/]+/).filter((p) => p !== '');
    if (parts.length < 3 || parts.length > 4) return null;
    let hex = '#';
    for (let k = 0; k < 3; k++) {
      const pm = /^(\d{1,3}(?:\.\d{1,4})?)(%?)$/.exec(parts[k]);
      if (!pm) return null;
      let n = parseFloat(pm[1]);
      if (pm[2]) n = n * 255 / 100;
      hex += Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');
    }
    if (parts.length === 4 && /^0(?:\.0+)?%?$/.test(parts[3])) return null; // fully transparent
    return hex;
  }
  return COLOR_NAMES.has(v) ? COLOR_NAMES.get(v) : null;
}

/** A CSS font size (lower case, trimmed) → 'small' | 'normal' | 'large' | 'xl', or null when it cannot be read. */
export function parseSize(v) {
  if (SIZE_KEYWORDS.has(v)) return SIZE_KEYWORDS.get(v);
  const m = /^(\d{1,4}(?:\.\d{1,4})?)(px|pt|pc|em|rem|%)$/.exec(v);
  if (!m) return null;
  let px = parseFloat(m[1]);
  if (m[2] === 'pt') px = px * 4 / 3;
  else if (m[2] === 'pc') px = px * 16;
  else if (m[2] === 'em' || m[2] === 'rem') px = px * 15;
  else if (m[2] === '%') px = px * 15 / 100;
  if (px <= 0) return null;
  // The nearest of the four sizes (13, 15, 18 and 24 px).
  if (px <= 13.2) return 'small';
  if (px <= 16.5) return 'normal';
  if (px <= 21) return 'large';
  return 'xl';
}

/** <font size="1…7"> (or "+1", "-2") → 'small' | 'normal' | 'large' | 'xl', or null. */
export function parseLegacySize(v) {
  const m = /^([+-]?)(\d{1,2})$/.exec(trimWs(v));
  if (!m) return null;
  let n = parseInt(m[2], 10);
  if (m[1] === '+') n = 3 + n;
  else if (m[1] === '-') n = 3 - n;
  n = Math.max(1, Math.min(7, n));
  if (n <= 2) return 'small';
  if (n === 3) return 'normal';
  return n === 4 ? 'large' : 'xl';
}

/** Black and the default ink count as "no color": the text then simply has the color of its surroundings. */
const isDefaultColor = (hex) => hex === RICHTEXT_DEFAULT_COLOR || hex === '#000000';

/** Pasted text that is almost white (copied from a dark page) would be invisible on the white box. */
function tooLight(hex) {
  const lin = [1, 3, 5].map((k) => {
    const v = parseInt(hex.substr(k, 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2] > 0.6;
}

const ATTR_RE = /([^ \t\n\r\f"'<>\/=]+)(?:[ \t\n\r\f]*=[ \t\n\r\f]*(?:"([^"]*)"|'([^']*)'|([^ \t\n\r\f"'=<>`]+)))?/g;
/** The attributes the cleaner reads (style, size, color) from the inside of a start tag. */
function parseAttrs(str) {
  const out = {};
  if (str.length > 4000) return out;
  ATTR_RE.lastIndex = 0;
  let m;
  while ((m = ATTR_RE.exec(str))) {
    const name = lower(m[1]);
    if ((name === 'style' || name === 'size' || name === 'color') && out[name] === undefined) {
      out[name] = decodeEntities(m[2] !== undefined ? m[2] : (m[3] !== undefined ? m[3] : (m[4] !== undefined ? m[4] : '')));
    }
  }
  return out;
}

const formatKey = (f) => (f.b ? 'b' : '') + (f.i ? 'i' : '') + (f.u ? 'u' : '') + '|' + (f.c || '') + '|' + (f.s || '');
const BASE_FORMAT = Object.freeze({ b: false, i: false, u: false, c: null, s: null, key: '||' });

/**
 * Clean formatted text: any HTML → the stored format (see the top of this file).
 *
 * paste: the HTML comes from the clipboard. Two things then apply that make
 * no sense for text written in the editor: colors that are too light to read
 * on white are not taken over, and the list paragraphs of Microsoft Word
 * (which are no lists in its HTML) become real lists.
 */
export function sanitizeRichText(input, { paste = false } = {}) {
  const html = String(input === null || input === undefined ? '' : input);
  const lowerHtml = lower(html);
  const n = html.length;

  const root = { blocks: [] };
  const stack = []; // open elements: { name, type: 'inline' | 'block' | 'list' | 'li', format, list?, item? }
  let para = null;  // the paragraph being filled: { runs, container }
  let skip = null;  // inside a dropped element: { name, depth, marker? }

  const format = () => (stack.length ? stack[stack.length - 1].format : BASE_FORMAT);
  /** Where paragraphs and lists go: the innermost open list item, otherwise the text itself. */
  function container() {
    for (let k = stack.length - 1; k >= 0; k--) if (stack[k].type === 'li') return stack[k].item;
    return root;
  }
  const lastRun = () => (para && para.runs.length ? para.runs[para.runs.length - 1] : null);

  function addText(raw) {
    let text = cleanText(decodeEntities(raw));
    if (text === '') return;
    const last = lastRun();
    // No space at the start of a line, and never two in a row.
    if (text[0] === ' ' && (!last || last.br || last.text.endsWith(' '))) text = text.slice(1);
    if (text === '') return;
    if (!para) para = { runs: [], container: container() };
    const f = format();
    if (last && !last.br && last.format.key === f.key) last.text += text;
    else para.runs.push({ text, format: f });
  }
  /** No space before a line break or at the end of a paragraph. */
  function trimEnd() {
    const last = lastRun();
    if (last && !last.br && last.text.endsWith(' ')) {
      last.text = last.text.slice(0, -1);
      if (last.text === '') para.runs.pop();
    }
  }
  function addBreak() {
    if (!para) para = { runs: [], container: container() };
    trimEnd();
    para.runs.push({ br: true });
  }
  /** The current paragraph ends here. */
  function flush() {
    if (!para) return;
    trimEnd();
    const runs = para.runs;
    // A single line break after the last text shows nothing (browsers leave one there as a placeholder).
    if (runs.length > 1 && runs[runs.length - 1].br && !runs[runs.length - 2].br) runs.pop();
    if (runs.length) para.container.blocks.push({ type: 'p', runs });
    para = null;
  }

  /** Read a style attribute into the format f. Returns { hidden, wordList, wordMarker }. */
  function applyStyle(f, style, parent) {
    const found = { hidden: false, wordList: false, wordMarker: false };
    for (const part of lower(style).split(';')) {
      const at = part.indexOf(':');
      if (at < 0) continue;
      const prop = trimWs(part.slice(0, at));
      const val = trimWs(trimWs(part.slice(at + 1)).replace(/![ \t\n\r\f]*important$/, ''));
      if (prop === 'font-weight') {
        if (val === 'bold' || val === 'bolder') f.b = true;
        else if (val === 'normal' || val === 'lighter') f.b = false;
        else if (/^\d{1,4}(?:\.\d{1,4})?$/.test(val)) f.b = parseFloat(val) >= 600;
      } else if (prop === 'font-style') {
        if (val === 'italic' || val.startsWith('oblique')) f.i = true;
        else if (val === 'normal') f.i = false;
      } else if (prop === 'text-decoration' || prop === 'text-decoration-line') {
        // A decoration without "underline" only takes back the element's own line, never the one around it.
        f.u = /(?:^|[ \t\n\r\f])underline(?:$|[ \t\n\r\f])/.test(val) ? true : parent.u;
      } else if (prop === 'color') {
        const c = parseColor(val);
        if (c && !(paste && tooLight(c))) f.c = isDefaultColor(c) ? null : c;
      } else if (prop === 'font-size') {
        const s = parseSize(val);
        if (s) f.s = s === 'normal' ? null : s;
      } else if (prop === 'display') {
        if (val === 'none') found.hidden = true;
      } else if (prop === 'mso-list' && paste) {
        // Word: "l0 level1 lfo1" marks a list paragraph, "Ignore" the bullet or number written in front of it.
        if (val === 'ignore') found.wordMarker = true;
        else if (/^l\d+[ \t]+level\d+/.test(val)) found.wordList = true;
      }
    }
    return found;
  }

  /** Start a list item: in the list that is open, or in a list of its own when there is none. */
  function openItem(name, f) {
    flush();
    // An item that is still open in the same list ends here.
    let at = stack.length - 1;
    while (at >= 0 && stack[at].type !== 'list') {
      if (stack[at].type === 'li') stack.length = at;
      at--;
    }
    let list;
    if (at >= 0) {
      list = stack[at].list;
    } else {
      const blocks = container().blocks;
      const last = blocks.length ? blocks[blocks.length - 1] : null;
      if (last && last.implicit) list = last;
      else { list = { type: 'ul', items: [], implicit: true }; blocks.push(list); }
    }
    const item = { blocks: [] };
    list.items.push(item);
    stack.push({ name, type: 'li', format: f, item, list });
  }

  function open(name, attrs, selfClosing) {
    if (skip) {
      if (name === skip.name && !selfClosing) skip.depth++;
      return;
    }
    if (name === 'br') { addBreak(); return; }
    if (name === 'hr') { flush(); return; }
    if (VOID.has(name)) return;
    if (DROP.has(name)) {
      if (!selfClosing) skip = { name, depth: 1 }; // <svg/> has no content and no end tag
      return;
    }

    const parent = format();
    const f = { b: parent.b, i: parent.i, u: parent.u, c: parent.c, s: parent.s, key: '' };
    if (name === 'b' || name === 'strong' || name === 'th') f.b = true;
    else if (name === 'i' || name === 'em') f.i = true;
    else if (name === 'u' || name === 'ins') f.u = true;
    else if (name === 'h1' || name === 'h2') { f.b = true; f.s = 'xl'; }
    else if (name === 'h3') { f.b = true; f.s = 'large'; }
    else if (name === 'h4' || name === 'h5' || name === 'h6') { f.b = true; f.s = null; }
    else if (name === 'small') f.s = 'small';
    else if (name === 'big') f.s = 'large';
    else if (name === 'font') {
      if (attrs.size !== undefined) {
        const s = parseLegacySize(attrs.size);
        if (s) f.s = s === 'normal' ? null : s;
      }
      if (attrs.color !== undefined) {
        const v = lower(trimWs(attrs.color));
        const c = parseColor(v) || (/^[0-9a-f]{6}$/.test(v) ? '#' + v : null);
        if (c && !(paste && tooLight(c))) f.c = isDefaultColor(c) ? null : c;
      }
    }
    const found = attrs.style !== undefined ? applyStyle(f, attrs.style, parent) : null;
    if (found && (found.hidden || found.wordMarker)) {
      skip = { name, depth: 1 };
      if (found.wordMarker) skip.marker = '';
      return;
    }
    f.key = formatKey(f);
    if (stack.length >= MAX_DEPTH) return;

    const listType = LIST.get(name);
    if (listType) {
      flush();
      let depth = 0;
      for (const frame of stack) if (frame.type === 'list') depth++;
      if (depth >= MAX_LIST_DEPTH) { stack.push({ name, type: 'inline', format: f }); return; }
      const list = { type: listType, items: [] };
      const top = stack.length ? stack[stack.length - 1] : null;
      if (top && top.type === 'list') {
        // A list directly inside a list (browsers build this when indenting) belongs to the item before it.
        if (!top.list.items.length) top.list.items.push({ blocks: [] });
        top.list.items[top.list.items.length - 1].blocks.push(list);
      } else {
        container().blocks.push(list);
      }
      stack.push({ name, type: 'list', format: f, list });
    } else if (name === 'li' || (found && found.wordList && BLOCK.has(name))) {
      openItem(name, f);
    } else if (BLOCK.has(name)) {
      flush();
      stack.push({ name, type: 'block', format: f });
    } else {
      if (name === 'td' || name === 'th') addText(' '); // cells of a row stay apart
      stack.push({ name, type: 'inline', format: f });
    }
  }

  function close(name) {
    if (skip) {
      if (name === skip.name && --skip.depth === 0) {
        // Word writes "1." or "a)" in front of the items of a numbered list.
        let item = null;
        for (let k = stack.length - 1; k >= 0 && !item; k--) if (stack[k].type === 'li') item = stack[k];
        if (skip.marker !== undefined && item && item.list.implicit && item.list.items.length === 1 &&
            /^(?:\d{1,3}|[a-zA-Z]{1,4})[.)](?:[^0-9a-zA-Z]|$)/.test(trimWs(cleanText(skip.marker)))) {
          item.list.type = 'ol';
        }
        skip = null;
      }
      return;
    }
    let at = stack.length - 1;
    while (at >= 0 && stack[at].name !== name) at--;
    if (at < 0) return;
    for (let k = at; k < stack.length; k++) {
      if (stack[k].type !== 'inline') { flush(); break; }
    }
    stack.length = at;
  }

  // ---- Read the HTML ----------------------------------------------------------
  let i = 0;
  while (i < n) {
    const lt = html.indexOf('<', i);
    const textEnd = lt === -1 ? n : lt;
    if (textEnd > i) {
      if (!skip) addText(html.slice(i, textEnd));
      else if (skip.marker !== undefined) skip.marker += decodeEntities(html.slice(i, textEnd));
    }
    if (lt === -1) break;
    i = lt;
    const next = html[i + 1];
    if (html.startsWith('<!--', i)) {
      const end = html.indexOf('-->', i + 4);
      i = end === -1 ? n : end + 3;
    } else if (next === '!' || next === '?') {
      const end = html.indexOf('>', i + 2);
      i = end === -1 ? n : end + 1;
    } else if (next === '/') {
      const m = /^[a-zA-Z][^ \t\n\r\f\/>]*/.exec(html.slice(i + 2, i + 66));
      const end = html.indexOf('>', i + 2);
      i = end === -1 ? n : end + 1;
      if (m) close(lower(m[0]));
    } else if (next !== undefined && /[a-zA-Z]/.test(next)) {
      const name = lower(/^[a-zA-Z][^ \t\n\r\f\/>]*/.exec(html.slice(i + 1, i + 65))[0]);
      // The end of the tag: the first ">" outside quotes.
      let j = i + 1 + name.length;
      const attrsStart = j;
      while (j < n && html[j] !== '>') {
        if (html[j] === '"' || html[j] === "'") {
          const q = html.indexOf(html[j], j + 1);
          j = q === -1 ? n : q + 1;
        } else {
          j++;
        }
      }
      const attrs = html.slice(attrsStart, j);
      i = j < n ? j + 1 : n;
      if (RAW_TEXT.has(name)) {
        // Skip to the end tag; without one the rest is dropped.
        let from = i;
        i = n;
        for (;;) {
          const c = lowerHtml.indexOf('</' + name, from);
          if (c === -1) break;
          const after = html[c + 2 + name.length];
          if (after === undefined || after === '>' || after === '/' || ' \t\n\r\f'.indexOf(after) !== -1) {
            const end = html.indexOf('>', c);
            i = end === -1 ? n : end + 1;
            break;
          }
          from = c + 2;
        }
      } else {
        open(name, skip ? {} : parseAttrs(attrs), attrs.endsWith('/'));
      }
    } else {
      if (!skip) addText('<');
      else if (skip.marker !== undefined) skip.marker += '<';
      i++;
    }
  }
  flush();

  // ---- Write the result -------------------------------------------------------
  const isBlank = (block) => block.type === 'p' && block.runs.every((r) => r.br);
  while (root.blocks.length && isBlank(root.blocks[0])) root.blocks.shift();
  while (root.blocks.length && isBlank(root.blocks[root.blocks.length - 1])) root.blocks.pop();

  /** The elements around a run, outermost first: [[start tag, end tag name], …]. */
  function wrappers(f) {
    const w = [];
    const css = [];
    if (f.c) css.push('color:' + f.c);
    if (f.s) css.push('font-size:' + RICHTEXT_SIZES[f.s].px + 'px');
    if (css.length) w.push(['span style="' + css.join(';') + '"', 'span']);
    if (f.b) w.push(['strong', 'strong']);
    if (f.i) w.push(['em', 'em']);
    if (f.u) w.push(['u', 'u']);
    return w;
  }
  function renderRuns(runs) {
    let out = '';
    const opened = [];
    const closeTo = (count) => { while (opened.length > count) out += '</' + opened.pop()[1] + '>'; };
    const shared = (want) => {
      let k = 0;
      while (k < opened.length && k < want.length && opened[k][0] === want[k][0]) k++;
      return k;
    };
    runs.forEach((run, idx) => {
      if (run.br) {
        // A line break stays inside the formatting that the next text continues.
        let nextText = null;
        for (let k = idx + 1; k < runs.length; k++) if (!runs[k].br) { nextText = runs[k]; break; }
        closeTo(nextText ? shared(wrappers(nextText.format)) : 0);
        out += '<br>';
        return;
      }
      const want = wrappers(run.format);
      const keep = shared(want);
      closeTo(keep);
      for (let k = keep; k < want.length; k++) { out += '<' + want[k][0] + '>'; opened.push(want[k]); }
      out += escapeText(run.text);
    });
    closeTo(0);
    return out;
  }
  function renderList(list) {
    if (!list.items.length) return '';
    return '<' + list.type + '>' + list.items.map((item) => '<li>' + renderItem(item) + '</li>').join('') + '</' + list.type + '>';
  }
  /** An item holds its text directly; several paragraphs become lines of it. */
  function renderItem(item) {
    let out = '';
    let before = null;
    item.blocks.forEach((block) => {
      if (block.type === 'p') {
        if (before && !before.runs[before.runs.length - 1].br) out += '<br>';
        out += renderRuns(block.runs);
        before = block;
      } else {
        out += renderList(block);
        before = null;
      }
    });
    return out === '' ? '<br>' : out;
  }
  return root.blocks.map((block) => (block.type === 'p' ? '<p>' + renderRuns(block.runs) + '</p>' : renderList(block))).join('');
}

/** The plain text of cleaned formatted text (lines joined with spaces). */
export function richTextToText(html) {
  return trimWs(decodeEntities(String(html || '').replace(/<[^>]*>/g, ' ')).replace(/[ \t\n\r\f\u00A0]+/g, ' '));
}

/** True when cleaned formatted text has nothing a reader could see. */
export function richTextIsEmpty(html) {
  return richTextToText(html) === '';
}

/** Plain text (for example from the clipboard) → paragraphs, one per line. */
export function plainTextToRichText(text) {
  const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
  const esc = (line) => line.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return sanitizeRichText(lines.map((line) => '<p>' + (trimWs(line) === '' ? '<br>' : esc(line)) + '</p>').join(''));
}

// =============================================================================
// The editor
// =============================================================================

/** The colors offered in the palette: [name, color]. All of them are readable on white. */
const PALETTE = [
  ['Default', RICHTEXT_DEFAULT_COLOR], ['Gray', '#64748b'], ['Red', '#dc2626'], ['Orange', '#ea580c'], ['Amber', '#b45309'],
  ['Green', '#15803d'], ['Teal', '#0f766e'], ['Blue', '#2563eb'], ['Purple', '#7c3aed'], ['Pink', '#db2777'],
];

/** The formatting each shortcut letter stands for. */
const SHORTCUTS = new Map([['b', 'bold'], ['i', 'italic'], ['u', 'underline']]);

/**
 * The editing command a key press asks for: 'bold', 'italic' or 'underline'
 * for ⌘B, ⌘I, ⌘U on a Mac and Ctrl+B, Ctrl+I, Ctrl+U elsewhere, otherwise null.
 * e is a keydown event (or an object with its fields).
 */
export function shortcutCommand(e, isMac) {
  // Shift and Alt make other shortcuts; AltGr (Ctrl+Alt on Windows) types characters.
  if (e.altKey || e.shiftKey || e.isComposing) return null;
  if (isMac ? !e.metaKey || e.ctrlKey : !e.ctrlKey || e.metaKey) return null;
  // The letter printed on the key; on a keyboard without Latin letters, the key in that position.
  let letter = lower(String(e.key || ''));
  if (!/^[a-z]$/.test(letter)) letter = /^Key[A-Z]$/.test(e.code || '') ? lower(e.code.slice(3)) : '';
  return SHORTCUTS.has(letter) ? SHORTCUTS.get(letter) : null;
}

/**
 * Build a formatted-text editor inside host.
 *
 *   value        the stored text (cleaned before it is shown)
 *   placeholder  shown while the editor is empty
 *   label        the accessible name of the text area
 *   onChange     called after every change of the text
 *
 * Returns { getHTML, setHTML, isEmpty, focus, element }. getHTML() is the
 * cleaned text, or '' when there is nothing to read.
 *
 * The editing itself is the browser's (contenteditable and its commands), so
 * typing, selecting and undo behave as everywhere else. The toolbar and the
 * shortcuts for bold, italic and underlined only call those commands; what
 * the browser leaves in the text is brought into the stored format when it
 * is read.
 */
export function createRichText(host, { value = '', placeholder = '', label = 'Formatted text', onChange = () => {} } = {}) {
  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
  const shortcut = (key) => ' (' + (isMac ? '⌘' : 'Ctrl+') + key + ')';

  const wrap = el('div', 'rt');
  const toolbar = el('div', 'rt-toolbar');
  toolbar.setAttribute('role', 'toolbar');
  toolbar.setAttribute('aria-label', 'Formatting');
  const editor = el('div', 'rt-editor richtext scroll-slim');
  editor.contentEditable = 'true';
  editor.setAttribute('role', 'textbox');
  editor.setAttribute('aria-multiline', 'true');
  editor.setAttribute('aria-label', label);
  editor.dataset.placeholder = placeholder;
  editor.spellcheck = true;

  // ---- Toolbar ------------------------------------------------------------------
  function button(className, title, iconClass) {
    const b = el('button', className);
    b.type = 'button';
    b.title = title;
    b.setAttribute('aria-label', title.replace(/ \(.*\)$/, ''));
    if (iconClass) { const i = el('i', iconClass); i.setAttribute('aria-hidden', 'true'); b.appendChild(i); }
    return b;
  }
  /** Buttons that show whether their formatting applies at the cursor: command → button. */
  const toggles = new Map();
  [['bold', 'fas fa-bold', 'Bold' + shortcut('B')], ['italic', 'fas fa-italic', 'Italic' + shortcut('I')],
    ['underline', 'fas fa-underline', 'Underlined' + shortcut('U')], null,
    ['insertUnorderedList', 'fas fa-list-ul', 'Bulleted list'], ['insertOrderedList', 'fas fa-list-ol', 'Numbered list'], null,
  ].forEach((def) => {
    if (!def) { toolbar.appendChild(el('span', 'rt-sep')); return; }
    const b = button('rt-btn', def[2], def[1]);
    b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', () => exec(def[0]));
    toggles.set(def[0], b);
    toolbar.appendChild(b);
  });

  // Text size: a small menu, each entry in its own size.
  const sizeWrap = el('div', 'rt-menu-wrap');
  const sizeBtn = button('rt-btn rt-menu-btn', 'Text size');
  const sizeLabel = el('span', 'rt-size-label', RICHTEXT_SIZES.normal.label);
  sizeBtn.append(sizeLabel, el('i', 'fas fa-caret-down'));
  sizeBtn.setAttribute('aria-haspopup', 'true');
  const sizeMenu = el('div', 'rt-menu rt-size-menu');
  sizeMenu.setAttribute('role', 'menu');
  const sizeItems = new Map();
  Object.keys(RICHTEXT_SIZES).forEach((key) => {
    const item = el('button', 'rt-menu-item');
    item.type = 'button';
    item.setAttribute('role', 'menuitemradio');
    const name = el('span', null, RICHTEXT_SIZES[key].label);
    name.style.fontSize = RICHTEXT_SIZES[key].px + 'px';
    item.append(el('i', 'fas fa-check'), name);
    item.addEventListener('click', () => { closeMenus(); exec('fontSize', RICHTEXT_SIZES[key].legacy); showSize(key); });
    sizeItems.set(key, item);
    sizeMenu.appendChild(item);
  });
  sizeWrap.append(sizeBtn, sizeMenu);
  toolbar.appendChild(sizeWrap);

  // Text color: a palette, and the browser's own color picker for anything else.
  const colorWrap = el('div', 'rt-menu-wrap');
  const colorBtn = button('rt-btn rt-menu-btn', 'Text color');
  const colorMark = el('span', 'rt-color-mark', 'A');
  colorBtn.append(colorMark, el('i', 'fas fa-caret-down'));
  colorBtn.setAttribute('aria-haspopup', 'true');
  const colorMenu = el('div', 'rt-menu rt-color-menu');
  function swatch(name, color) {
    const s = button('rt-swatch', name);
    s.style.setProperty('--swatch', color);
    s.dataset.color = color;
    s.addEventListener('click', () => { closeMenus(); exec('foreColor', color); showColor(color); });
    return s;
  }
  let shownColor = RICHTEXT_DEFAULT_COLOR; // the color the toolbar shows for the selection
  const swatches = el('div', 'rt-swatches');
  PALETTE.forEach(([name, color]) => swatches.appendChild(swatch(name, color)));
  // Colors of the text that are not in the palette (chosen with the picker
  // earlier), so that they can be used again without finding them a second time.
  const usedSwatches = el('div', 'rt-swatches rt-swatches-used');
  function refreshUsedColors() {
    const found = [];
    const re = /color:(#[0-9a-f]{6})/g;
    const html = sanitizeRichText(editor.innerHTML);
    let m;
    while ((m = re.exec(html)) && found.length < 5) {
      if (!found.includes(m[1]) && !PALETTE.some(([, color]) => color === m[1])) found.push(m[1]);
    }
    usedSwatches.textContent = '';
    found.forEach((color) => usedSwatches.appendChild(swatch('Color used in this text', color)));
    usedSwatches.classList.toggle('hidden', !found.length);
    showColor(shownColor);
  }
  const custom = el('label', 'rt-custom');
  const customInput = el('input');
  customInput.type = 'color';
  customInput.value = '#4f46e5';
  customInput.setAttribute('aria-label', 'Another color');
  custom.append(customInput, el('span', null, 'Another color…'));
  // The picker reports every color while it is open ("input") and once more
  // when it closes ("change"). Each one is put on the selection at once, while
  // the selection is still the one the color is meant for.
  let customApplied = null;
  function applyCustom(closing) {
    const color = customInput.value;
    if (color !== customApplied) {
      exec('foreColor', color, { keepFocus: true });
      customApplied = color;
      showColor(color);
    }
    if (closing) closeMenus();
  }
  customInput.addEventListener('input', () => applyCustom(false));
  customInput.addEventListener('change', () => applyCustom(true));
  colorMenu.append(swatches, usedSwatches, custom);
  colorWrap.append(colorBtn, colorMenu);
  toolbar.appendChild(colorWrap);

  toolbar.appendChild(el('span', 'rt-sep'));
  const clearBtn = button('rt-btn', 'Remove the formatting of the selected text', 'fas fa-eraser');
  clearBtn.addEventListener('click', () => exec('removeFormat'));
  toolbar.appendChild(clearBtn);

  // A click on the toolbar must not take the selection out of the text. (The
  // color picker is a form field of its own and needs its click.)
  toolbar.addEventListener('mousedown', (e) => { if (!custom.contains(e.target)) e.preventDefault(); });

  // ---- Menus --------------------------------------------------------------------
  const menus = [[sizeBtn, sizeMenu], [colorBtn, colorMenu]];
  function closeMenus() {
    menus.forEach(([btn, menu]) => { menu.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); });
  }
  menus.forEach(([btn, menu]) => {
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', () => {
      const show = !menu.classList.contains('open');
      closeMenus();
      if (show && menu === colorMenu) {
        refreshUsedColors();
        customApplied = null;
      }
      menu.classList.toggle('open', show);
      btn.setAttribute('aria-expanded', String(show));
    });
  });
  document.addEventListener('mousedown', (e) => { if (!sizeWrap.contains(e.target) && !colorWrap.contains(e.target)) closeMenus(); });
  wrap.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenus(); });

  // ---- Selection ------------------------------------------------------------------
  const inside = (node) => !!node && (node === editor || editor.contains(node));
  /** The last selection inside the text, for commands that are called while the focus is elsewhere. */
  let savedRange = null;
  document.addEventListener('selectionchange', () => {
    const sel = document.getSelection();
    if (!sel || !sel.rangeCount || !inside(sel.anchorNode) || !inside(sel.focusNode)) return;
    savedRange = sel.getRangeAt(0).cloneRange();
    refreshToolbar();
  });
  /** Put the selection back into the text when it is somewhere else. */
  function selectInEditor(takeFocus) {
    const sel = document.getSelection();
    const there = !!sel && sel.rangeCount > 0 && inside(sel.anchorNode) && inside(sel.focusNode);
    if (takeFocus && document.activeElement !== editor) editor.focus({ preventScroll: true });
    if (there || !sel) return;
    let range = savedRange;
    if (!range || !inside(range.startContainer) || !inside(range.endContainer)) {
      range = document.createRange();
      range.selectNodeContents(editor);
      range.collapse(false);
    }
    sel.removeAllRanges();
    sel.addRange(range);
  }

  /**
   * Run one of the browser's editing commands on the selection. keepFocus: the
   * focus stays where it is (the color picker must not lose it while it is open).
   */
  function exec(command, argument, { keepFocus = false } = {}) {
    const focused = document.activeElement;
    selectInEditor(!keepFocus);
    const before = editor.innerHTML;
    try {
      document.execCommand('styleWithCSS', false, false); // tags, not style attributes: the same in every browser
      document.execCommand(command, false, argument === undefined ? null : argument);
    } catch (_) { /* a browser without this command: nothing changes */ }
    // A command moves the focus into the text; give it back where it was wanted.
    if (keepFocus && focused && focused !== document.activeElement && typeof focused.focus === 'function') {
      focused.focus({ preventScroll: true });
    }
    refreshToolbar();
    if (editor.innerHTML !== before) changed();
  }

  // ---- State of the toolbar ---------------------------------------------------------
  function showSize(key) {
    sizeLabel.textContent = RICHTEXT_SIZES[key].label;
    sizeItems.forEach((item, k) => {
      item.classList.toggle('current', k === key);
      item.setAttribute('aria-checked', String(k === key));
    });
  }
  function showColor(color) {
    shownColor = color;
    colorMark.style.setProperty('--mark', color);
    colorMenu.querySelectorAll('.rt-swatch').forEach((s) => s.classList.toggle('current', s.dataset.color === color));
  }
  /** Where the selection starts to cover text (for a cursor: where it stands). */
  function startOfSelection(range) {
    if (range.collapsed) return range.startContainer;
    const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
      const from = node === range.startContainer ? range.startOffset : 0;
      const to = node === range.endContainer ? range.endOffset : node.length;
      if (to > from && range.intersectsNode(node)) return node; // at least one of its characters is selected
    }
    return range.startContainer;
  }
  /** The size and the color of the text at the selection. */
  function formatAtSelection() {
    const sel = document.getSelection();
    const range = sel && sel.rangeCount ? sel.getRangeAt(0) : null;
    let size = null;
    let color = null;
    if (range && range.collapsed) {
      // With only a cursor, the browser knows how the next typed text will look,
      // including a size or color that was chosen but not used yet.
      try {
        const s = String(document.queryCommandValue('fontSize') || '');
        size = parseLegacySize(s) || parseSize(lower(trimWs(s)));
        color = parseColor(lower(trimWs(String(document.queryCommandValue('foreColor') || ''))));
      } catch (_) { /* read from the elements below */ }
    }
    // Otherwise, and where the browser gave no answer: from the elements around the text.
    let node = range ? startOfSelection(range) : null;
    for (; node && node !== editor && inside(node) && (size === null || color === null); node = node.parentNode) {
      if (node.nodeType !== 1) continue;
      if (size === null) {
        size = node.nodeName === 'FONT' && node.hasAttribute('size') ? parseLegacySize(node.getAttribute('size')) : null;
        if (size === null && node.style && node.style.fontSize) size = parseSize(lower(node.style.fontSize));
      }
      if (color === null) {
        color = node.nodeName === 'FONT' && node.hasAttribute('color') ? parseColor(lower(trimWs(node.getAttribute('color')))) : null;
        if (color === null && node.style && node.style.color) color = parseColor(lower(node.style.color));
      }
    }
    return { size: size || 'normal', color: !color || isDefaultColor(color) ? RICHTEXT_DEFAULT_COLOR : color };
  }
  function refreshToolbar() {
    const sel = document.getSelection();
    if (!sel || !sel.rangeCount || !inside(sel.anchorNode)) return;
    toggles.forEach((btn, command) => {
      let on = false;
      try { on = document.queryCommandState(command); } catch (_) { /* unknown command */ }
      btn.classList.toggle('active', on);
      btn.setAttribute('aria-pressed', String(on));
    });
    const at = formatAtSelection();
    showSize(at.size);
    showColor(at.color);
  }

  // ---- Content ------------------------------------------------------------------------
  /** Nothing in the editor but, at most, one empty line. */
  const isBlank = () => editor.textContent === '' && editor.children.length <= 1 && editor.querySelectorAll('li, br').length <= 1;
  function refreshEmpty() {
    editor.classList.toggle('is-empty', isBlank());
  }
  function changed() {
    refreshEmpty();
    onChange();
  }
  editor.addEventListener('input', (e) => {
    // After the last text was deleted, the browser would write the next text in
    // the formatting of the deleted one, although the empty editor shows nothing
    // of it. Placing the cursor anew lets an empty editor start plain. (The
    // content is not touched, so undo still brings the text back.)
    if (String(e.inputType || '').startsWith('delete') && isBlank()) {
      const sel = document.getSelection();
      if (sel && sel.rangeCount && inside(sel.anchorNode)) {
        const start = document.createRange();
        start.selectNodeContents(editor);
        start.collapse(true);
        sel.removeAllRanges();
        sel.addRange(start);
      }
    }
    changed();
  });
  editor.addEventListener('keyup', refreshToolbar);

  // Shortcuts: ⌘B, ⌘I, ⌘U on a Mac, Ctrl+B, Ctrl+I, Ctrl+U elsewhere. With text
  // selected they format it; with only a cursor they switch the formatting on
  // or off for what is typed next. They are handled here instead of being left
  // to the browser, so that they work the same everywhere (browsers give some
  // of these keys to their own menus) and the toolbar shows the new state at once.
  editor.addEventListener('keydown', (e) => {
    const command = shortcutCommand(e, isMac);
    if (!command) return;
    e.preventDefault();
    if (!e.repeat) exec(command); // a key held down toggles once
  });

  /** Insert cleaned HTML at the selection. */
  function insertHtml(html) {
    selectInEditor(true);
    let done = false;
    try { done = document.execCommand('insertHTML', false, html); } catch (_) { /* fall back below */ }
    if (!done) {
      const sel = document.getSelection();
      if (!sel || !sel.rangeCount) return;
      const range = sel.getRangeAt(0);
      range.deleteContents();
      const holder = document.createElement('template');
      holder.innerHTML = html;
      const last = holder.content.lastChild;
      range.insertNode(holder.content);
      if (last) {
        range.setStartAfter(last);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
      }
    }
    changed();
  }

  // Pasted content is cleaned before it enters the text: the structure and the
  // basic formatting stay, everything else of the other program goes.
  editor.addEventListener('paste', (e) => {
    const data = e.clipboardData;
    if (!data) return; // the browser pastes; the text is cleaned when it is read
    e.preventDefault();
    let clean = '';
    const html = data.getData('text/html');
    if (html && html.length < 500000) {
      let body = html;
      try { body = new DOMParser().parseFromString(html, 'text/html').body.innerHTML; } catch (_) { /* cleaned as it is */ }
      clean = sanitizeRichText(body, { paste: true });
    }
    if (richTextIsEmpty(clean)) clean = plainTextToRichText(data.getData('text/plain'));
    if (clean === '') return;
    // One paragraph is text for the current line, not a new paragraph. The
    // cleaning trimmed its ends; a space the copied text began or ended with stays.
    if (clean.startsWith('<p>') && clean.indexOf('</p>') === clean.length - 4) {
      const plain = data.getData('text/plain');
      clean = (/^[ \t]/.test(plain) ? ' ' : '') + clean.slice(3, -4) + (/[ \t]$/.test(plain) ? ' ' : '');
    }
    insertHtml(clean);
  });
  // Text dragged in from elsewhere would skip the cleaning; moving text inside the editor is fine.
  let ownDrag = false;
  editor.addEventListener('dragstart', () => { ownDrag = true; });
  editor.addEventListener('dragend', () => { ownDrag = false; });
  editor.addEventListener('drop', (e) => { if (!ownDrag) e.preventDefault(); });

  function setHTML(html) {
    editor.innerHTML = sanitizeRichText(html);
    savedRange = null;
    refreshEmpty();
  }
  function getHTML() {
    const html = sanitizeRichText(editor.innerHTML);
    return richTextIsEmpty(html) ? '' : html;
  }

  wrap.append(toolbar, editor);
  host.appendChild(wrap);
  setHTML(value);
  showSize('normal');
  showColor(RICHTEXT_DEFAULT_COLOR);

  return {
    getHTML,
    setHTML,
    isEmpty: () => richTextIsEmpty(sanitizeRichText(editor.innerHTML)),
    focus: () => editor.focus({ preventScroll: true }),
    element: editor,
  };
}
