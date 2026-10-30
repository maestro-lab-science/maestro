/**
 * stream.js — receive a model stream from api/director.php, whichever way it travels.
 *
 *   for await (const ev of AIT.modelStream(payload, { signal })) { … }
 *
 * yields, in order:
 *   { connected: true }        the model call is running (worker started / stream opened)
 *   { d }, { r }, { p }, { error, code }, { final }   the relay's events, unchanged
 *   { done: true }             end of stream ([DONE]); nothing follows
 * A stream that ends without { done } simply stops yielding.
 *
 * Throws an Error with .status (HTTP status) when api/director.php refused the
 * request before streaming (validation, auth, configuration), a plain Error
 * when the network or the relay connection failed, and the signal's reason
 * when the caller aborted.
 *
 * Transport: when the page sets window.AIT_RELAY_URL, the request asks for a
 * relay token and the events arrive over one shared WebSocket (app/relay/) —
 * not subject to the host's 45-second cut on HTTP responses, and not holding a
 * PHP connection while the model writes. If the relay cannot be reached, this
 * request and the next minute's requests use the direct Server-Sent-Events
 * response instead. Leaving the loop early (break/return) before a terminal
 * event cancels the model call, like aborting a fetch.
 *
 * @author Marc Becker
 */
(function () {
  'use strict';

  const ENDPOINT = 'api/director.php';
  const CONNECT_TIMEOUT_MS = 5000;
  const RELAY_BACKOFF_MS = 60000;

  let relayDownUntil = 0;
  let connection = null;  // { ws, closed, ready: Promise, requests: Map<id, channel> }
  let nextId = 1;

  function relayUrl() {
    const configured = String(window.AIT_RELAY_URL || '');
    if (!configured) return '';
    const url = new URL(configured, window.location.href);
    if (url.protocol === 'http:') url.protocol = 'ws:';
    if (url.protocol === 'https:') url.protocol = 'wss:';
    return url.href;
  }

  function httpError(message, status) {
    const err = new Error(message);
    err.status = status;
    return err;
  }

  function abortError(signal) {
    const reason = signal && signal.reason;
    return reason instanceof Error ? reason : new DOMException('Aborted', 'AbortError');
  }

  /** A tiny async queue: push() from event handlers, await next() in the generator. */
  function channel() {
    const items = [];
    let wake = null;
    return {
      push(item) {
        items.push(item);
        if (wake) { const w = wake; wake = null; w(); }
      },
      async next() {
        while (!items.length) await new Promise((resolve) => { wake = resolve; });
        return items.shift();
      },
    };
  }

  /** The page's relay socket, opened on first use and reopened after it closed. */
  function getConnection() {
    if (connection && !connection.closed) return connection.ready;
    const conn = { ws: null, closed: false, requests: new Map() };
    conn.ready = new Promise((resolve, reject) => {
      let ws;
      try { ws = new WebSocket(relayUrl()); } catch (err) { conn.closed = true; reject(err); return; }
      conn.ws = ws;
      const timer = setTimeout(() => { reject(new Error('relay connect timeout')); try { ws.close(); } catch (_) {} }, CONNECT_TIMEOUT_MS);
      ws.onopen = () => { clearTimeout(timer); resolve(conn); };
      ws.onerror = () => { clearTimeout(timer); reject(new Error('relay unreachable')); };
      ws.onclose = () => {
        clearTimeout(timer);
        conn.closed = true;
        if (connection === conn) connection = null;
        reject(new Error('relay closed'));
        for (const ch of conn.requests.values()) ch.push({ lost: true });
        conn.requests.clear();
      };
      ws.onmessage = (event) => {
        let msg;
        try { msg = JSON.parse(event.data); } catch (_) { return; }
        const ch = conn.requests.get(msg.id);
        if (ch) ch.push(msg);
      };
    });
    connection = conn;
    return conn.ready;
  }

  function untilAborted(promise, signal) {
    if (!signal) return promise;
    if (signal.aborted) return Promise.reject(abortError(signal));
    return new Promise((resolve, reject) => {
      const onAbort = () => reject(abortError(signal));
      signal.addEventListener('abort', onAbort, { once: true });
      promise.then(resolve, reject).finally(() => signal.removeEventListener('abort', onAbort));
    });
  }

  async function* modelStream(payload, opts) {
    const signal = opts && opts.signal;
    const useRelay = !!relayUrl() && typeof WebSocket === 'function' && Date.now() >= relayDownUntil;
    const body = useRelay ? Object.assign({}, payload, { relay: 1 }) : payload;
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal,
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw httpError((data && data.error) || ('The request failed (status code ' + res.status + ').'), res.status);
    }
    if ((res.headers.get('content-type') || '').includes('text/event-stream')) {
      yield* sseEvents(res, signal);
      return;
    }
    const data = await res.json().catch(() => ({}));
    if (data && data.relay && data.relay.token) {
      let conn;
      try {
        conn = await untilAborted(getConnection(), signal);
      } catch (err) {
        if (signal && signal.aborted) throw abortError(signal);
        relayDownUntil = Date.now() + RELAY_BACKOFF_MS; // the job file expires on its own
        console.warn('[AI Team] relay unavailable, streaming directly for a while:', err.message);
        yield* modelStream(payload, opts);
        return;
      }
      yield* relayEvents(conn, data.relay.token, signal);
      return;
    }
    if (data && data.ok === false) throw httpError(data.error || 'The request failed.', res.status);
    yield { final: data }; // a plain JSON answer (not used by the current server, kept for safety)
    yield { done: true };
  }

  /** Direct transport: the Server-Sent-Events body of the POST. */
  async function* sseEvents(res, signal) {
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let terminal = false;
    try {
      yield { connected: true };
      while (true) {
        const { done, value } = await reader.read();
        if (done) { terminal = true; return; }
        buffer += decoder.decode(value, { stream: true });
        const rows = buffer.split('\n');
        buffer = rows.pop() || '';
        for (const row of rows) {
          const line = row.trim();
          if (!line.startsWith('data:')) continue; // ": ping" keep-alives and padding
          const data = line.slice(5).trim();
          if (data === '[DONE]') { terminal = true; yield { done: true }; return; }
          let obj;
          try { obj = JSON.parse(data); } catch (_) { continue; }
          if (obj.error || obj.final) terminal = true; // the server finishes on its own; don't cut it short
          yield obj;
        }
      }
    } finally {
      if (!terminal && !(signal && signal.aborted)) reader.cancel().catch(() => {});
    }
  }

  /** Relay transport: this request's events on the shared WebSocket. */
  async function* relayEvents(conn, token, signal) {
    const id = nextId++;
    const ch = channel();
    conn.requests.set(id, ch);
    const onAbort = () => ch.push({ aborted: true });
    if (signal) signal.addEventListener('abort', onAbort);
    let terminal = false;
    try {
      conn.ws.send(JSON.stringify({ t: 'start', id, token }));
      while (true) {
        if (signal && signal.aborted) throw abortError(signal);
        const msg = await ch.next();
        if (msg.aborted) throw abortError(signal);
        if (msg.lost) { terminal = true; throw new Error('The connection to the relay was lost.'); }
        if (msg.started) { yield { connected: true }; continue; }
        if (msg.e) {
          if (msg.e.error || msg.e.final) terminal = true;
          // The relay itself failed (no worker ran, or it could not run the job):
          // the next calls stream directly for a while, as when the socket is down.
          if (msg.e.error && msg.e.relay) relayDownUntil = Date.now() + RELAY_BACKOFF_MS;
          yield msg.e;
          continue;
        }
        if (msg.done) { terminal = true; yield { done: true }; return; }
      }
    } finally {
      if (signal) signal.removeEventListener('abort', onAbort);
      conn.requests.delete(id);
      if (!terminal && !conn.closed) {
        try { conn.ws.send(JSON.stringify({ t: 'cancel', id })); } catch (_) { /* socket gone: the relay cancels anyway */ }
      }
    }
  }

  window.AIT = Object.assign(window.AIT || {}, { modelStream });
})();
