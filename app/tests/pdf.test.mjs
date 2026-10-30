/**
 * pdf.test.mjs — unit tests for the PDF transcripts (includes/pdf.php and
 * includes/pdf_font.php), run through the PHP CLI: the embedded fonts, the
 * map back to Unicode, the fallback for characters the fonts lack, and line
 * wrapping of long unbroken words.
 *
 *   node --test app/tests
 *
 * Skipped when no `php` binary is on the PATH. No database is needed.
 *
 * @author Marc Becker
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { inflateSync } from 'node:zlib';

const INCLUDES = fileURLToPath(new URL('../includes/', import.meta.url));
let hasPhp = true;
try { execFileSync('php', ['-v'], { stdio: 'ignore' }); } catch (_) { hasPhp = false; }
const skip = !hasPhp && 'php not installed';

/** Run a PHP snippet with pdf.php loaded; returns its standard output. */
function php(code, input) {
  const script = 'require ' + JSON.stringify(INCLUDES + 'pdf.php') + ';' +
    '$in = json_decode(stream_get_contents(STDIN), true);' + code;
  const stdin = Buffer.from(JSON.stringify(input ?? null), 'utf8'); // the PDF itself is binary: read it as latin1
  return execFileSync('php', ['-r', script], { input: stdin, maxBuffer: 64 * 1024 * 1024 }).toString('latin1');
}

const PROJECT = {
  project_id: 'TESTPROJECT00001', name: 'PDF test',
  team_json: JSON.stringify([
    { id: 'bot1', name: 'Blue', color: '#3b82f6' },
    { id: 'human', name: 'Yellow', color: '#eab308' },
  ]),
};

/** The text of the ToUnicode maps of a PDF: every character the PDF shows. */
function shownText(pdf) {
  const out = [];
  const re = /stream\n([\s\S]*?)\nendstream/g;
  let m;
  while ((m = re.exec(pdf))) {
    let body;
    try { body = inflateSync(Buffer.from(m[1], 'latin1')).toString('latin1'); } catch (_) { continue; }
    if (!body.includes('beginbfchar')) continue;
    for (const [, hex] of body.matchAll(/<[0-9A-F]{4}> <([0-9A-F]+)>/g)) out.push(Buffer.from(hex, 'hex').swap16().toString('utf16le'));
  }
  return out.join('');
}

test('a transcript embeds Noto Sans and Noto Emoji and keeps every character it can show', { skip }, () => {
  const messages = [
    { sender: 'bot1', shown_at: '2026-10-30 10:00:00.000', message_text: 'Grüße · Привет · Ελληνικά · Tiếng Việt 😀', client_id: 'a' },
    { sender: 'human', shown_at: '2026-10-30 10:00:05.000', message_text: '你好 (parentheses) \\ backslash', client_id: 'b' },
  ];
  const pdf = php('echo render_transcript_pdf($in["project"], [["conversation_id" => "TEST_pdf", "messages" => $in["messages"], "notes" => []]]);', { project: PROJECT, messages });
  assert.ok(pdf.startsWith('%PDF-1.4'));
  assert.match(pdf, /\/Subtype \/Type0 \/BaseFont \/[A-Z]{6}\+NotoSans-Regular/);
  assert.match(pdf, /\/BaseFont \/[A-Z]{6}\+NotoEmoji-Regular/);
  assert.doesNotMatch(pdf, /Helvetica/);
  const shown = shownText(pdf);
  for (const ch of ['ü', 'ß', 'П', 'λ', 'ệ', '😀', '(', '\\']) assert.ok(shown.includes(ch), 'shows ' + ch);
  assert.ok(!shown.includes('你'), 'a character neither font has is not embedded');
});

test('a character neither font has becomes "?", and steering characters are left out', { skip }, () => {
  const out = php('$w = fn($s) => round(MiniPdf::width($s, 10), 3); echo json_encode([$w("你"), $w("?"), $w("❤\u{FE0F}"), $w("❤")]);');
  const [han, question, heartWithSelector, heart] = JSON.parse(out);
  assert.equal(han, question, '"你" is as wide as "?"');
  assert.equal(heartWithSelector, heart, 'the variation selector adds nothing');
});

test('a long unbroken word wraps in pieces that fit, quickly', { skip }, () => {
  const out = php('$t = microtime(true); $lines = MiniPdf::wrap(str_repeat("ab", 10000), 300, 11); $max = max(array_map(fn($l) => MiniPdf::width($l, 11), $lines)); echo json_encode(["s" => microtime(true) - $t, "lines" => count($lines), "max" => $max, "joined" => implode("", $lines) === str_repeat("ab", 10000)]);');
  const r = JSON.parse(out);
  assert.ok(r.s < 3, 'took ' + r.s + ' s');
  assert.ok(r.lines > 10);
  assert.ok(r.max <= 300, 'every piece fits the line');
  assert.ok(r.joined, 'nothing is lost or added');
});
