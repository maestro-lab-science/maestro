/**
 * session.js — the conversation, start to finish.
 *
 * The stored script plays on its own. When the participant writes, the plan
 * is outdated: it is dropped and the director is asked for the entire
 * remaining conversation. Whoever was typing pauses — the dots go after a
 * moment, as if they stopped to read — and types again only when the new plan
 * says so. The director decides everything about timing; the runtime only
 * places lines where the pauses say, and shows a late line as soon as its
 * typing time has been visible.
 *
 * Sequence of one participant message:
 *   1. show + record the message
 *   2. describe the state for the director — what was said, the whole script,
 *      the unsent lines of the plan — all as "+Ns after the previous line"
 *   3. drop the plan; let the interrupted typists pause; ask the director (epoch++)
 *   4. lines are placed as they stream in: the first at the message plus its
 *      pause, each next at the previous line plus its pause. A line that
 *      repeats a message already shown is dropped (models copy now and then)
 *   5. reply complete → that is the plan. An empty list means the team is done.
 *
 * If the director fails (after one retry), nothing is planned any more: the
 * team is silent until the participant writes again, and the failure is
 * recorded so the researcher sees the unanswered message. A second message
 * while a reply streams aborts it; the lines that arrived are the plan the
 * next request sees. A safety cap (maxMessages) is the only hard stop of a
 * session.
 *
 * Segments (the stored script divided at fixed times, see includes/segments.php)
 * mark important events: hard cuts where the conversation changes a lot. Until
 * the participant first writes, the script simply plays on, segments included.
 * Once the director has taken over, each segment start is a cut: the same
 * sequence runs without a participant message, and the director gets the new
 * segment instead. A director plan never runs past the next segment start.
 *
 * @author Marc Becker
 */

import { TIMING, TEAM } from './config.js';
import { parseScript, scriptSegments, lineFromDirector, describeSequence, describeSegmented, lineKey } from './script.js';

export function createSession({ config, ui, clock, log, scheduler, director, saves }) {
  /** Everything shown so far, in order: { role, text, atMs }. */
  const transcript = [];
  /** The stored script as lines — the director sees it whole, every time. */
  let scriptLines = [];
  let messageCount = 0;
  let rewriteEpoch = 0;
  let ended = false;
  /** The segments of the script ([] = not divided), each { number, startMs, passed, timer }. */
  let segments = [];
  /** From the participant's first message on, the director plans; before that the script plays as written. */
  let directorActive = false;

  // ---- Start --------------------------------------------------------------------

  function start() {
    scriptLines = parseScript(config.script);
    segments = scriptSegments(config.script).map((s) => ({ ...s, passed: s.number === 1, timer: null }));
    if (!scriptLines.length) {
      log.warn('no-script', 'The stored script has no usable lines.');
    }
    ui.onSend(handleParticipantMessage);
    // A hidden tab: the conversation and the timer go on (wall clock). On
    // return, whatever came due meanwhile appears at once with its original
    // times — what a real chat looks like after switching back to it — up to
    // a segment start that came meanwhile, where the director then takes over.
    clock.onReturnFromHidden((hiddenMs) => {
      const due = ended ? [] : segments.filter((s) => !s.passed && s.startMs <= clock.elapsedMs());
      const shown = scheduler.deliverOverdue(directorActive && due.length ? due[0].startMs : Infinity);
      log.event('tab-returned', { hidden_ms: hiddenMs, caught_up: shown, segment_started: due.length > 0 });
      if (due.length) segmentReached(due[due.length - 1]);
    });

    ui.readyGate.onReady(() => {
      // "Finding teammates…" for the configured wait, then the chat begins.
      setTimeout(() => {
        ui.readyGate.release();
        clock.start();
        if (config.timerEnabled && config.timerDurationMs > 0) {
          ui.timer.start(config.timerDurationMs, onTimerEnd);
        }
        scheduler.load(scriptLines);
        segments.forEach((seg) => {
          if (!seg.passed) seg.timer = setTimeout(() => segmentReached(seg), Math.max(0, seg.startMs - clock.elapsedMs()));
        });
        log.event('session-started', { lines: scriptLines.length, director: config.hasDirector, segments: segments.length, conversation: config.conversationId });
      }, config.readyModal ? config.readyWaitMs : 0);
    });
  }

  // ---- Segments -----------------------------------------------------------------------

  /**
   * A segment starts. Once the director has taken over, this is a hard cut:
   * the plan goes and the director continues with the new segment. Before
   * that, the script plays on as written.
   */
  function segmentReached(seg) {
    if (ended || seg.passed) return;
    // Earlier starts that never fired (a throttled timer) count as passed: the
    // prompt lists every segment with its time, so the director still sees them.
    segments.forEach((s) => {
      if (s.startMs <= seg.startMs) { s.passed = true; clearTimeout(s.timer); }
    });
    const cut = directorActive && config.hasDirector;
    log.event('segment-started', { segment: seg.number, late_ms: Math.max(0, clock.elapsedMs() - seg.startMs), cut });
    if (cut) replan({ segment: seg });
  }

  /** The start of the next segment (Infinity when none is coming). */
  function nextSegmentMs() {
    const next = segments.find((s) => !s.passed);
    return next ? next.startMs : Infinity;
  }

  // ---- Delivery -------------------------------------------------------------------

  /** A team line reached its time (atMs = its planned session time): show it and record it. */
  function deliverLine(line, atMs) {
    if (ended) return;
    const time = clock.dateAt(atMs);
    transcript.push({ role: line.sender, text: line.text, atMs });
    ui.addMessage({ role: line.sender, text: line.text, time });
    saves.save({ sender: line.sender, text: line.text, shownAt: time });
    countMessage();
  }

  function countMessage() {
    messageCount++;
    if (messageCount >= config.maxMessages) end('message-limit');
  }

  // ---- Participant message --------------------------------------------------------

  function handleParticipantMessage(text, keystrokes) {
    if (ended) return;
    const now = clock.elapsedMs();
    const time = new Date();
    transcript.push({ role: 'human', text, atMs: now });
    ui.addMessage({ role: 'human', text, time });
    // The keystroke count travels with the text (a measure of editing effort).
    const messageClientId = saves.save({ sender: 'human', text: text + ' [[//' + keystrokes + ']]', shownAt: time });
    countMessage();
    if (ended) return;

    if (!config.hasDirector) {
      log.warn('no-director', 'Message recorded; the script continues unchanged (no director instructions).');
      return;
    }
    directorActive = true;
    replan({ text, messageClientId });
  }

  /**
   * Ask the director for the rest of the conversation, after a participant
   * message ({ text, messageClientId }) or at a segment start ({ segment }).
   */
  function replan(trigger) {
    const now = clock.elapsedMs();
    const segment = trigger.segment || null;

    // The state as the director sees it, described before the plan goes.
    const unsent = scheduler.pending();
    const payload = {
      elapsed_seconds: Math.round(now / 1000),
      remaining_seconds: remainingSeconds(),
      already_said: describeTranscript(now),
      script: describeSegmented(scriptLines.map((l) => ({ sender: l.sender, text: l.text, atMs: l.sendAtMs, ghost: l.ghost, typingMs: l.typingMs, segment: l.segment })), segments),
      plan: describeSequence(unsent.map((l) => ({ sender: l.sender, text: l.text, atMs: l.plannedMs, ghost: l.ghost, typingMs: l.typingMs })), now),
      human_text: segment ? '' : trigger.text,
    };
    if (segment) payload.segment = segment.number;
    else payload.message_client_id = trigger.messageClientId;

    // From this moment the old plan is outdated: nothing more of it is shown.
    // Whoever was typing pauses: the dots go after a moment, and that teammate
    // types again no sooner than a little later — if the new plan says so.
    scheduler.dropAll();
    const epoch = ++rewriteEpoch;
    const isCurrent = () => epoch === rewriteEpoch && !ended;
    const paused = ui.typingRoles();
    paused.forEach((role) => {
      const hideIn = randomBetween(TIMING.INTERRUPT_HIDE_MIN_MS, TIMING.INTERRUPT_HIDE_MAX_MS);
      ui.fadeTyping(role, hideIn);
      scheduler.pauseTyping(role, now + hideIn + randomBetween(TIMING.INTERRUPT_RESUME_MIN_MS, TIMING.INTERRUPT_RESUME_MAX_MS));
    });
    log.director('rewrite-requested', segment ? { epoch, dropped: unsent.length, paused, segment: segment.number } : { epoch, dropped: unsent.length, paused });

    // Lines already shown: a model sometimes copies one into its plan, and the
    // participant would see the same bubble twice. Such copies are dropped.
    const said = new Set();
    transcript.slice(-12).forEach((m) => { if (m.role !== 'human') said.add(lineKey(m.role, m.text)); });

    let previousMs = now; // every pause counts from the previous placed line; the first from the message (or segment start)
    let placed = 0;
    let cut = false;

    director.requestRewrite(payload, {
      isCurrent,
      onLine(obj) {
        if (cut) return;
        const line = lineFromDirector(obj, previousMs, epoch);
        if (!line) { log.warn('line-skipped', obj); return; }
        // The plan ends at the next segment start: there the chat cuts and asks again.
        const nextMs = nextSegmentMs();
        if (line.sendAtMs >= nextMs) {
          cut = true;
          log.script('plan-cut-at-segment', { epoch, segment_s: nextMs / 1000 });
          return;
        }
        if (!line.ghost) {
          const key = lineKey(line.sender, line.text);
          if (said.has(key)) { log.warn('line-repeated-skipped', { epoch, sender: line.sender, text: line.text.slice(0, 60) }); return; }
          said.add(key);
        }
        previousMs = scheduler.scheduleLine(line);
        placed++;
        log.script('line-scheduled', { id: line.id, sender: line.sender, in_s: Math.round((previousMs - clock.elapsedMs()) / 1000), ghost: line.ghost });
      },
      onDone({ lines }) {
        if (lines === 0) log.director('plan-empty', { epoch }); // the team is done
        else log.director('plan-complete', { epoch, lines: placed });
      },
      onError(message, { serverLogged } = {}) {
        // Nothing is planned any more: the team stays silent until the
        // participant writes again (or the next segment starts). The relay logs
        // the failures it sees; the ones that never reached it are reported
        // from here, so the researcher sees the unanswered message either way.
        log.warn('rewrite-unavailable', { epoch, reason: message, planned: scheduler.pending().length });
        if (!serverLogged) {
          saves.event(segment
            ? { message_client_id: '', segment: segment.number, status: 'client-error', error: message }
            : { message_client_id: trigger.messageClientId, status: 'client-error', error: message });
        }
      },
    });
  }

  /** Everything said so far, for the prompt: every message, as pauses, with a divider where each segment started. */
  function describeTranscript(nowMs) {
    return describeSegmented(transcript.map((m) => ({ sender: m.role, text: m.text, atMs: m.atMs })), segments, nowMs);
  }

  /** Seconds left on the session timer, or null when the project has no timer. */
  function remainingSeconds() {
    const ms = ui.timer.remainingMs();
    return Number.isFinite(ms) ? Math.round(ms / 1000) : null;
  }

  function randomBetween(min, max) {
    return min + Math.random() * (max - min);
  }

  // ---- End --------------------------------------------------------------------------

  function onTimerEnd() {
    log.event('timer-ended', { lock_input: config.lockInputAtTimerEnd });
    if (config.lockInputAtTimerEnd) end('timer');
  }

  function end(reason) {
    if (ended) return;
    ended = true;
    rewriteEpoch++; // abandons any in-flight rewrite
    segments.forEach((s) => clearTimeout(s.timer));
    director.abortCurrent();
    scheduler.cancelAll(reason);
    ui.disableInput('The session has ended. Thank you!');
    saves.flush();
    log.event('session-ended', { reason, messages: messageCount });
  }

  return { start, deliverLine, end };
}
