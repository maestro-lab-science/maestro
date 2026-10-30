/**
 * persistence.js — recording what the participant saw.
 *
 * Every bubble that appears on screen is queued with a client-generated id
 * and sent to api/save_message.php in small batches. Failed batches are
 * retried with backoff; because the server ignores an id it has already
 * stored, retrying is always safe. When the page is closing, whatever is
 * still queued goes out with navigator.sendBeacon(), which survives unload.
 *
 * The same queue carries the browser's reports of director requests that
 * never reached the relay (see session.js), so the researcher's log of
 * unanswered participant messages is complete even after network trouble.
 *
 * Browsers cap the body of requests that may outlive the page (keepalive
 * fetches and beacons) at 64 KiB in total; a larger one is refused outright.
 * A batch therefore never exceeds MAX_BATCH_BYTES, however much has piled up
 * during an outage, and the beacon on unload sends what is queued in such
 * pieces too.
 *
 * A batch the server refuses as malformed (4xx) is not retried as it is,
 * because it would block everything queued behind it: the items then go one
 * at a time, and an item the server still refuses is dropped and logged.
 *
 * @author Marc Becker
 */

/**
 * Text without lone halves of surrogate pairs (a model that wrote half an
 * emoji escape): JSON.stringify keeps them as "\ud83d", which PHP refuses as
 * invalid JSON. Each lone half becomes U+FFFD.
 */
export function wellFormed(text) {
  const s = String(text);
  if (typeof s.toWellFormed === 'function') return s.toWellFormed();
  let out = '';
  for (let i = 0; i < s.length; i++) {
    const c = s.charCodeAt(i);
    if (c >= 0xD800 && c <= 0xDBFF) {
      const next = s.charCodeAt(i + 1);
      if (next >= 0xDC00 && next <= 0xDFFF) { out += s[i] + s[i + 1]; i++; continue; }
      out += '\uFFFD';
    } else if (c >= 0xDC00 && c <= 0xDFFF) {
      out += '\uFFFD';
    } else {
      out += s[i];
    }
  }
  return out;
}

/** The largest request body one batch may have (below the browsers' 64 KiB keepalive limit). */
export const MAX_BATCH_BYTES = 48 * 1024;
const MAX_BATCH_MESSAGES = 50;
const MAX_BATCH_EVENTS = 20;

export function createSaveQueue({ projectId, conversationId, log }) {
  const messages = [];
  const events = [];
  let flushTimer = null;
  let inFlight = false;
  let failures = 0;
  let oneByOne = false; // after a refused batch, until the queue is empty

  function newClientId() {
    if (crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '').slice(0, 32);
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  function body(msgs, evs) {
    return JSON.stringify({ project_id: projectId, conversation_id: conversationId, messages: msgs, director_events: evs });
  }

  /**
   * The next batch from the front of the queues: as many items as fit into
   * MAX_BATCH_BYTES, and always at least one, so the queue keeps draining.
   */
  function nextBatch() {
    if (oneByOne) return messages.length ? { msgs: messages.slice(0, 1), evs: [] } : { msgs: [], evs: events.slice(0, 1) };
    let msgs = messages.slice(0, MAX_BATCH_MESSAGES);
    let evs = events.slice(0, MAX_BATCH_EVENTS);
    while (msgs.length + evs.length > 1 && new TextEncoder().encode(body(msgs, evs)).length > MAX_BATCH_BYTES) {
      if (msgs.length > 1) msgs = msgs.slice(0, Math.ceil(msgs.length / 2));
      else if (evs.length > 1) evs = evs.slice(0, Math.ceil(evs.length / 2));
      else if (msgs.length) evs = []; // one large message: it goes alone, the event follows
      else msgs = [];
    }
    return { msgs, evs };
  }

  function scheduleFlush(delayMs) {
    clearTimeout(flushTimer);
    flushTimer = setTimeout(flush, delayMs);
  }

  async function flush() {
    if (inFlight || (messages.length === 0 && events.length === 0)) return;
    inFlight = true;
    const { msgs: msgBatch, evs: evBatch } = nextBatch();
    try {
      const res = await fetch('api/save_message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body(msgBatch, evBatch),
        keepalive: true,
      });
      if (res.status >= 400 && res.status < 500 && res.status !== 408 && res.status !== 429) {
        // Refused as malformed: retrying the same body cannot help.
        if (msgBatch.length + evBatch.length > 1) {
          oneByOne = true;
          log.warn('save-refused', { status: res.status, items: msgBatch.length + evBatch.length, next: 'one at a time' });
        } else {
          messages.splice(0, msgBatch.length);
          events.splice(0, evBatch.length);
          log.warn('save-dropped', { status: res.status, client_id: msgBatch.length ? msgBatch[0].client_id : null });
        }
        if (messages.length || events.length) scheduleFlush(50);
        else oneByOne = false;
        return;
      }
      if (!res.ok) throw new Error('HTTP ' + res.status);
      messages.splice(0, msgBatch.length);
      events.splice(0, evBatch.length);
      failures = 0;
      if (messages.length || events.length) scheduleFlush(50);
      else oneByOne = false;
    } catch (err) {
      failures++;
      const wait = Math.min(30000, 1000 * 2 ** Math.min(failures, 5)) + Math.random() * 500;
      log.warn('save-retry', { attempt: failures, in_ms: Math.round(wait), queued: messages.length + events.length, error: err.message });
      scheduleFlush(wait);
    } finally {
      inFlight = false;
    }
  }

  // Last chance on unload: hand the remainder to the browser's beacon queue, in
  // batches it accepts. What the browser refuses stays queued: a page that
  // comes back from the back-forward cache then simply flushes it later.
  window.addEventListener('pagehide', () => {
    try {
      while (messages.length || events.length) {
        const { msgs, evs } = nextBatch();
        if (!navigator.sendBeacon('api/save_message.php', new Blob([body(msgs, evs)], { type: 'application/json' }))) break;
        messages.splice(0, msgs.length);
        events.splice(0, evs.length);
      }
    } catch (_) { /* nothing more we can do */ }
  });

  return {
    /** Queue one message. Returns its client id. */
    save({ sender, text, shownAt }) {
      const clientId = newClientId();
      messages.push({ client_id: clientId, sender, message_text: wellFormed(text), shown_at: shownAt.toISOString() });
      scheduleFlush(300);
      return clientId;
    },
    /** Report a director failure the relay could not record itself (segment: its number, for the call at a segment start). */
    event({ message_client_id, segment, status, error }) {
      const ev = { message_client_id, status, error: wellFormed(String(error || '').slice(0, 255)) };
      if (Number.isInteger(segment)) ev.segment = segment;
      events.push(ev);
      scheduleFlush(300);
    },
    pendingCount: () => messages.length + events.length,
    flush,
  };
}
