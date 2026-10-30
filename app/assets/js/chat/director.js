/**
 * director.js — the browser side of the director relay.
 *
 * One rewrite = one request to api/director.php (mode "rewrite"), received
 * through AIT.modelStream (assets/js/stream.js) — over the WebSocket relay when
 * it is configured, otherwise as a Server-Sent-Events response. Events:
 *
 *   {connected}              the model call is running
 *   {"d":"…"}                fragments of the model's JSON reply
 *   {"error":"…","code":429} (relay: true when the relay itself failed)
 *   {done}
 *
 * The reply is compact JSON {"m":[{…},{…},…]} — the entire remaining
 * conversation. Rather than waiting for the whole thing, the objects inside
 * "m" are extracted the moment each one is complete (string- and
 * escape-aware brace matching) and handed to onLine — the first line of the
 * team's reaction can be on screen while the model is still writing the rest.
 *
 * Failure policy: one retry (jittered) for transient failures that happen
 * before any line arrived (network error, 429/5xx, first-token timeout).
 * A request that has been superseded by a newer participant message is
 * abandoned silently (isCurrent() returns false). onError receives
 * { serverLogged }: true when the relay reported the failure in-band (it has
 * recorded the event itself), false when the request never got through or
 * timed out — then the session reports it, so no failure goes unrecorded.
 *
 * @author Marc Becker
 */

import { TIMING } from './config.js';

export function createDirectorClient({ projectId, conversationId, log }) {
  let current = null; // the in-flight request: { controller, superseded }

  /** Abort whatever request is in flight (a newer participant message replaces it). */
  function abortCurrent() {
    if (current) {
      current.superseded = true;
      current.controller.abort();
      current = null;
    }
  }

  /**
   * Start a rewrite. Returns a handle with abort().
   * handlers: isCurrent(), onLine(obj), onDone({lines}), onError(message, { serverLogged })
   */
  function requestRewrite(payload, handlers) {
    abortCurrent();
    const request = { controller: new AbortController(), superseded: false };
    current = request;
    run(payload, handlers, request, 1);
    return { abort: () => { request.superseded = true; request.controller.abort(); } };
  }

  async function run(payload, handlers, request, attempt) {
    const { isCurrent } = handlers;
    const controller = request.controller; // this attempt's; a retry gets a fresh one
    const startedAt = Date.now();
    let linesDelivered = 0;
    let firstTokenTimer = null;
    let streamTimer = null;
    const clearTimers = () => { clearTimeout(firstTokenTimer); clearTimeout(streamTimer); };

    const failed = async (message, transient, serverLogged = false) => {
      clearTimers();
      if (!isCurrent() || request.superseded) return; // superseded: nobody is waiting for this result
      if (transient && linesDelivered === 0 && attempt === 1) {
        const wait = 900 + Math.random() * 900;
        log.director('rewrite-retry', { after_ms: Math.round(wait), reason: message });
        await new Promise((r) => setTimeout(r, wait));
        if (!isCurrent() || request.superseded) return;
        request.controller = new AbortController(); // the first one may have been aborted by our own timeout
        return run(payload, handlers, request, attempt + 1);
      }
      log.director('rewrite-failed', { reason: message, lines: linesDelivered, ms: Date.now() - startedAt });
      handlers.onError(message, { serverLogged });
    };

    firstTokenTimer = setTimeout(() => controller.abort(new Error('first-token-timeout')), TIMING.FIRST_TOKEN_TIMEOUT_MS);
    streamTimer = setTimeout(() => controller.abort(new Error('stream-timeout')), TIMING.STREAM_TIMEOUT_MS);

    const body = Object.assign({ project_id: projectId, conversation_id: conversationId, mode: 'rewrite', stream: true }, payload);
    const extractor = createObjectExtractor();

    try {
      for await (const chunk of window.AIT.modelStream(body, { signal: controller.signal })) {
        if (chunk.connected) {
          // The model call is running. Anything after this is model time (or a
          // proxy holding the stream back) — worth telling apart when a reply feels slow.
          log.director('rewrite-connected', { ms: Date.now() - startedAt });
          continue;
        }
        if (chunk.done) {
          clearTimers();
          if (!isCurrent()) return;
          log.director('rewrite-done', { lines: linesDelivered, ms: Date.now() - startedAt });
          handlers.onDone({ lines: linesDelivered });
          return;
        }
        if (chunk.error) {
          const code = Number(chunk.code) || 0;
          // chunk.relay: the relay itself failed (no worker ran), so nothing was recorded server-side.
          return failed(String(chunk.error), code === 429 || code >= 500 || code === 0, !chunk.relay);
        }
        if (typeof chunk.d !== 'string') continue;
        for (const obj of extractor.feed(chunk.d)) {
          if (!isCurrent()) return;
          if (obj.r !== undefined) continue; // an answer-type field some models still send; not used
          if (linesDelivered === 0) {
            clearTimeout(firstTokenTimer);
            log.director('rewrite-first-line', { ms: Date.now() - startedAt });
          }
          linesDelivered++;
          handlers.onLine(obj);
        }
      }
      clearTimers();
      if (!isCurrent()) return;
      // Stream ended without [DONE]: treat what we got as the result.
      log.director('rewrite-ended', { lines: linesDelivered, ms: Date.now() - startedAt });
      handlers.onDone({ lines: linesDelivered });
    } catch (err) {
      if (err && err.status) {
        // Validation / configuration errors arrive as JSON before any stream.
        return failed(err.message || ('Director error ' + err.status), err.status === 429 || err.status >= 500);
      }
      return failed(abortReason(controller, err), true);
    }
  }

  return { requestRewrite, abortCurrent };
}

function abortReason(controller, err) {
  if (controller.signal.aborted) {
    const r = controller.signal.reason;
    return r && r.message ? r.message : 'superseded';
  }
  return (err && err.message) || 'network error';
}

/**
 * Incremental extraction of the objects inside {"m":[ … ]} as the text
 * streams in: each complete message object is emitted as soon as it closes.
 * Braces inside strings and escaped quotes are handled. A leading "r" field
 * (an answer type older instructions asked for) is passed through as {r} so
 * the caller can ignore it. (Exported for the unit tests in app/tests.)
 */
export function createObjectExtractor() {
  let buffer = '';        // unconsumed text (inside the "m" array once found)
  let scanned = 0;        // how far into buffer the scanner already looked
  let inArray = false;
  let typeEmitted = false;
  let closed = false;
  let depth = 0;
  let inString = false;
  let escaped = false;
  let objStart = -1;      // index in buffer where the current object began

  return {
    feed(text) {
      const out = [];
      if (closed) return out;
      buffer += text;

      if (!typeEmitted) {
        const m = buffer.match(/"r"\s*:\s*"([nmMe])"/);
        if (m) { typeEmitted = true; out.push({ r: m[1] }); }
      }
      if (!inArray) {
        // The key "m" followed by its array — not the value "m" of "r".
        const m = buffer.match(/"m"\s*:\s*\[/);
        if (!m) return out;
        inArray = true;
        buffer = buffer.substring(m.index + m[0].length);
        scanned = 0;
      }

      // Resume where the previous chunk left off; never rescan consumed text,
      // because the string/escape/depth state belongs to that position.
      let i = scanned;
      while (i < buffer.length) {
        const ch = buffer[i];
        if (objStart === -1) {
          if (ch === '{') { objStart = i; depth = 1; inString = false; escaped = false; }
          else if (ch === ']') { closed = true; break; }
        } else if (inString) {
          if (escaped) escaped = false;
          else if (ch === '\\') escaped = true;
          else if (ch === '"') inString = false;
        } else if (ch === '"') {
          inString = true;
        } else if (ch === '{') {
          depth++;
        } else if (ch === '}') {
          depth--;
          if (depth === 0) {
            const raw = buffer.substring(objStart, i + 1);
            try { out.push(JSON.parse(raw)); } catch (_) { /* skip a malformed object, keep streaming */ }
            buffer = buffer.substring(i + 1);
            i = -1;
            objStart = -1;
          }
        }
        i++;
      }
      if (objStart === -1) {
        buffer = '';
        scanned = 0;
      } else {
        scanned = buffer.length;
      }
      return out;
    },
  };
}
