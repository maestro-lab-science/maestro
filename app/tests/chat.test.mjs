/**
 * chat.test.mjs — unit tests for the participant-chat runtime (no browser
 * needed).
 *
 *   node --test app/tests
 *
 * They cover the parts that are hard to observe in a live chat: how director
 * lines chain from the participant's message, what happens to the plan and the
 * typing dots when the participant writes, the sender mapping for a
 * configurable team, and the incremental JSON extraction of streamed plans.
 *
 * @author Marc Becker
 */

import test from 'node:test';
import assert from 'node:assert/strict';

// config.js reads the page only inside loadConfig(); give it a minimal window.
globalThis.window = {
  CHAT_CONFIG: {
    projectId: 'TESTPROJECT000000',
    team: {
      human: { name: 'You', color: '#eab308' },
      bot1: { name: 'Anna', color: '#3b82f6' },
      bot4: { name: 'Ben', color: '#a855f7' },
    },
  },
  location: { search: '?conversationID=TEST', pathname: '/app/aiTeam.php', hash: '' },
  history: { replaceState() {} },
};

const { loadConfig, TEAM, TIMING, senderFromCode } = await import('../assets/js/chat/config.js');
const { parseScript, scriptSegments, lineFromDirector, describeLine, describeSequence, describeSegmented, lineKey } = await import('../assets/js/chat/script.js');
const { createScheduler } = await import('../assets/js/chat/scheduler.js');
const { createObjectExtractor } = await import('../assets/js/chat/director.js');
const { createTypingRegistry } = await import('../assets/js/chat/ui.js');
const { createSession } = await import('../assets/js/chat/session.js');

const config = loadConfig();

// ---- Team / sender mapping ---------------------------------------------------------

test('team ids, prompt names and sender codes follow the configured team', () => {
  assert.deepEqual(TEAM.roles, ['bot1', 'bot4']);
  assert.equal(TEAM.promptNames.bot4, 'BEN');
  assert.equal(TEAM.promptNames.human, 'YELLOW');
  assert.equal(senderFromCode('b4'), 'bot4');
  assert.equal(senderFromCode('BOT1'), 'bot1');
  assert.equal(senderFromCode('b2'), null, 'bot2 is not in this team');
  assert.match(config.conversationId, /^TEST_[a-z0-9]{8}$/);
});

test('a conversation ID from the link is kept, an unfilled placeholder gets a random ID', () => {
  const idFor = (raw) => {
    window.location.search = '?conversationID=' + encodeURIComponent(raw);
    return loadConfig().conversationId;
  };
  try {
    assert.equal(idFor('R_3kTz9QpLm2'), 'R_3kTz9QpLm2');
    assert.equal(idFor('5f8a1c2e9b'), '5f8a1c2e9b');
    for (const placeholder of ['${e://Field/ResponseID}', '${q://QID1/ChoiceTextEntryValue}', '{{%PROLIFIC_PID%}}', '{SAVEDID}']) {
      assert.match(idFor(placeholder), /^conv_[a-z0-9]{13}$/, placeholder);
    }
  } finally {
    window.location.search = '?conversationID=TEST';
  }
});

test('parseScript keeps lines of team members and drops unknown senders', () => {
  const lines = parseScript({ messages: [
    { id: 1, sender: 'bot1', content: 'hi', send_at: 5, typing_pct: 40 },
    { id: 2, sender: 'bot2', content: 'not in team', send_at: 8 },
    { id: 3, sender: 'b4', content: 'hey', send_at: 12 },
    { id: 4, sender: 'bot1', content: '', typing_start: 20, typing_end: 24, is_empty: true },
  ] });
  assert.deepEqual(lines.map((l) => [l.sender, l.sendAtMs, l.ghost]), [['bot1', 5000, false], ['bot4', 12000, false], ['bot1', 24000, true]]);
});

test('lines with a duplicate, empty or missing id get ids of their own, so the scheduler controls every one of them', () => {
  const lines = parseScript({ messages: [
    { id: 7, sender: 'bot1', content: 'first', send_at: 5 },
    { id: 7, sender: 'bot4', content: 'pasted copy', send_at: 9 },
    { id: null, sender: 'bot1', content: 'no id', send_at: 12 },
    { id: '', sender: 'bot4', content: 'empty id', send_at: 15 },
    { sender: 'bot1', content: 'missing id', send_at: 18 },
  ] });
  assert.equal(lines.length, 5);
  assert.equal(new Set(lines.map((l) => l.id)).size, 5, 'every line has an id of its own');
  assert.equal(lines[0].id, 7, 'a valid id is kept');
  const { scheduler } = fakeRuntime();
  scheduler.load(lines);
  assert.equal(scheduler.pending().length, 5, 'the scheduler knows all five lines');
  assert.equal(scheduler.dropAll().length, 5, 'and drops all five when the participant writes');
  scheduler.cancelAll('test');
});

// ---- Director lines and the prompt's pauses --------------------------------------------

test('director objects chain with pauses from the previous line', () => {
  const first = lineFromDirector({ s: 'b4', c: 'sure', p: 2, d: 1 }, 30000, 3);
  assert.equal(first.sender, 'bot4');
  assert.equal(first.sendAtMs, 32000);
  assert.equal(first.typingMs, 1000);
  assert.equal(first.epoch, 3);
  const second = lineFromDirector({ s: 'b1', c: 'ok', p: 9 }, first.sendAtMs, 3);
  assert.equal(second.sendAtMs, 41000);
  assert.equal(second.typingMs, 1000, 'no d: about a second per three words');
  assert.equal(lineFromDirector({ s: 'b1', c: 'now', d: 0 }, 5000, 1).sendAtMs, 5000, 'no p: right after the previous line');
  assert.equal(lineFromDirector({ s: 'b1', c: 'now', d: 0 }, 5000, 1).typingMs, 0, 'd = 0: no dots');
  assert.equal(lineFromDirector({ s: 'b9', c: 'nobody' }, 0, 1), null, 'not in the team');
});

test('the prompt describes every line as a pause after the previous one', () => {
  assert.equal(describeLine('bot4', 'hey', 12000, { typingMs: 2000 }), '  +12s BEN (typing 2s): hey');
  assert.equal(describeLine('bot1', '', 5000, { ghost: true, typingMs: 3000 }), '  +5s [ANNA types for 3s and sends nothing]');
  const text = describeSequence([
    { sender: 'bot1', text: 'Hello', atMs: 5000, typingMs: 2000 },
    { sender: 'human', text: 'hi', atMs: 9000 },
    { sender: 'bot4', text: 'late', atMs: 8000 }, // already overdue: no negative pause
  ], 0);
  assert.equal(text, '  +5s ANNA (typing 2s): Hello\n  +4s YELLOW: hi\n  +0s BEN: late');
});

test('lineKey ignores case, spacing and trailing punctuation', () => {
  assert.equal(lineKey('bot1', 'And zones —  quiet, collab. '), lineKey('bot1', 'and zones — quiet, collab'));
  assert.notEqual(lineKey('bot1', 'yes'), lineKey('bot4', 'yes'), 'the same words from another teammate are a different line');
});

// ---- Scheduler ----------------------------------------------------------------------

function fakeRuntime() {
  let now = 0;
  const delivered = [];
  const typingCalls = []; // every showTyping / hideTyping / fadeTyping call, in order
  const clock = { elapsedMs: () => now, set: (ms) => { now = ms; } };
  const ui = {
    showTyping: (role) => typingCalls.push(['show', role]),
    hideTyping: (role) => typingCalls.push(['hide', role]),
    fadeTyping: (role) => typingCalls.push(['fade', role]),
    hideAllTyping() {},
  };
  const log = { script() {}, event() {}, warn() {}, director() {} };
  const scheduler = createScheduler({ clock, ui, log, onDeliver: (line, atMs) => delivered.push([line.id, atMs]) });
  return { clock, scheduler, delivered, typingCalls };
}
const line = (id, sender, sendAtMs, extra = {}) => ({ id, sender, text: 'line ' + id, ghost: false, sendAtMs, typingMs: 1000, origin: 'script', epoch: 0, ...extra });

test('a line keeps its moment; a late line waits only for its own typing time', () => {
  const { clock, scheduler } = fakeRuntime();
  clock.set(20000);
  assert.equal(scheduler.scheduleLine(line(1, 'bot1', 25000)), 25000);
  assert.equal(scheduler.scheduleLine(line(2, 'bot4', 1000, { typingMs: 1000 })), 21000, 'dots for a second, then the bubble');
  assert.equal(scheduler.scheduleLine(line(3, 'bot4', 1000, { typingMs: 0 })), 20000, 'no typing time: shown right away');
  scheduler.cancelAll('test');
});

test('an interrupted teammate types again no sooner than the pause allows', () => {
  const { clock, scheduler } = fakeRuntime();
  clock.set(20000);
  scheduler.pauseTyping('bot1', 23000);
  const paused = line(1, 'bot1', 21000, { typingMs: 1000 });
  assert.equal(scheduler.scheduleLine(paused), 24000, 'dots from 23 s, bubble a second later');
  assert.equal(paused.typingStartMs, 23000);
  assert.equal(scheduler.scheduleLine(line(2, 'bot4', 21000, { typingMs: 1000 })), 21000, 'another teammate is not affected');
  scheduler.cancelAll('test');
});

test('dropAll cancels the whole plan and leaves the dots to the session', () => {
  const { clock, scheduler, typingCalls } = fakeRuntime();
  scheduler.load([line(1, 'bot1', 8000, { typingMs: 5000 }), line(2, 'bot4', 20000)]);
  clock.set(6000); // line 1 has been typing since 3 s
  assert.equal(scheduler.isTyping('bot1'), true);
  assert.equal(scheduler.dropAll().length, 2);
  assert.deepEqual(scheduler.pending(), []);
  assert.deepEqual(typingCalls.filter(([what]) => what !== 'show'), [], 'no hide, no fade from the scheduler itself');
  scheduler.cancelAll('test');
});

test('delivering a bubble keeps the dots on for a follow-up that is already being typed', () => {
  const { clock, scheduler, typingCalls } = fakeRuntime();
  scheduler.load([line(1, 'bot1', 5000, { typingMs: 1000 }), line(2, 'bot1', 8000, { typingMs: 5000 })]); // line 2 typing from 3 s
  clock.set(5000);
  scheduler.deliverOverdue();
  assert.deepEqual(typingCalls, [['hide', 'bot1'], ['show', 'bot1']]);
  scheduler.cancelAll('test');
});

test('after a hidden tab, overdue lines are shown at once with their original times', () => {
  const { clock, scheduler, delivered } = fakeRuntime();
  scheduler.load([line(1, 'bot1', 5000), line(2, 'bot4', 10000), line(3, 'bot1', 60000)]);
  clock.set(12000); // the tab was hidden; no timer fired meanwhile
  assert.equal(scheduler.deliverOverdue(), 2);
  assert.deepEqual(delivered, [[1, 5000], [2, 10000]], 'stamped with the planned times, not "now"');
  assert.deepEqual(scheduler.pending().map((l) => l.id), [3], 'future lines keep their schedule');
  assert.equal(scheduler.deliverOverdue(), 0);
  scheduler.cancelAll('test');
});

test('a typing row that is fading out is taken back instead of doubled', async () => {
  const mounted = [];
  const unmounted = [];
  const typing = createTypingRegistry({
    mount: (role) => { const row = { role }; mounted.push(row); return row; },
    unmount: (row) => unmounted.push(row),
  });
  typing.show('bot1');
  assert.deepEqual(typing.roles(), ['bot1']);
  typing.fade('bot1', 30);
  assert.deepEqual(typing.roles(), [], 'a fading row no longer counts as typing');
  typing.show('bot1'); // during the fade: the same teammate types again
  assert.deepEqual(typing.roles(), ['bot1']);
  await new Promise((r) => setTimeout(r, 60));
  assert.equal(mounted.length, 1, 'one row for one color');
  assert.equal(unmounted.length, 0, 'the fade was cancelled');
  typing.hide('bot1');
  assert.deepEqual(unmounted, mounted);

  typing.show('bot1');
  typing.fade('bot1', 10);
  await new Promise((r) => setTimeout(r, 40));
  assert.equal(unmounted.length, 2, 'an undisturbed fade removes the row');
  typing.show('bot1');
  assert.equal(mounted.length, 3, 'after the fade a new row is needed again');
  typing.hideAll();
  assert.equal(unmounted.length, 3);
});

// ---- Session ---------------------------------------------------------------------------

/** A whole session with fakes around it: a settable clock, a recording ui/log, and the given director. */
async function fakeSession({ script, director, segments = null }) {
  let now = 0;
  let send = null;
  let onReturn = null;
  const typingCalls = [];
  const events = [];
  const savedEvents = [];
  const typing = new Set();
  const clock = { start() {}, elapsedMs: () => now, dateAt: (ms) => new Date(ms), onReturnFromHidden: (cb) => { onReturn = cb; }, set: (ms) => { now = ms; } };
  const ui = {
    onSend: (cb) => { send = cb; }, addMessage() {}, disableInput() {},
    showTyping: (r) => { typing.add(r); typingCalls.push(['show', r]); },
    hideTyping: (r) => { typing.delete(r); typingCalls.push(['hide', r]); },
    fadeTyping: (r, ms) => { typing.delete(r); typingCalls.push(['fade', r, ms]); },
    hideAllTyping: () => typing.clear(),
    typingRoles: () => [...typing],
    readyGate: { present: false, onReady: (cb) => cb(), release() {} },
    timer: { start() {}, remainingMs: () => Infinity },
  };
  const record = (what, data) => events.push([what, data]);
  const log = { script: record, event: record, director: record, warn: record };
  const saves = { save: () => 'cid1', event: (e) => savedEvents.push(e), flush() {} };
  const config = {
    script: segments ? { segments: segments.map((start) => ({ start })), messages: script } : { messages: script },
    hasDirector: true, readyModal: false, readyWaitMs: 0, timerEnabled: false, timerDurationMs: 0,
    lockInputAtTimerEnd: false, maxMessages: 200, conversationId: 'TEST_unit', projectId: 'TESTPROJECT000000',
  };
  let session = null;
  const scheduler = createScheduler({ clock, ui, log, onDeliver: (line, atMs) => session.deliverLine(line, atMs) });
  session = createSession({ config, ui, clock, log, scheduler, director, saves });
  session.start();
  await new Promise((r) => setTimeout(r, 5)); // start() loads the script on the next tick
  return {
    clock, scheduler, ui, typingCalls, events, savedEvents,
    send: (text) => send(text, 0),
    returnFromHidden: (hiddenMs) => onReturn(hiddenMs),
    names: () => events.map(([w]) => w),
    end: () => session.end('test'), // clears every timer, so the test process can exit
  };
}

/** A script of n lines, one every 15 s from 25 s on, alternating bot1 / bot4. */
function longScript(n = 20) {
  const script = [];
  for (let i = 1; i <= n; i++) script.push({ id: i, sender: i % 2 ? 'bot1' : 'bot4', content: 'script line ' + i, send_at: 10 + i * 15 });
  return script;
}

test('a message drops the plan and asks for the whole remaining conversation; lines chain from the message', async () => {
  let payload = null;
  const director = {
    abortCurrent() {},
    requestRewrite(p, h) {
      payload = p;
      h.onLine({ s: 'b4', c: 'reaction one', p: 2, d: 1 });
      h.onLine({ s: 'b1', c: 'reaction two', p: 9, d: 3 });
      h.onDone({ lines: 2 });
    },
  };
  const s = await fakeSession({ director, script: longScript() });
  s.clock.set(20000);
  s.send('what do you think?');
  assert.match(payload.already_said, /\+20s YELLOW: what do you think\?$/);
  assert.match(payload.script, /^ {2}\+25s ANNA \(typing 1s\): script line 1\n {2}\+15s BEN/, 'the whole script, as pauses');
  assert.match(payload.plan, /^ {2}\+5s ANNA \(typing 1s\): script line 1\n {2}\+15s BEN/, 'the unsent plan, from the message');
  assert.equal(payload.human_text, 'what do you think?');
  assert.deepEqual(s.scheduler.pending().map((l) => [l.text, l.plannedMs]), [['reaction one', 22000], ['reaction two', 31000]], 'the old plan is gone; pauses chain from the message');
  assert.ok(s.names().includes('plan-dropped'));
  assert.ok(s.names().includes('plan-complete'));
  s.end();
});

test('a slow reply: the first line shows after its typing time, the pauses after it stay', async () => {
  let s = null;
  const director = {
    abortCurrent() {},
    requestRewrite(p, h) {
      s.clock.set(26000); // the model took 6 s
      h.onLine({ s: 'b4', c: 'a', p: 2, d: 1 });
      h.onLine({ s: 'b1', c: 'b', p: 9, d: 1 });
      h.onDone({ lines: 2 });
    },
  };
  s = await fakeSession({ director, script: longScript() });
  s.clock.set(20000);
  s.send('hm');
  assert.deepEqual(s.scheduler.pending().map((l) => [l.text, l.plannedMs]), [['a', 27000], ['b', 36000]], 'a second of dots first, then the chain');
  s.end();
});

test('an interrupted typist pauses: the dots go after 1 to 3 s and nobody else starts typing', async () => {
  const director = { abortCurrent() {}, requestRewrite() { /* still thinking */ } };
  const s = await fakeSession({ director, script: longScript() });
  s.ui.showTyping('bot1'); // as if line 1's typing timer had fired
  s.clock.set(20000);
  s.send('first');
  assert.equal(s.typingCalls.length, 2);
  const [what, role, ms] = s.typingCalls[1];
  assert.equal(what + ' ' + role, 'fade bot1');
  assert.ok(ms >= TIMING.INTERRUPT_HIDE_MIN_MS && ms <= TIMING.INTERRUPT_HIDE_MAX_MS, 'hidden after 1 to 3 s');
  await new Promise((r) => setTimeout(r, 300));
  assert.equal(s.typingCalls.length, 2, 'no bridge, no other dots while the director thinks');
  s.end();
});

test('after the pause the same teammate types again only when the new plan says so', async () => {
  let h = null;
  const director = { abortCurrent() {}, requestRewrite(p, handlers) { h = handlers; } };
  const s = await fakeSession({ director, script: longScript() });
  s.ui.showTyping('bot1');
  s.clock.set(20000);
  s.send('first');
  const hideMs = s.typingCalls[1][2];
  s.clock.set(21000);
  h.onLine({ s: 'b1', c: 'back again', p: 1, d: 1 });
  h.onLine({ s: 'b4', c: 'me too', p: 3, d: 1 });
  h.onDone({ lines: 2 });
  const [back, meToo] = s.scheduler.pending();
  assert.ok(back.typingStartMs >= 20000 + hideMs + TIMING.INTERRUPT_RESUME_MIN_MS, 'dots come back only after the pause');
  assert.ok(back.typingStartMs <= 20000 + hideMs + TIMING.INTERRUPT_RESUME_MAX_MS);
  assert.equal(back.plannedMs, back.typingStartMs + 1000, 'the bubble waits for its typing time');
  assert.equal(meToo.plannedMs, back.plannedMs + 3000, 'the next pause counts from the placed line');
  s.end();
});

test('a director line that repeats a message already shown is dropped', async () => {
  const director = {
    abortCurrent() {},
    requestRewrite(p, h) {
      h.onLine({ s: 'b4', c: 'Hey!', p: 2, d: 1 });                                 // copies an earlier message
      h.onLine({ s: 'b4', c: 'good question — price first, I think', p: 3, d: 2 });
      h.onDone({ lines: 2 });
    },
  };
  const s = await fakeSession({ director, script: [
    { id: 1, sender: 'bot4', content: 'hey', send_at: 5 },
    { id: 2, sender: 'bot1', content: 'a later line', send_at: 60 },
  ] });
  s.clock.set(5000);
  s.scheduler.deliverOverdue(); // "hey" is on screen
  s.clock.set(20000);
  s.send('should we focus on price or community?');
  assert.deepEqual(s.scheduler.pending().map((l) => l.text), ['good question — price first, I think']);
  assert.equal(s.names().filter((w) => w === 'line-repeated-skipped').length, 1);
  s.end();
});

test('an empty reply means the team is done', async () => {
  const director = { abortCurrent() {}, requestRewrite(p, h) { h.onDone({ lines: 0 }); } };
  const s = await fakeSession({ director, script: longScript() });
  s.clock.set(20000);
  s.send('bye');
  assert.equal(s.scheduler.pending().length, 0);
  assert.ok(s.names().includes('plan-empty'));
  s.end();
});

test('a failed director leaves nothing planned and records the unanswered message', async () => {
  const director = { abortCurrent() {}, requestRewrite(p, h) { h.onError('network error', { serverLogged: false }); } };
  const s = await fakeSession({ director, script: longScript() });
  s.clock.set(20000);
  s.send('hi');
  assert.equal(s.scheduler.pending().length, 0, 'nothing of the old plan continues');
  assert.equal(s.savedEvents.length, 1);
  assert.equal(s.savedEvents[0].status, 'client-error');
  s.end();
});

test('a second message while the reply streams: what arrived is the plan the next request sees', async () => {
  const payloads = [];
  const director = {
    abortCurrent() {},
    requestRewrite(p, h) {
      payloads.push(p);
      if (payloads.length === 1) {
        h.onLine({ s: 'b4', c: 'first reaction', p: 2, d: 1 }); // cut off by the second message
      } else {
        h.onLine({ s: 'b1', c: 'second reaction', p: 2, d: 1 });
        h.onDone({ lines: 1 });
      }
    },
  };
  const s = await fakeSession({ director, script: longScript() });
  s.clock.set(20000);
  s.send('first');
  s.clock.set(21000);
  s.send('second');
  assert.match(payloads[1].plan, /^ {2}\+1s BEN \(typing 1s\): first reaction$/, 'the partial plan, described from the second message');
  assert.deepEqual(s.scheduler.pending().map((l) => l.text), ['second reaction']);
  s.end();
});

// ---- Segments --------------------------------------------------------------------------

test('a divided script: lines belong to their segment, and a line after the next start is cut', () => {
  const script = {
    segments: [{ start: 0 }, { start: 30 }],
    messages: [
      { id: 1, sender: 'bot1', content: 'in time', send_at: 25, segment: 1 },
      { id: 2, sender: 'bot4', content: 'too late for segment 1', send_at: 35, segment: 1 },
      { id: 3, sender: 'bot1', content: 'segment 2 opens', send_at: 32, segment: 2 },
      { id: 4, sender: 'bot4', content: 'no segment given', send_at: 40 },
    ],
  };
  assert.deepEqual(scriptSegments(script), [{ number: 1, startMs: 0 }, { number: 2, startMs: 30000 }]);
  assert.deepEqual(parseScript(script).map((l) => [l.text, l.segment]), [['in time', 1], ['segment 2 opens', 2], ['no segment given', 2]]);
  assert.deepEqual(scriptSegments({ segments: [{ start: 5 }, { start: 30 }], messages: [] }), [], 'segment 1 has to start at 0');
  assert.deepEqual(scriptSegments({ segments: [{ start: 0 }], messages: [] }), [], 'one segment is no division');
});

test('the prompt lists every item with a divider where a segment starts; the first pause counts from the start', () => {
  const segments = [{ number: 1, startMs: 0 }, { number: 2, startMs: 30000 }, { number: 3, startMs: 60000 }];
  const items = [
    { sender: 'bot1', text: 'a', atMs: 10000 },
    { sender: 'human', text: 'b', atMs: 20000 },
    { sender: 'bot4', text: 'c', atMs: 35000 },
  ];
  assert.equal(describeSegmented(items, segments, 45000), '  +10s ANNA: a\n  +10s YELLOW: b\n  ── Segment 2 starts (0:30) ──\n  +5s BEN: c');
  assert.match(describeSegmented(items, segments, 61000), /\+5s BEN: c\n {2}── Segment 3 starts \(1:00\) ──$/, 'a segment that has started gets its divider');
  assert.equal(describeSegmented(items, [], 45000), describeSequence(items, 0), 'without segments: the usual list');
});

test('until the participant writes, the script plays on across segment starts', async () => {
  let calls = 0;
  const director = { abortCurrent() {}, requestRewrite() { calls++; } };
  const s = await fakeSession({ director, script: longScript(4), segments: [0, 0.05] }); // segment 2 starts after 50 ms
  const before = s.scheduler.pending().map((l) => l.text);
  await new Promise((r) => setTimeout(r, 120));
  assert.equal(calls, 0, 'no director call');
  assert.deepEqual(s.scheduler.pending().map((l) => l.text), before, 'nothing was cut');
  assert.ok(s.names().includes('segment-started'));
  s.end();
});

test('once the director has taken over, a segment start is a hard cut and the director continues', async () => {
  const payloads = [];
  const director = {
    abortCurrent() {},
    requestRewrite(p, h) {
      payloads.push(p);
      if (p.segment) {
        h.onLine({ s: 'b1', c: 'sorry, urgent email. back in a few mins', p: 1, d: 2 });
        h.onDone({ lines: 1 });
      } else {
        h.onLine({ s: 'b4', c: 'reaction', p: 2, d: 1 });
        h.onDone({ lines: 1 });
      }
    },
  };
  const s = await fakeSession({ director, script: longScript(), segments: [0, 30] });
  s.clock.set(20000);
  s.send('hi');
  s.clock.set(30000);
  s.returnFromHidden(5000); // the segment start came (a timer that fired late takes the same path)
  assert.equal(payloads.length, 2);
  assert.equal(payloads[1].segment, 2);
  assert.equal(payloads[1].human_text, '');
  assert.equal(payloads[1].message_client_id, undefined, 'no participant message behind the call');
  assert.match(payloads[1].already_said, /YELLOW: hi\n.*reaction\n {2}── Segment 2 starts \(0:30\) ──$/, 'the lines before the cut were shown, then the divider');
  assert.match(payloads[1].script, /── Segment 2 starts \(0:30\) ──/);
  assert.deepEqual(s.scheduler.pending().map((l) => [l.text, l.plannedMs]), [['sorry, urgent email. back in a few mins', 32000]], 'the reply chains from the segment start');
  s.end();
});

test('a plan never runs past the next segment start', async () => {
  const director = {
    abortCurrent() {},
    requestRewrite(p, h) {
      h.onLine({ s: 'b1', c: 'at 30', p: 10, d: 1 });
      h.onLine({ s: 'b4', c: 'at 50', p: 20, d: 1 });
      h.onLine({ s: 'b1', c: 'at 65, after the cut', p: 15, d: 1 });
      h.onLine({ s: 'b4', c: 'at 70', p: 5, d: 1 });
      h.onDone({ lines: 4 });
    },
  };
  const s = await fakeSession({ director, script: longScript(), segments: [0, 60] });
  s.clock.set(20000);
  s.send('what now?');
  assert.deepEqual(s.scheduler.pending().map((l) => l.text), ['at 30', 'at 50']);
  assert.equal(s.names().filter((w) => w === 'plan-cut-at-segment').length, 1);
  s.end();
});

test('a failed call at a segment start is reported for the segment, not as an unanswered message', async () => {
  const director = { abortCurrent() {}, requestRewrite(p, h) { if (p.segment) h.onError('network error', { serverLogged: false }); } };
  const s = await fakeSession({ director, script: longScript(), segments: [0, 30] });
  s.clock.set(20000);
  s.send('hi');
  s.clock.set(31000);
  s.returnFromHidden(5000);
  assert.deepEqual(s.savedEvents, [{ message_client_id: '', segment: 2, status: 'client-error', error: 'network error' }]);
  s.end();
});

test('the director always gets every message said so far', async () => {
  const payloads = [];
  const director = { abortCurrent() {}, requestRewrite(p) { payloads.push(p); } };
  const s = await fakeSession({ director, script: longScript(2) });
  for (let i = 1; i <= 70; i++) {
    s.clock.set(i * 1000);
    s.send('message ' + i);
  }
  const said = payloads[payloads.length - 1].already_said;
  assert.match(said, /^ {2}\+1s YELLOW: message 1\n/);
  assert.equal(said.split('\n').length, 70);
  assert.doesNotMatch(said, /not shown/);
  s.end();
});

// ---- Streamed plan extraction --------------------------------------------------------

test('objects are extracted as they stream, across chunk boundaries and braces in strings', () => {
  const ex = createObjectExtractor();
  const out = [];
  const text = '{"m":[{"s":"b1","c":"a } weird \\" text","p":2,"d":1},{"s":"b4","g":1,"p":9,"d":2}]}';
  for (let i = 0; i < text.length; i += 7) out.push(...ex.feed(text.slice(i, i + 7)));
  assert.deepEqual(out, [
    { s: 'b1', c: 'a } weird " text', p: 2, d: 1 },
    { s: 'b4', g: 1, p: 9, d: 2 },
  ]);
});

test('an answer-type field from older instructions is passed through and an empty plan yields nothing', () => {
  assert.deepEqual(createObjectExtractor().feed('{"r":"m","m":[{"s":"b1","c":"x","p":1,"d":1}]}'), [{ r: 'm' }, { s: 'b1', c: 'x', p: 1, d: 1 }]);
  assert.deepEqual(createObjectExtractor().feed('{"m":[]}'), []);
});
