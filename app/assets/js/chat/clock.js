/**
 * clock.js — the session clock.
 *
 * All script times are milliseconds since the conversation started; this is
 * the single source of that origin. It also watches tab visibility: browsers
 * throttle timers in hidden tabs to about once a minute, so a participant who
 * switches apps comes back to lines that are overdue. The session is told
 * about that moment and shows them at once (see scheduler.deliverOverdue).
 *
 * Policy (a study-design decision): the clock and the timer keep running
 * while the tab is hidden — the team continued without the participant, like
 * a real chat. If a study should instead pause while the participant is away,
 * this is the place to implement it.
 *
 * @author Marc Becker
 */

export function createClock() {
  let startMs = 0;
  let hiddenSince = 0;
  const returnListeners = [];

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      hiddenSince = Date.now();
      return;
    }
    const hiddenMs = hiddenSince ? Date.now() - hiddenSince : 0;
    hiddenSince = 0;
    if (hiddenMs >= 2000) returnListeners.forEach((cb) => cb(hiddenMs));
  }, { passive: true });

  return {
    start() { startMs = Date.now(); },
    started() { return startMs > 0; },
    /** Milliseconds since the conversation started (0 before start). */
    elapsedMs() { return startMs ? Date.now() - startMs : 0; },
    /** Wall-clock Date for a given session offset. */
    dateAt(elapsedMs) { return new Date((startMs || Date.now()) + elapsedMs); },
    /** Called with the hidden duration when the tab becomes visible again after ≥ 2 s. */
    onReturnFromHidden(cb) { returnListeners.push(cb); },
  };
}
