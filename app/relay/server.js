/**
 * server.js — the WebSocket relay for model calls.
 *
 * Why: the production host cuts every HTTP response after 45 seconds, and a
 * streaming PHP request occupies one of the web server's few PHP connections
 * for as long as the model writes. WebSocket connections are not cut, and the
 * work behind them runs outside the web server.
 *
 * What it does — transport only, no model logic:
 *   1. The browser asks api/director.php as always, adding "relay": 1. PHP
 *      validates, authorizes and prepares the call exactly as before, stores it
 *      as a one-time job file and answers with a token.
 *   2. Over one WebSocket per page the browser sends {"t":"start","id":N,"token":…}.
 *   3. The relay starts `php worker.php <token>` (command-line PHP), which runs
 *      the same job code as the direct path and prints the same events.
 *   4. Every event line goes to the browser as {"id":N,"e":{…}}; the end as
 *      {"id":N,"done":true}. {"id":N,"started":true} confirms the worker runs.
 *   5. {"t":"cancel","id":N}, or the socket closing, ends the worker's stdin:
 *      PHP notices within about a second, stops the upstream call and records
 *      the call as aborted — the same behavior as a browser aborting a fetch.
 *
 * Configuration (environment variables):
 *   RELAY_PORT               listen port                     (default 8021)
 *   RELAY_HOST               listen address                  (default 0.0.0.0 — Uberspace requires it)
 *   RELAY_PATH               URL path prefix                 (default /relay)
 *   RELAY_PHP                PHP command-line binary         (default php)
 *   RELAY_WORKER             worker script                   (default worker.php next to this file)
 *   RELAY_ORIGINS            allowed page origins, comma-separated ('' = any)
 *   RELAY_MAX_WORKERS        simultaneous workers; more wait (default 40)
 *   RELAY_MAX_PER_CONNECTION requests one page may run at once (default 4)
 *   RELAY_MAX_JOB_S          hard cap for one worker          (default 900)
 *   RELAY_MAX_QUEUE          requests that may wait for a worker; more are refused (default 400)
 *   RELAY_JOB_DIR            the job directory of config.php; when set, a token without
 *                            a job file is refused before any worker starts
 *
 * Health check: GET <RELAY_PATH>/health → {"ok":true,"active":…,"queued":…,"connections":…}
 * Logs: one JSON object per line on stdout (the systemd journal on the server).
 *
 * Run: node app/relay/server.js
 *
 * @author Marc Becker
 */

import http from 'node:http';
import { existsSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';
import { OP, acceptKey, encodeFrame, encodeClose, createFrameParser } from './ws.js';

const HEARTBEAT_MS = 25000;       // ping every open socket; one missed pong closes it
const CANCEL_GRACE_MS = 20000;    // after stdin closes, how long PHP may take to log and exit
const MAX_CONTROL_BYTES = 8192;   // browser → relay messages are tiny

function log(msg, meta = {}) {
  process.stdout.write(JSON.stringify({ ts: new Date().toISOString(), msg, ...meta }) + '\n');
}

export function createRelayServer(opts = {}) {
  const here = path.dirname(fileURLToPath(import.meta.url));
  const cfg = {
    port: Number(opts.port ?? process.env.RELAY_PORT ?? 8021),
    host: opts.host ?? process.env.RELAY_HOST ?? '0.0.0.0',
    basePath: (opts.basePath ?? process.env.RELAY_PATH ?? '/relay').replace(/\/+$/, ''),
    php: opts.php ?? process.env.RELAY_PHP ?? 'php',
    worker: opts.worker ?? process.env.RELAY_WORKER ?? path.join(here, 'worker.php'),
    origins: String(opts.origins ?? process.env.RELAY_ORIGINS ?? '').split(',').map((s) => s.trim()).filter(Boolean),
    maxWorkers: Number(opts.maxWorkers ?? process.env.RELAY_MAX_WORKERS ?? 40),
    maxPerConnection: Number(opts.maxPerConnection ?? process.env.RELAY_MAX_PER_CONNECTION ?? 4),
    maxJobMs: Number(opts.maxJobS ?? process.env.RELAY_MAX_JOB_S ?? 900) * 1000, // > the assistants' own cap (LLM_ASSIST_S + summary)
    maxQueue: Number(opts.maxQueue ?? process.env.RELAY_MAX_QUEUE ?? 400),
    jobDir: opts.jobDir ?? process.env.RELAY_JOB_DIR ?? '',
    quiet: !!opts.quiet,
  };
  const note = cfg.quiet ? () => {} : log;

  const connections = new Set();
  const queue = [];   // requests waiting for a worker slot
  let active = 0;     // running workers
  let nextConnId = 1;

  // ---- HTTP: health check + WebSocket upgrade ----------------------------------

  const server = http.createServer((req, res) => {
    const url = (req.url || '').split('?')[0];
    if (req.method === 'GET' && url === cfg.basePath + '/health') {
      res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify({ ok: true, active, queued: queue.length, connections: connections.size, uptime_s: Math.round(process.uptime()) }));
      return;
    }
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Not found\n');
  });

  server.on('upgrade', (req, socket) => {
    const url = (req.url || '').split('?')[0];
    const reject = (status, text) => {
      socket.end(`HTTP/1.1 ${status} ${text}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n`);
    };
    if (!url.startsWith(cfg.basePath)) return reject(404, 'Not Found');
    const key = req.headers['sec-websocket-key'];
    if (String(req.headers.upgrade || '').toLowerCase() !== 'websocket' || !key || req.headers['sec-websocket-version'] !== '13') {
      return reject(400, 'Bad Request');
    }
    const origin = String(req.headers.origin || '');
    if (cfg.origins.length && !cfg.origins.includes(origin)) {
      note('origin-rejected', { origin });
      return reject(403, 'Forbidden');
    }
    socket.write('HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n' +
      'Sec-WebSocket-Accept: ' + acceptKey(key) + '\r\n\r\n');
    openConnection(socket, origin);
  });

  // ---- Connections -----------------------------------------------------------------

  function openConnection(socket, origin) {
    socket.setNoDelay(true);
    socket.setTimeout(0);
    const conn = { id: nextConnId++, socket, requests: new Map(), alive: true, closed: false };
    connections.add(conn);

    const send = (obj) => {
      if (!conn.closed) socket.write(encodeFrame(OP.TEXT, typeof obj === 'string' ? obj : JSON.stringify(obj)));
    };
    conn.send = send;

    const parser = createFrameParser({
      maxMessageBytes: MAX_CONTROL_BYTES,
      onError: (reason) => { note('protocol-error', { conn: conn.id, reason }); closeConnection(conn, 1002, 'protocol error'); },
      onMessage: (opcode, payload) => {
        conn.alive = true;
        if (opcode === OP.PING) return socket.write(encodeFrame(OP.PONG, payload));
        if (opcode === OP.PONG) return;
        if (opcode === OP.CLOSE) return closeConnection(conn, 1000, '');
        if (opcode !== OP.TEXT) return;
        let msg;
        try { msg = JSON.parse(payload.toString('utf8')); } catch (_) { return; }
        handleMessage(conn, msg);
      },
    });

    socket.on('data', (chunk) => parser.push(chunk));
    socket.on('close', () => closeConnection(conn));
    socket.on('error', () => closeConnection(conn));
  }

  function closeConnection(conn, code, reason) {
    if (conn.closed) return;
    if (code !== undefined) {
      try { conn.socket.write(encodeClose(code, reason)); } catch (_) { /* socket already gone */ }
    }
    conn.closed = true;
    connections.delete(conn);
    for (const req of conn.requests.values()) cancelRequest(req, 'disconnect');
    conn.requests.clear();
    conn.socket.end();
    setTimeout(() => conn.socket.destroy(), 1000).unref();
  }

  function handleMessage(conn, msg) {
    if (conn.closed) return; // a frame still in flight while the connection closed: nobody could cancel its worker
    const id = Number(msg && msg.id);
    if (!Number.isInteger(id) || id < 1) return;
    if (msg.t === 'cancel') {
      const req = conn.requests.get(id);
      if (req) cancelRequest(req, 'cancel');
      return;
    }
    if (msg.t !== 'start' || conn.requests.has(id)) return;
    if (!/^[a-f0-9]{64}$/.test(String(msg.token || ''))) return relayError(conn, id, 'Invalid relay token.', 400);
    if (conn.requests.size >= cfg.maxPerConnection) return relayError(conn, id, 'Too many simultaneous requests from this page.', 429);
    // Made-up tokens must not start workers or fill the queue ahead of real calls.
    if (cfg.jobDir && !existsSync(path.join(cfg.jobDir, msg.token + '.json'))) return relayError(conn, id, 'This request has expired. Please try again.', 410);
    if (active >= cfg.maxWorkers && queue.length >= cfg.maxQueue) return relayError(conn, id, 'The server is busy. Please try again in a moment.', 503);

    const req = { conn, id, token: msg.token, state: 'queued', child: null, sawDone: false, cancelled: false, startedAt: Date.now() };
    conn.requests.set(id, req);
    if (active < cfg.maxWorkers) runRequest(req);
    else queue.push(req);
  }

  /** A failure the relay itself detects, in the same shape a worker would report it. */
  function relayError(conn, id, message, code) {
    conn.send({ id, e: { error: message, code, relay: true, final: { ok: false, error: message } } });
    conn.send({ id, done: true });
  }

  // ---- Workers -----------------------------------------------------------------------

  function runRequest(req) {
    const { conn, id } = req;
    req.state = 'running';
    active++;
    const child = spawn(cfg.php, [cfg.worker, req.token], { stdio: ['pipe', 'pipe', 'pipe'] });
    req.child = child;
    child.stdin.on('error', () => {}); // the worker may exit before we close its stdin
    conn.send({ id, started: true });

    let lineBuffer = '';
    let stderr = '';
    child.stdout.setEncoding('utf8');
    child.stdout.on('data', (chunk) => {
      lineBuffer += chunk;
      let nl;
      while ((nl = lineBuffer.indexOf('\n')) !== -1) {
        const line = lineBuffer.slice(0, nl).trim();
        lineBuffer = lineBuffer.slice(nl + 1);
        if (!line.startsWith('data:') || req.cancelled) continue; // comments, pings
        const data = line.slice(5).trim();
        if (data === '[DONE]') {
          if (!req.sawDone) conn.send({ id, done: true });
          req.sawDone = true;
          continue;
        }
        if (data.startsWith('{')) conn.send('{"id":' + id + ',"e":' + data + '}'); // PHP wrote valid JSON
      }
    });
    child.stderr.setEncoding('utf8');
    child.stderr.on('data', (chunk) => { if (stderr.length < 2000) stderr += chunk; });

    const hardStop = setTimeout(() => child.kill('SIGKILL'), cfg.maxJobMs);
    let finished = false;
    const finish = (exitCode, signal, spawnError) => {
      if (finished) return;
      finished = true;
      clearTimeout(hardStop);
      clearTimeout(req.killTimer);
      active--;
      req.state = 'done';
      if (!req.sawDone && !req.cancelled) {
        const message = spawnError ? 'The relay could not start the model worker.' : 'The model worker stopped unexpectedly.';
        conn.send({ id, e: { error: message, code: 0, relay: true, final: { ok: false, error: message } } });
        conn.send({ id, done: true });
      }
      conn.requests.delete(id);
      note('job-end', {
        conn: conn.id, id, ms: Date.now() - req.startedAt, exit: exitCode, signal: signal || undefined,
        cancelled: req.cancelled || undefined, done: req.sawDone, error: spawnError || undefined,
        stderr: stderr ? stderr.slice(0, 500) : undefined,
      });
      pump();
    };
    child.on('error', (err) => finish(null, null, err.message));
    child.on('close', (code, signal) => finish(code, signal));
  }

  function cancelRequest(req, why) {
    if (req.cancelled || req.state === 'done') return;
    req.cancelled = true;
    if (req.state === 'queued') {
      const i = queue.indexOf(req);
      if (i !== -1) queue.splice(i, 1);
      req.state = 'done';
      req.conn.requests.delete(req.id);
      return;
    }
    note('job-cancel', { conn: req.conn.id, id: req.id, why });
    req.child.stdin.end(); // PHP sees EOF → stops the upstream call, logs "aborted", exits
    req.killTimer = setTimeout(() => {
      req.child.kill('SIGTERM');
      setTimeout(() => req.child.kill('SIGKILL'), 5000).unref();
    }, CANCEL_GRACE_MS);
    req.killTimer.unref();
  }

  function pump() {
    while (active < cfg.maxWorkers && queue.length) runRequest(queue.shift());
  }

  // ---- Heartbeat + lifecycle ---------------------------------------------------------------

  const heartbeat = setInterval(() => {
    for (const conn of connections) {
      if (!conn.alive) { closeConnection(conn); continue; }
      conn.alive = false;
      conn.socket.write(encodeFrame(OP.PING));
    }
  }, HEARTBEAT_MS);
  heartbeat.unref();

  return {
    config: cfg,
    stats: () => ({ active, queued: queue.length, connections: connections.size }),
    listen() {
      return new Promise((resolve) => server.listen(cfg.port, cfg.host, () => {
        note('relay-started', { host: cfg.host, port: server.address().port, path: cfg.basePath, php: cfg.php, worker: cfg.worker, origins: cfg.origins, max_workers: cfg.maxWorkers });
        resolve(server.address().port);
      }));
    },
    /** Stop accepting, cancel running work (PHP records it as aborted), resolve when workers are gone. */
    close() {
      clearInterval(heartbeat);
      server.close();
      for (const conn of [...connections]) closeConnection(conn, 1001, 'relay restarting');
      return new Promise((resolve) => {
        const check = () => (active === 0 ? resolve() : setTimeout(check, 100));
        check();
      });
    },
  };
}

// ---- Entry point --------------------------------------------------------------------------

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const relay = createRelayServer();
  relay.listen();
  const shutdown = (signal) => {
    log('relay-stopping', { signal, ...relay.stats() });
    setTimeout(() => process.exit(1), CANCEL_GRACE_MS + 2000).unref();
    relay.close().then(() => process.exit(0));
  };
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}
