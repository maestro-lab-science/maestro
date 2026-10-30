/**
 * segments.test.mjs — unit tests for conversation segments on the server side
 * (includes/segments.php, the director prompt in includes/director.php and the
 * script assistant's handling in includes/assist.php), run through the PHP CLI.
 *
 *   node --test app/tests
 *
 * Skipped when no `php` binary is on the PATH. No database is needed: the
 * prompt is built from a project array.
 *
 * @author Marc Becker
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const INCLUDES = fileURLToPath(new URL('../includes/', import.meta.url));
let hasPhp = true;
try { execFileSync('php', ['-v'], { stdio: 'ignore' }); } catch (_) { hasPhp = false; }
const skip = !hasPhp && 'php not installed';

/** Run a PHP snippet with assist.php (and so director.php and segments.php) loaded; returns its JSON output. */
function php(code, input) {
  const script = 'require ' + JSON.stringify(INCLUDES + 'assist.php') + ';' +
    '$in = json_decode(stream_get_contents(STDIN), true);' + code;
  return JSON.parse(execFileSync('php', ['-r', script], { input: JSON.stringify(input ?? null), encoding: 'utf8' }));
}

const TEAM = JSON.stringify([
  { id: 'bot1', name: 'Blue', color: '#3b82f6' },
  { id: 'bot2', name: 'Green', color: '#22c55e' },
  { id: 'human', name: 'Yellow', color: '#eab308' },
]);
const MESSAGES = [{ id: 1, sender: 'bot1', content: 'hi both', send_at: 3, is_empty: false }];
const plain = { director_instructions: 'Blue and Green discuss three coworking concepts.', team_json: TEAM, conversation_script: JSON.stringify({ messages: MESSAGES }) };
const divided = {
  ...plain,
  conversation_script: JSON.stringify({
    segments: [
      { start: 0, prompt: '' },
      { start: 300, prompt: 'Blue leaves for an urgent email.\nUntil the next segment Blue writes nothing.' },
      { start: 480, prompt: 'Blue comes back.' },
    ],
    messages: MESSAGES,
  }),
};
const PAYLOAD = { elapsed_seconds: 120, remaining_seconds: 480, already_said: '  +3s BLUE: hi both', script: '  +3s BLUE: hi both', plan: '  +5s GREEN: hey', human_text: 'hello' };
const prompt = (project, payload) => php('echo json_encode(director_rewrite_messages($in["project"], $in["payload"]));', { project, payload });
const normalize = (list) => php('echo json_encode(segments_normalize($in));', list);

test('segments are validated: segment 1 at 0:00, later ones in order, short prompts', { skip }, () => {
  assert.deepEqual(normalize([{ start: 0, prompt: '' }, { start: '300', prompt: ' leave ' }]), [{ start: 0, prompt: '' }, { start: 300, prompt: 'leave' }]);
  assert.deepEqual(normalize([]), []);
  assert.deepEqual(normalize([{ start: 0, prompt: 'only one' }]), [], 'a single segment is no division');
  assert.match(normalize([{ start: 60, prompt: '' }, { start: 300, prompt: '' }]), /Segment 1 has to start at 0:00/);
  assert.match(normalize([{ start: 0, prompt: '' }, { start: 300, prompt: '' }, { start: 200, prompt: '' }]), /Segment 3 has to start after segment 2 \(5:00\)/);
  assert.match(normalize([{ start: 0, prompt: '' }, { start: 300, prompt: 'x'.repeat(401) }]), /1 to 3 sentences/);
  assert.deepEqual(php('echo json_encode(segments_of_project($in));', { conversation_script: 'not json' }), [], 'a broken script counts as not divided');
});

test('without segments the prompt is the one it always was, whatever the payload says', { skip }, () => {
  const [system, user] = prompt(plain, { ...PAYLOAD, segment: 2 });
  assert.match(system.content, /for the first line, after YELLOW's message \(now\)\./);
  assert.match(system.content, /The list is the ENTIRE remaining conversation/);
  assert.doesNotMatch(user.content, /[Ss]egment/);
  assert.match(user.content, /### What was said so far \(\+Ns = seconds after the previous message\):/);
  assert.match(user.content, /### YELLOW \(the human\) just wrote:\n"hello"/);
  assert.match(user.content, /Write the entire remaining conversation from now on/);
});

test('with segments, a participant message sees every segment and plans up to the next start', { skip }, () => {
  const [system, user] = prompt(divided, PAYLOAD);
  assert.match(system.content, /up to the start of the next segment/);
  assert.match(user.content, /\(\+Ns = seconds after the previous message; after a segment divider, after that segment's start\)/);
  assert.match(user.content, /- Segment 1 · 0:00 to 5:00 · current, started 120s ago: \(no prompt\)\n- Segment 2 · 5:00 to 8:00 · starts in 180s: Blue leaves for an urgent email\. Until the next segment Blue writes nothing\.\n- Segment 3 · from 8:00 · starts in 360s: Blue comes back\./);
  assert.match(user.content, /Your instructions above still apply/);
  assert.match(user.content, /Plan only up to the start of segment 2 \(5:00, in 180s\)/);
  assert.match(user.content, /### YELLOW \(the human\) just wrote:\n"hello"/);
});

test('at a segment start the segment takes the place of a participant message', { skip }, () => {
  const [, user] = prompt(divided, { ...PAYLOAD, elapsed_seconds: 300, human_text: '', segment: 2 });
  assert.match(user.content, /- Segment 1 · 0:00 to 5:00 · past: /);
  assert.match(user.content, /- Segment 2 · 5:00 to 8:00 · current, starts now: /);
  assert.match(user.content, /### Segment 2 starts now \(5:00\):\nBlue leaves for an urgent email\.\nUntil the next segment/);
  assert.match(user.content, /Plan only up to the start of segment 3 \(8:00, in 180s\)/);
  assert.match(user.content, /the first after now\)/);
  assert.match(user.content, /start with what its prompt asks for/);
  assert.doesNotMatch(user.content, /just wrote/);
});

test('in the last segment the director plans to the end; segment 1 or an unknown number is no segment call', { skip }, () => {
  const [, late] = prompt(divided, { ...PAYLOAD, elapsed_seconds: 500 });
  assert.match(late.content, /- Segment 3 · from 8:00 · current, started 20s ago: Blue comes back\./);
  assert.match(late.content, /This is the last segment: plan to the end of the conversation\./);
  for (const segment of [1, 7, 'x']) {
    const [, user] = prompt(divided, { ...PAYLOAD, segment });
    assert.match(user.content, /just wrote:\n"hello"/, 'segment ' + segment);
  }
});

test('the script assistant keeps the segments it was not asked to change', { skip }, () => {
  const old = JSON.stringify({ segments: [{ start: 0, prompt: '' }, { start: 300, prompt: 'Blue leaves.' }], messages: MESSAGES });
  const messages = [
    { sender: 'bot1', content: 'early', send_at: 10, is_empty: false },
    { sender: 'bot2', content: 'late', send_at: 320, is_empty: false, segment: 1 }, // stated segment does not fit its time
    { sender: 'bot1', content: 'kept', send_at: 330, is_empty: false, segment: 2 },
  ];
  const run = (result) => php('$s = assist_script_segments($in["result"], $in["old"]); echo json_encode(["segments" => $s, "messages" => assist_assign_segments($in["result"]["messages"], $s)]);', { result, old });
  const kept = run({ messages });
  assert.deepEqual(kept.segments.map((s) => s.start), [0, 300], 'left out: the current segments stay');
  assert.deepEqual(kept.messages.map((m) => m.segment), [1, 1, 2], 'a stated segment counts; a message without one follows its time');
  const removed = run({ messages, segments: [] });
  assert.deepEqual(removed.segments, [], 'an empty list removes them');
  assert.ok(removed.messages.every((m) => m.segment === undefined));
});
