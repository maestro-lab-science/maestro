/**
 * script.js — lines: the unit the runtime schedules.
 *
 * A line is one planned event of a teammate:
 *
 *   {
 *     id:        unique number
 *     sender:    a teammate id ('bot1' … 'bot10', see config.js TEAM)
 *     text:      message text ('' for ghost typing)
 *     ghost:     true → the sender types for a while and sends nothing
 *     sendAtMs:  when the bubble appears (session ms; for ghosts: when typing stops)
 *     typingMs:  visible typing before that moment (0 = none)
 *     origin:    'script' | 'director'
 *     epoch:     rewrite generation that produced it (0 = the stored script)
 *     segment:   segment number of a script line (0 = the script is not divided)
 *   }
 *
 * Two sources produce lines: the stored script (absolute send_at seconds, see
 * the format in includes/assist.php script_format_spec) and the director's
 * streamed objects, each with a pause after the previous line — the same
 * "+Ns after the previous line" convention in which the prompt describes the
 * conversation, the script and the plan (describeSequence). A script can be
 * divided into segments (includes/segments.php); then the lists show a divider
 * where each segment starts, and the first line after it counts from the
 * segment's start (describeSegmented).
 *
 * @author Marc Becker
 */

import { TEAM, senderFromCode } from './config.js';
import { wellFormed } from './persistence.js';

let nextId = 1;

/** Reserve ids above the ones a stored script uses. */
function reserveIds(lines) {
  lines.forEach((l) => { if (l.id >= nextId) nextId = l.id + 1; });
}

/** Typing time for a message when none is given: ~one second per three words, at least one. */
function defaultTypingMs(text) {
  const words = String(text || '').trim().split(/\s+/).filter(Boolean).length;
  return Math.max(1, Math.round(words / 3)) * 1000;
}

/**
 * The segments of a stored script: [{ number, startMs }] with segment 1 at 0,
 * or [] when the script is not divided. The chat only receives the start times;
 * the prompts stay on the server (includes/segments.php).
 */
export function scriptSegments(script) {
  const raw = script && !Array.isArray(script) && Array.isArray(script.segments) ? script.segments : [];
  const out = [];
  for (const s of raw) {
    const startMs = Number(s && s.start) * 1000;
    if (!Number.isFinite(startMs) || (out.length === 0 ? startMs !== 0 : startMs <= out[out.length - 1].startMs)) return [];
    out.push({ number: out.length + 1, startMs });
  }
  return out.length > 1 ? out : [];
}

/** The number of the segment a moment falls into (0 without segments). */
export function segmentAt(segments, ms) {
  let n = segments.length ? 1 : 0;
  segments.forEach((s) => { if (s.startMs <= ms) n = s.number; });
  return n;
}

/**
 * Turn the stored script into lines, sorted by time.
 * Accepts {messages:[…]} or a bare array; unknown senders and malformed
 * entries are skipped rather than failing the whole session. In a divided
 * script every line belongs to a segment (its "segment" field, or by time); a
 * line that would come at or after the start of the next segment is cut — the
 * segment start is a hard cut — and never plays.
 */
export function parseScript(script) {
  const raw = Array.isArray(script) ? script : (script && Array.isArray(script.messages) ? script.messages : []);
  const segments = scriptSegments(script);
  const lines = [];
  let prevSendS = 0;

  const ids = new Set();
  raw.forEach((m, index) => {
    if (!m || typeof m !== 'object') return;
    const sender = senderFromCode(m.sender);
    if (!sender) return;
    // The stored id, unless it is missing, empty or used twice (a line pasted
    // in the JSON tab): the scheduler keys its timers by id, and two lines
    // with one id would leave one of them outside every control path.
    let id = m.id === null || m.id === '' ? NaN : Number(m.id);
    if (!Number.isFinite(id) || ids.has(id)) {
      id = 100000 + index;
      while (ids.has(id)) id++;
    }
    ids.add(id);

    if (m.is_empty) {
      const start = Number(m.typing_start);
      const end = Number(m.typing_end);
      if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return;
      lines.push({ id, sender, text: '', ghost: true, sendAtMs: end * 1000, typingMs: (end - start) * 1000, origin: 'script', epoch: 0, segment: Number(m.segment) });
      return;
    }

    const sendS = Number(m.send_at);
    const text = String(m.content || '').trim();
    if (!Number.isFinite(sendS) || !text) return;
    let typingMs;
    if (Number.isFinite(Number(m.typing_start))) {
      typingMs = Math.max(0, (sendS - Number(m.typing_start)) * 1000);
    } else if (Number.isFinite(Number(m.typing_pct))) {
      typingMs = Math.max(0, (sendS - prevSendS) * 1000 * Number(m.typing_pct) / 100);
    } else {
      typingMs = defaultTypingMs(text);
    }
    lines.push({ id, sender, text, ghost: false, sendAtMs: sendS * 1000, typingMs, origin: 'script', epoch: 0, segment: Number(m.segment) });
    prevSendS = sendS;
  });

  let kept = lines;
  if (segments.length) {
    kept = lines.filter((l) => {
      // A stated segment counts when the line does not lie before its start.
      const stated = segments[l.segment - 1];
      l.segment = Number.isInteger(l.segment) && stated && stated.startMs <= l.sendAtMs ? l.segment : segmentAt(segments, l.sendAtMs);
      const next = segments[l.segment]; // the segment after this line's
      return !next || l.sendAtMs < next.startMs;
    });
  } else {
    lines.forEach((l) => { l.segment = 0; });
  }
  kept.sort((a, b) => a.sendAtMs - b.sendAtMs);
  reserveIds(kept);
  return kept;
}

/**
 * Turn one streamed director object into a line.
 *   {"s":"b2","c":"text","p":PAUSE_S,"d":TYPING_S}   or ghost {"s":"b2","g":1,"p":N,"d":N}
 * The pause counts from previousMs — the moment the previous line was placed,
 * or the participant's message for the first line. No pause means right after
 * the previous line; no typing time means the usual second per three words;
 * "d":0 means no dots. Returns null for anything unusable.
 */
export function lineFromDirector(obj, previousMs, epoch) {
  if (!obj || typeof obj !== 'object') return null;
  const sender = senderFromCode(obj.s || obj.sender);
  if (!sender) return null;

  const ghost = !!obj.g;
  const text = ghost ? '' : wellFormed(String(obj.c || obj.content || '')).trim(); // it is shown, saved and sent back
  if (!ghost && !text) return null;

  const p = Number(obj.p);
  const d = Number(obj.d);
  const sendAtMs = previousMs + (Number.isFinite(p) && p > 0 ? p : 0) * 1000;
  let typingMs;
  if (Number.isFinite(d)) typingMs = Math.max(0, d) * 1000;
  else typingMs = ghost ? 3000 : defaultTypingMs(text);
  return { id: nextId++, sender, text, ghost, sendAtMs, typingMs, origin: 'director', epoch };
}

/**
 * Identity of a message for repeat detection: the teammate plus the text with
 * case, spacing and trailing punctuation ignored. The director is told that
 * lines already shown are done; when a model copies one anyway, the session
 * drops the copy (see session.js).
 */
export function lineKey(sender, text) {
  const norm = String(text || '').toLowerCase().replace(/\s+/g, ' ').replace(/[\s.!?…,;:'"“”‘’-]+$/, '').trim();
  return sender + '\n' + norm;
}

/**
 * One line as the director prompt shows it: "+Ns" is the pause after the
 * previous line — the convention the director answers in as well.
 */
export function describeLine(sender, text, gapMs, { ghost = false, typingMs = 0 } = {}) {
  const t = '+' + Math.max(0, Math.round(gapMs / 1000)) + 's';
  const name = TEAM.promptNames[sender] || sender;
  if (ghost) return '  ' + t + ' [' + name + ' types for ' + Math.round(typingMs / 1000) + 's and sends nothing]';
  const typed = typingMs > 0 ? ' (typing ' + Math.round(typingMs / 1000) + 's)' : '';
  return '  ' + t + ' ' + name + typed + ': ' + text;
}

/**
 * A sequence of items { sender, text, atMs, ghost?, typingMs? } as pauses,
 * the first counted from startMs. An item that lies before its predecessor
 * (already overdue) simply gets a pause of 0.
 */
export function describeSequence(items, startMs) {
  let prev = startMs;
  return items.map((it) => {
    const s = describeLine(it.sender, it.text, it.atMs - prev, { ghost: !!it.ghost, typingMs: it.typingMs || 0 });
    prev = Math.max(prev, it.atMs);
    return s;
  }).join('\n');
}

/** 300000 → "5:00" */
function clock(ms) {
  const s = Math.floor(ms / 1000);
  return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

/**
 * describeSequence for a divided conversation (segments from scriptSegments):
 * a divider where each segment starts, and the first item after it counted
 * from the segment's start, as in the settings editor. Items may carry their
 * segment number (script lines); otherwise their time decides. Segments that
 * start after untilMs get no divider (what was said so far). Without segments
 * this is describeSequence from 0.
 */
export function describeSegmented(items, segments, untilMs = Infinity) {
  if (!segments.length) return describeSequence(items, 0);
  const out = [];
  let prev = 0;
  let current = 1;
  const enter = (number) => {
    while (current < number) {
      current++;
      prev = segments[current - 1].startMs;
      out.push('  ── Segment ' + current + ' starts (' + clock(prev) + ') ──');
    }
  };
  items.forEach((it) => {
    enter(it.segment || segmentAt(segments, it.atMs));
    out.push(describeLine(it.sender, it.text, it.atMs - prev, { ghost: !!it.ghost, typingMs: it.typingMs || 0 }));
    prev = Math.max(prev, it.atMs);
  });
  enter(segments.filter((s) => s.startMs <= untilMs).length);
  return out.join('\n');
}
