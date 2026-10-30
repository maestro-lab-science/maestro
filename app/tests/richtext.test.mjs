/**
 * richtext.test.mjs — unit tests for formatted text (the instructions above
 * the chat): the cleaner in the browser (assets/js/richtext.js) and its twin
 * on the server (includes/richtext.php), run through the PHP CLI.
 *
 *   node --test app/tests
 *
 * Both sides must turn the same input into the same output, so every case
 * below runs on both, and so does a few hundred pieces of generated tag soup.
 * The PHP half is skipped when no `php` binary is on the PATH.
 *
 * @author Marc Becker
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
  sanitizeRichText, richTextIsEmpty, richTextToText, plainTextToRichText, parseColor, parseSize, parseLegacySize, shortcutCommand,
} from '../assets/js/richtext.js';

const INCLUDES = fileURLToPath(new URL('../includes/', import.meta.url));
let hasPhp = true;
try { execFileSync('php', ['-v'], { stdio: 'ignore' }); } catch (_) { hasPhp = false; }
const skip = !hasPhp && 'php not installed';

/**
 * Run a PHP snippet with richtext.php loaded; $in is the decoded input, the reply is its JSON output.
 * Every PHP warning or notice counts as a failure.
 */
function php(code, input) {
  const script = 'error_reporting(E_ALL); set_error_handler(function ($no, $text, $file, $line) { throw new ErrorException($text, 0, $no, $file, $line); });' +
    'require ' + JSON.stringify(INCLUDES + 'richtext.php') + ';' +
    '$in = json_decode(stream_get_contents(STDIN), true);' + code;
  return JSON.parse(execFileSync('php', ['-r', script], { input: JSON.stringify(input ?? null), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
}
const phpSanitize = (list) => php('echo json_encode(array_map("richtext_sanitize", $in));', list);

/** Everything the stored format may contain, and nothing else. */
const STORED_FORMAT = /^(?:<\/?(?:p|ul|ol|li|strong|em|u)>|<br>|<span style="(?:color:#[0-9a-f]{6}(?:;font-size:(?:13|18|24)px)?|font-size:(?:13|18|24)px)">|<\/span>|&(?:amp|lt|gt|nbsp);|[^<>&])*$/;

// [what it shows, input, expected output]
const CASES = [
  ['nothing', '', ''],
  ['plain text becomes a paragraph', 'Hello', '<p>Hello</p>'],
  ['blocks become paragraphs', '<div>a</div><div>b</div>', '<p>a</p><p>b</p>'],
  ['what a browser leaves after typing three lines with an empty one',
    'first line<div>second</div><div><br></div><div>third</div>', '<p>first line</p><p>second</p><p><br></p><p>third</p>'],
  ['bold, italic, underlined', '<b>bold</b> <i>it</i> <u>un</u>', '<p><strong>bold</strong> <em>it</em> <u>un</u></p>'],
  ['strong and em stay', '<p><strong>a</strong><em>b</em></p>', '<p><strong>a</strong><em>b</em></p>'],
  ['legacy font size and color', '<font size="5">Big</font> and <font color="#dc2626">red</font>',
    '<p><span style="font-size:24px">Big</span> and <span style="color:#dc2626">red</span></p>'],
  ['the innermost size wins', '<font size="5">Big <font size="3">normal</font> big</font>',
    '<p><span style="font-size:24px">Big </span>normal<span style="font-size:24px"> big</span></p>'],
  ['legacy sizes, relative sizes and a color without #', '<font size="+2" color="DC2626">x</font><font size="1">y</font><font size="3">z</font>',
    '<p><span style="color:#dc2626;font-size:24px">x</span><span style="font-size:13px">y</span>z</p>'],
  ['everything at once', '<font color="#2563eb" size="4"><b><i><u>all</u></i></b></font>',
    '<p><span style="color:#2563eb;font-size:18px"><strong><em><u>all</u></em></strong></span></p>'],
  ['Google Docs: a <b> that is not bold around spans that are',
    '<b style="font-weight:normal" id="docs-internal-guid-1"><span style="font-weight:700;color:#000000;font-size:11pt;text-decoration:none">Bold</span><span style="font-weight:400"> plain</span></b>',
    '<p><strong>Bold</strong> plain</p>'],
  ['colors in every notation; black, the default ink and transparent are no color',
    '<span style="color: rgb(220, 38, 38)">r</span><span style="color:#D00">s</span><span style="COLOR:Red">t</span><span style="color:rgba(0,0,0,0)">u</span><span style="color: rgb(15, 23, 42);">v</span><span style="color:black">w</span>',
    '<p><span style="color:#dc2626">r</span><span style="color:#dd0000">s</span><span style="color:#ff0000">t</span>uvw</p>'],
  ['sizes in every unit snap to the four sizes',
    '<span style="font-size:10pt">a</span><span style="font-size:14pt">b</span><span style="font-size: x-large">c</span><span style="font-size:120%">d</span><span style="font-size:0.8em">e</span><span style="font-size:15px !important">f</span>',
    '<p>a<span style="font-size:18px">b</span><span style="font-size:24px">c</span><span style="font-size:18px">d</span><span style="font-size:13px">e</span>f</p>'],
  ['an underline can only be taken back by the element that drew it',
    '<u>a<span style="text-decoration:none">b</span></u><u style="text-decoration: none">c</u><span style="text-decoration: underline dotted">d</span>',
    '<p><u>ab</u>c<u>d</u></p>'],
  ['shared formatting is written once', '<b><i>bi</i></b><i>i</i><b>b</b>', '<p><strong><em>bi</em></strong><em>i</em><strong>b</strong></p>'],
  ['line breaks', 'a<br>b<br><br>c', '<p>a<br>b<br><br>c</p>'],
  ['a line break stays in formatting that continues', '<b>a<br>b</b>', '<p><strong>a<br>b</strong></p>'],
  ['a line break leaves formatting that ends', '<b>a</b><br>b', '<p><strong>a</strong><br>b</p>'],
  ['self-closing tags', '<br/>a<br />b<svg/>c', '<p><br>a<br>bc</p>'],
  ['a single line break after the last text is a placeholder and goes; an empty last line stays',
    '<div>a<br></div><div>b<br><br></div><ul><li>c<br></li><li><b>d</b><br></li><li>e<br><br></li></ul><p><br><br></p><p>f</p>',
    '<p>a</p><p>b<br><br></p><ul><li>c</li><li><strong>d</strong></li><li>e<br><br></li></ul><p><br><br></p><p>f</p>'],
  ['what Safari leaves after turning two bold lines into a list',
    '<p><font size="4">Title</font></p><p><ul><li><b style="font-size: 18px; font-family: var(--font);">One</b><br></li><li><b style="font-size: 18px; font-family: var(--font);">Two <font color="#0d9488">colored</font></b><br></li></ul></p>',
    '<p><span style="font-size:18px">Title</span></p><ul><li><span style="font-size:18px"><strong>One</strong></span></li><li><span style="font-size:18px"><strong>Two </strong></span><span style="color:#0d9488;font-size:18px"><strong>colored</strong></span></li></ul>'],
  ['lists', '<ul><li>one</li><li>two</li></ul><ol><li>1</li></ol>', '<ul><li>one</li><li>two</li></ul><ol><li>1</li></ol>'],
  ['paragraphs inside an item become lines', '<ol><li><p>a</p><p>b</p></li><li><div>c</div></li></ol>', '<ol><li>a<br>b</li><li>c</li></ol>'],
  ['an empty item stays an item', '<ul><li></li><li><br></li></ul>', '<ul><li><br></li><li><br></li></ul>'],
  ['a list inside an item', '<ul><li>a<ul><li>b</li></ul>c</li></ul>', '<ul><li>a<ul><li>b</li></ul>c</li></ul>'],
  ['a list directly inside a list belongs to the item before it', '<ul><li>a</li><ul><li>b</li></ul></ul>', '<ul><li>a<ul><li>b</li></ul></li></ul>'],
  ['at most three levels of lists', '<ul><li>1<ul><li>2<ul><li>3<ul><li>4</li></ul></li></ul></li></ul></li></ul>',
    '<ul><li>1<ul><li>2<ul><li>3</li><li>4</li></ul></li></ul></li></ul>'],
  ['items without a list get one', '<li>a</li><li>b</li>text', '<ul><li>a</li><li>b</li></ul><p>text</p>'],
  ['items without end tags', '<ul><li>a<li>b</ul>', '<ul><li>a</li><li>b</li></ul>'],
  ['a browser sometimes builds a list inside a paragraph', '<p><ul><li>a</li></ul></p><div>b</div>', '<ul><li>a</li></ul><p>b</p>'],
  ['headings become bold text in a larger size', '<h1>Title</h1><h3>Sub</h3><h4>Small</h4><p>text</p>',
    '<p><span style="font-size:24px"><strong>Title</strong></span></p><p><span style="font-size:18px"><strong>Sub</strong></span></p><p><strong>Small</strong></p><p>text</p>'],
  ['table rows become lines', '<table><tr><td>a</td><td>b</td></tr><tr><th>c</th></tr></table>', '<p>a b</p><p><strong>c</strong></p>'],
  ['scripts, pictures, links and event handlers do not get through',
    '<img src=x onerror=alert(1)><script>alert(1)</script><p onclick="x()">hi <a href="javascript:alert(1)">link</a></p><style>p{}</style><iframe src="//x"></iframe>',
    '<p>hi link</p>'],
  ['a script that is never closed drops the rest', 'a<script>alert(1)', '<p>a</p>'],
  ['dropped elements go with everything inside', '<svg><script>x</script><text>t</text><svg>y</svg>z</svg>after<button>b</button>', '<p>after</p>'],
  ['end tags of scripts are found whatever their case', 'a<SCRIPT>x</Script >b<scripty>c</scripty>', '<p>abc</p>'],
  ['hidden elements are dropped', 'a<span style="display:none">hidden<span>x</span></span>b', '<p>ab</p>'],
  ['entities', 'a &lt; b &amp;&amp; c &gt; d &nbsp; e &quot;q&quot; &#39;s&#39; &#x41; &eacute; &#0;',
    '<p>a &lt; b &amp;&amp; c &gt; d &nbsp; e "q" \'s\' A &amp;eacute; \uFFFD</p>'],
  ['a "<" that starts no tag is text', 'a < b and 1<2', '<p>a &lt; b and 1&lt;2</p>'],
  ['an escaped tag stays text', '&lt;script&gt;alert(1)&lt;/script&gt;', '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'],
  ['white space collapses and is trimmed at the edges of lines', '  <p>  a   b  </p>\n\n<p> c <br> d </p>\t ', '<p>a b</p><p>c<br>d</p>'],
  ['no double space across formatting', 'a <b> b </b> c', '<p>a <strong>b </strong>c</p>'],
  ['empty paragraphs at the start and the end are removed', '<div><br></div><div>a</div><div><br></div><div><br></div>', '<p>a</p>'],
  ['only empty lines', '<div><br></div><p></p><div>  </div>', ''],
  ['a non-breaking space is text', '<p>&nbsp;</p>', '<p>&nbsp;</p>'],
  ['control and zero-width characters go', 'a\u200Bb\u0007c\uFEFFd\u0085e', '<p>abcde</p>'],
  ['other scripts and emoji stay', 'Hi 👋 שלום 你好 naïve', '<p>Hi 👋 שלום 你好 naïve</p>'],
  ['wrong nesting', '<b>a<i>b</b>c</i>d', '<p><strong>a<em>b</em></strong>cd</p>'],
  ['comments, doctype and CDATA', '<!DOCTYPE html><!-- c --><p>a</p><![CDATA[x]]><?php echo 1 ?>', '<p>a</p>'],
  ['an unfinished comment drops the rest', 'a<!-- b', '<p>a</p>'],
  ['a ">" inside an attribute', '<span title="a>b" style="color:#dc2626">x</span>', '<p><span style="color:#dc2626">x</span></p>'],
  ['style values in single quotes and with entities', "<span style='font-family:&quot;Times&quot;;color:#16a34a'>x</span>", '<p><span style="color:#16a34a">x</span></p>'],
  ['the first of two style attributes counts', '<span style="color:#dc2626" style="color:#2563eb">x</span>', '<p><span style="color:#dc2626">x</span></p>'],
  ['unknown values change nothing', '<span style="color:url(x);font-size:calc(1px);font-weight:900px">x</span>', '<p>x</p>'],
  ['small and big', '<small>s</small><big>b</big>', '<p><span style="font-size:13px">s</span><span style="font-size:18px">b</span></p>'],
  ['an unfinished tag at the end', 'a<span style="color:#dc2626', '<p>a</p>'],
  ['names of built-in object properties are ordinary tags', '<constructor>a</constructor><hasOwnProperty>b</hasOwnProperty><toString style="color:constructor">c</toString>', '<p>abc</p>'],
  ['a tag name starts with a letter, anything else is text', '<__proto__>a</__proto__><1>b', '<p>&lt;__proto__&gt;a&lt;1&gt;b</p>'],
];

test('the cleaner turns browser and foreign HTML into the stored format', () => {
  for (const [what, input, expected] of CASES) {
    assert.equal(sanitizeRichText(input), expected, what);
    assert.match(expected, STORED_FORMAT, what);
  }
});

test('cleaning cleaned text changes nothing', () => {
  for (const [what, , expected] of CASES) {
    assert.equal(sanitizeRichText(expected), expected, what);
  }
});

test('the PHP cleaner gives the same results (includes/richtext.php)', { skip }, () => {
  const out = phpSanitize(CASES.map(([, input]) => input));
  CASES.forEach(([what, , expected], i) => assert.equal(out[i], expected, what));
  const again = phpSanitize(CASES.map(([, , expected]) => expected));
  CASES.forEach(([what, , expected], i) => assert.equal(again[i], expected, what + ' (cleaned twice)'));
});

// ---- Generated tag soup --------------------------------------------------------------
// A fixed pseudo-random sequence, so that a failure can be reproduced.
function soup(count) {
  let seed = 20261001;
  const rand = (n) => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed % n; };
  const pick = (list) => list[rand(list.length)];
  const TAGS = ['p', 'div', 'b', 'strong', 'i', 'em', 'u', 'span', 'font', 'ul', 'ol', 'li', 'h1', 'h3', 'table', 'tr', 'td', 'th', 'a',
    'small', 'big', 'blockquote', 'pre', 'ins', 'section', 'P', 'DIV', 'Span', 'o:p', 'x-y', 'center', 'dl', 'dd', 's', 'sub', 'code'];
  const DROPPED = ['script', 'style', 'svg', 'button', 'head', 'title', 'textarea', 'noscript', 'template', 'select'];
  const STYLES = ['color:#dc2626', 'color: rgb(37, 99, 235)', 'color:rgb(0 128 0 / 50%)', 'COLOR:RED', 'color:#0f172a', 'color:#fff8', 'color:#12345',
    'font-size:18px', 'font-size:9pt', 'font-size: 2em', 'font-size:large', 'font-size:15px', 'font-size:0', 'font-size:1e3px',
    'font-weight:bold', 'font-weight:400', 'font-weight: 700 !important', 'font-style:italic', 'font-style:normal',
    'text-decoration:underline', 'text-decoration: none', 'text-decoration-line:underline overline', 'display:none', 'display: block',
    'background:url("a;b:c")', 'mso-list:l0 level1 lfo1', '', ';;', 'color', ':', 'font-size:;color:#16a34a'];
  const TEXTS = ['hello', ' world ', 'a  b', '\n', ' ', '\t x', '&amp;', '&lt;b&gt;', '&nbsp;', '&nbsp; &nbsp;', '&#128512;', '&#x3C;', '&bogus;', '&', '<', '>', '< ',
    '<3', '"quoted"', "it's", 'ünï©ödé', '👍🏽', '\u200B', '\u00A0', 'a\u0000b', '1 < 2 > 0', '</', '<!', 'x=y', '--', ']]>', '.'];
  const attr = () => {
    const kind = rand(8);
    if (kind === 0) return ' style="' + pick(STYLES) + ';' + pick(STYLES) + '"';
    if (kind === 1) return " style='" + pick(STYLES) + "'";
    if (kind === 2) return ' size="' + pick(['1', '3', '5', '7', '+1', '-2', '9', 'x', '']) + '"';
    if (kind === 3) return ' color=' + pick(['red', '#2563eb', '2563EB', '"#dc2626"', 'nope']);
    if (kind === 4) return ' onclick="alert(1)" href="javascript:x" class=\'a>b\'';
    if (kind === 5) return ' STYLE = "' + pick(STYLES) + '"';
    return '';
  };
  const out = [];
  for (let c = 0; c < count; c++) {
    let html = '';
    const parts = 3 + rand(22);
    for (let k = 0; k < parts; k++) {
      const kind = rand(20);
      if (kind < 6) html += '<' + pick(TAGS) + attr() + attr() + (rand(12) === 0 ? '/' : '') + '>';
      else if (kind < 10) html += '</' + pick(TAGS) + (rand(6) === 0 ? ' ' : '') + '>';
      else if (kind === 10) html += '<br' + pick(['', '/', ' /', ' style="display:none"']) + '>';
      else if (kind === 11) html += pick(['<!-- note -->', '<!--', '<!DOCTYPE html>', '<?xml version="1.0"?>', '<hr>', '<img src="x" onerror="a()">']);
      else if (kind === 12) { const d = pick(DROPPED); html += '<' + d + attr() + '>' + pick(TEXTS) + '<b>in</b>' + (rand(4) === 0 ? '' : '</' + d + '>'); }
      else html += pick(TEXTS);
    }
    out.push(html);
  }
  return out;
}

test('generated tag soup: always the stored format, stable when cleaned twice', () => {
  for (const html of soup(600)) {
    const clean = sanitizeRichText(html);
    assert.match(clean, STORED_FORMAT, JSON.stringify(html));
    assert.equal(sanitizeRichText(clean), clean, JSON.stringify(html));
  }
});

test('generated tag soup: PHP and JavaScript agree', { skip }, () => {
  const inputs = soup(600);
  const out = phpSanitize(inputs);
  inputs.forEach((html, i) => assert.equal(out[i], sanitizeRichText(html), JSON.stringify(html)));
});

// ---- Pasted content -------------------------------------------------------------------

test('pasted text that would be unreadable on white keeps the default color', () => {
  const html = '<span style="color:#ffffff">w</span><span style="color:#d4d4d4">g</span><font color="#fefefe">f</font><span style="color:#dc2626">r</span>';
  assert.equal(sanitizeRichText(html, { paste: true }), '<p>wgf<span style="color:#dc2626">r</span></p>');
  assert.equal(sanitizeRichText(html), '<p><span style="color:#ffffff">w</span><span style="color:#d4d4d4">g</span><span style="color:#fefefe">f</span><span style="color:#dc2626">r</span></p>',
    'a color chosen in the editor is kept, whatever it is');
});

test('pasted list paragraphs of Microsoft Word become lists', () => {
  const item = (marker, text) => '<p class="MsoListParagraph" style="text-indent:-18.0pt;mso-list:l0 level1 lfo1"><!--[if !supportLists]-->' +
    '<span style="font-family:Symbol"><span style="mso-list:Ignore">' + marker + '<span style="font:7.0pt &quot;Times New Roman&quot;">&nbsp;&nbsp;&nbsp; </span></span></span><!--[endif]-->' +
    '<span lang="EN-US">' + text + '<o:p></o:p></span></p>';
  const bullets = '<p class="MsoNormal">Please:<o:p></o:p></p>' + item('·', 'read the <b>task</b>') + item('·', 'discuss it') + '<p class="MsoNormal"><o:p>&nbsp;</o:p></p><p class="MsoNormal">Thanks<o:p></o:p></p>';
  assert.equal(sanitizeRichText(bullets, { paste: true }),
    '<p>Please:</p><ul><li>read the <strong>task</strong></li><li>discuss it</li></ul><p>&nbsp;</p><p>Thanks</p>');
  assert.equal(sanitizeRichText(item('1.', 'first') + item('2.', 'second'), { paste: true }), '<ol><li>first</li><li>second</li></ol>');
  assert.equal(sanitizeRichText(item('a)', 'first') + item('b)', 'second'), { paste: true }), '<ol><li>first</li><li>second</li></ol>');
  assert.equal(sanitizeRichText(item('o', 'second level bullet'), { paste: true }), '<ul><li>second level bullet</li></ul>');
});

// ---- Helpers ---------------------------------------------------------------------------

test('empty means: nothing a reader could see', { skip }, () => {
  const cases = [['', true], ['<p><br></p>', true], ['<p>&nbsp;</p>', true], ['<ul><li><br></li></ul>', true], ['<p> &nbsp; </p><p><br></p>', true],
    ['<p>a</p>', false], ['<ul><li>x</li></ul>', false], ['<p>&lt;</p>', false]];
  for (const [html, empty] of cases) assert.equal(richTextIsEmpty(html), empty, html);
  const out = php('echo json_encode(array_map(fn($h) => [richtext_is_empty($h), richtext_text($h), richtext_render($h)], $in));', cases.map(([html]) => html));
  cases.forEach(([html, empty], i) => {
    assert.equal(out[i][0], empty, html);
    assert.equal(out[i][1], richTextToText(html), html);
    assert.equal(out[i][2], empty ? '' : html, html);
  });
});

test('the plain text of formatted text', () => {
  assert.equal(richTextToText('<p>Read the <strong>task</strong>.</p><ul><li>a &amp; b</li><li>c&nbsp;d</li></ul>'), 'Read the task . a & b c d');
});

test('plain text from the clipboard becomes one paragraph per line', () => {
  assert.equal(plainTextToRichText('a\r\n\r\nb < c & d\n  indented'), '<p>a</p><p><br></p><p>b &lt; c &amp; d</p><p>indented</p>');
  assert.equal(plainTextToRichText(''), '');
});

test('colors and sizes are read in the notations browsers and other programs use', () => {
  assert.equal(parseColor('#abc'), '#aabbcc');
  assert.equal(parseColor('#aabbccdd'), '#aabbcc');
  assert.equal(parseColor('#aabbcc00'), null);
  assert.equal(parseColor('rgb(255, 0, 128)'), '#ff0080');
  assert.equal(parseColor('rgb(100% 0% 50%)'), '#ff0080');
  assert.equal(parseColor('rgba(1, 2, 3, 0.5)'), '#010203');
  assert.equal(parseColor('rgba(1, 2, 3, 0)'), null);
  assert.equal(parseColor('navy'), '#000080');
  assert.equal(parseColor('constructor'), null);
  assert.equal(parseColor('inherit'), null);
  assert.equal(parseSize('13px'), 'small');
  assert.equal(parseSize('15px'), 'normal');
  assert.equal(parseSize('16px'), 'normal');
  assert.equal(parseSize('18px'), 'large');
  assert.equal(parseSize('24px'), 'xl');
  assert.equal(parseSize('12pt'), 'normal');
  assert.equal(parseSize('medium'), 'normal');
  assert.equal(parseSize('0px'), null);
  assert.equal(parseSize('bogus'), null);
  assert.equal(parseLegacySize('2'), 'small');
  assert.equal(parseLegacySize('4'), 'large');
  assert.equal(parseLegacySize('7'), 'xl');
  assert.equal(parseLegacySize('-1'), 'small');
  assert.equal(parseLegacySize('big'), null);
});

// ---- Shortcuts of the editor ---------------------------------------------------------

test('shortcuts: Cmd+B, Cmd+I, Cmd+U on a Mac and Ctrl+B, Ctrl+I, Ctrl+U elsewhere', () => {
  const press = (key, modifiers = {}, code) => ({
    key, code: code || 'Key' + key.toUpperCase(), metaKey: false, ctrlKey: false, altKey: false, shiftKey: false, isComposing: false, ...modifiers,
  });
  const MAC = true;
  const OTHER = false;
  assert.equal(shortcutCommand(press('b', { metaKey: true }), MAC), 'bold');
  assert.equal(shortcutCommand(press('i', { metaKey: true }), MAC), 'italic');
  assert.equal(shortcutCommand(press('u', { metaKey: true }), MAC), 'underline');
  assert.equal(shortcutCommand(press('B', { metaKey: true }), MAC), 'bold', 'with caps lock on');
  assert.equal(shortcutCommand(press('b', { ctrlKey: true }), MAC), null, 'Ctrl+B moves the cursor on a Mac');
  assert.equal(shortcutCommand(press('b', { metaKey: true, shiftKey: true }), MAC), null, 'with Shift it is another shortcut');
  assert.equal(shortcutCommand(press('b', { metaKey: true, altKey: true }), MAC), null, 'with Option it is another shortcut');
  assert.equal(shortcutCommand(press('s', { metaKey: true }), MAC), null, 'saving stays with the page');
  assert.equal(shortcutCommand(press('b'), MAC), null, 'a letter alone is text');
  assert.equal(shortcutCommand(press('b', { metaKey: true, isComposing: true }), MAC), null, 'while an input method composes a character');

  assert.equal(shortcutCommand(press('b', { ctrlKey: true }), OTHER), 'bold');
  assert.equal(shortcutCommand(press('i', { ctrlKey: true }), OTHER), 'italic');
  assert.equal(shortcutCommand(press('u', { ctrlKey: true }), OTHER), 'underline');
  assert.equal(shortcutCommand(press('b', { metaKey: true }), OTHER), null, 'the Windows key');
  assert.equal(shortcutCommand(press('b', { ctrlKey: true, altKey: true }), OTHER), null, 'AltGr types characters');

  // A keyboard without Latin letters: the key in the same position counts.
  assert.equal(shortcutCommand(press('и', { ctrlKey: true }, 'KeyB'), OTHER), 'bold');
  assert.equal(shortcutCommand(press('ш', { ctrlKey: true }, 'KeyI'), OTHER), 'italic');
  // Another Latin layout: the letter on the key counts, not its position.
  assert.equal(shortcutCommand(press('x', { ctrlKey: true }, 'KeyB'), OTHER), null);
  assert.equal(shortcutCommand(press('b', { ctrlKey: true }, 'KeyN'), OTHER), 'bold');
  assert.equal(shortcutCommand({ key: undefined, ctrlKey: true }, OTHER), null, 'an event without a key');
});
