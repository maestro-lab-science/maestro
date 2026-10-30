/**
 * scheduler.js — the one place lines are turned into timers.
 *
 * The scheduler owns the plan: every pending line and its two timers (typing
 * dots, delivery). It does not decide timing — the stored script and the
 * director do. Its own rules are few:
 *
 *   late        a line whose moment has already passed (a slow reply) is
 *               shown as soon as its typing time has been visible;
 *   pause       a teammate who was typing when the participant wrote types
 *               again no sooner than the session says (pauseTyping) — the
 *               line waits for its dots, the following pauses stay as given;
 *   catch-up    when the participant returns to a tab that was hidden, lines
 *               that came due meanwhile are shown at once with their original
 *               times (deliverOverdue) — the conversation went on without them.
 *
 * Typing dots are one indicator per teammate (ui.js): shown from a line's
 * typing start, hidden when its bubble appears — unless another line of that
 * teammate is already being typed, then the dots simply stay on.
 *
 * Every delivered line is stamped with its planned time, not the moment the
 * timer fired, so a throttled timer never distorts the recorded timeline.
 *
 * The scheduler never talks to the network and never decides *what* is said.
 *
 * @author Marc Becker
 */

export function createScheduler({ clock, ui, log, onDeliver }) {
  /** Every line ever handed to the scheduler, by id. */
  const lines = new Map();
  /** Per teammate: the earliest moment their dots may come back after an interruption. */
  const typingPausedUntil = {};
  let active = true;

  function pending() {
    return [...lines.values()].filter((l) => l.state === 'pending').sort((a, b) => a.plannedMs - b.plannedMs);
  }

  /** Is a pending line of this teammate already in its typing window (the dots should be on)? */
  function isTyping(sender) {
    const now = clock.elapsedMs();
    return pending().some((l) => l.sender === sender && l.typingMs > 0 && l.typingStartMs <= now);
  }

  function clearTimers(line) {
    if (line.timers) {
      clearTimeout(line.timers.typing);
      clearTimeout(line.timers.send);
      line.timers = null;
    }
  }

  /**
   * Schedule one line: its dots start at its moment minus its typing time —
   * never before now, and never before the teammate's pause is over — and the
   * bubble appears at its moment, or as soon as the dots have been visible
   * for the typing time. Returns the planned moment.
   */
  function scheduleLine(line) {
    if (!active) return null;
    const now = clock.elapsedMs();
    const typingMs = Math.max(0, line.typingMs || 0);
    const typingStart = Math.max(now, line.sendAtMs - typingMs, typingPausedUntil[line.sender] || 0);
    const planned = Math.max(line.sendAtMs, typingStart + typingMs);
    line.plannedMs = planned;
    line.typingStartMs = typingStart;
    line.state = 'pending';
    line.timers = {
      typing: typingMs > 0
        ? setTimeout(() => { if (line.state === 'pending') ui.showTyping(line.sender); }, typingStart - now)
        : null,
      send: setTimeout(() => deliver(line), planned - now),
    };
    lines.set(line.id, line);
    return planned;
  }

  function deliver(line) {
    if (line.state !== 'pending') return;
    line.state = 'sent';
    line.timers = null;
    ui.hideTyping(line.sender);
    // A follow-up of the same teammate that is already in its typing window
    // (a double-text with long typing) keeps the dots on after the bubble.
    if (isTyping(line.sender)) ui.showTyping(line.sender);
    if (line.ghost) {
      log.script('ghost-typing-ended', { id: line.id, sender: line.sender });
      return;
    }
    onDeliver(line, line.plannedMs);
  }

  return {
    /** Load a whole plan (the stored script at session start). */
    load(planLines) {
      planLines.forEach(scheduleLine);
      log.script('plan-loaded', { lines: planLines.length });
    },

    scheduleLine,
    pending,
    isTyping,

    /**
     * The participant wrote: the whole plan is outdated. Every pending line is
     * cancelled. The typing dots are left to the session (it lets whoever was
     * typing pause). Returns the cancelled lines.
     */
    dropAll() {
      const dropped = pending();
      dropped.forEach((l) => { clearTimers(l); l.state = 'cancelled'; });
      if (dropped.length) log.script('plan-dropped', { lines: dropped.length });
      return dropped;
    },

    /** This teammate's dots may not come back before untilMs (they were interrupted). */
    pauseTyping(sender, untilMs) {
      typingPausedUntil[sender] = Math.max(typingPausedUntil[sender] || 0, untilMs);
    },

    /**
     * After a hidden tab: every line whose time has passed is shown now, in
     * order, each stamped with its original time — like the messages waiting
     * in a chat app that was in the background. Future lines keep their
     * schedule; so do lines from beforeMs on (a segment that started
     * meanwhile replaces them). Returns how many lines were shown.
     */
    deliverOverdue(beforeMs = Infinity) {
      const now = clock.elapsedMs();
      const due = pending().filter((l) => l.plannedMs <= now && l.plannedMs < beforeMs);
      due.forEach((l) => { clearTimers(l); deliver(l); });
      if (due.length) log.script('plan-caught-up', { lines: due.length });
      return due.length;
    },

    /** Stop everything (session end). */
    cancelAll(reason) {
      active = false;
      pending().forEach((l) => { clearTimers(l); l.state = 'cancelled'; });
      ui.hideAllTyping();
      log.script('plan-cancelled', { reason });
    },
  };
}
