/**
 * relay.test.mjs — tests for the WebSocket relay (app/relay/): frame handling
 * and the server's request lifecycle, with a fake worker instead of PHP
 * (tests/fixtures/fake-worker.mjs).
 *
 *   node --test app/tests
 *
 * @author Marc Becker
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import net from 'node:net';
import { randomBytes } from 'node:crypto';
import { mkdtempSync, readFileSync, existsSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { OP, acceptKey, encodeFrame, createFrameParser } from '../relay/ws.js';
import { createRelayServer } from '../relay/server.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const token = (first) => first + randomBytes(32).toString('hex').slice(1);

// ---- Frames ------------------------------------------------------------------------

test('acceptKey matches the RFC 6455 example', () => {
  assert.equal(acceptKey('dGhlIHNhbXBsZSBub25jZQ=='), 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=');
});

test('frames of every length class round-trip, masked, in arbitrary chunks', () => {
  for (const size of [0, 5, 125, 126, 1000, 65535, 70000]) {
    const payload = randomBytes(size);
    const got = [];
    const parser = createFrameParser({ maxMessageBytes: 100000, onMessage: (op, p) => got.push([op, p]), onError: (r) => assert.fail(r) });
    const frame = encodeFrame(OP.BINARY, payload, true);
    for (let i = 0; i < frame.length; i += 7) parser.push(frame.subarray(i, i + 7));
    assert.equal(got.length, 1);
    assert.equal(got[0][0], OP.BINARY);
    assert.ok(got[0][1].equals(payload), 'payload intact for size ' + size);
  }
});

test('fragmented text is reassembled; control frames pass through in between', () => {
  const got = [];
  const parser = createFrameParser({ onMessage: (op, p) => got.push([op, p.toString()]), onError: (r) => assert.fail(r) });
  const part = (fin, op, text) => { const f = encodeFrame(op, text, true); if (!fin) f[0] &= 0x7f; return f; };
  parser.push(Buffer.concat([part(false, OP.TEXT, 'hel'), encodeFrame(OP.PING, 'x', true), part(false, OP.CONT, 'lo '), part(true, OP.CONT, 'world')]));
  assert.deepEqual(got, [[OP.PING, 'x'], [OP.TEXT, 'hello world']]);
});

test('protocol violations are rejected', () => {
  const errors = [];
  createFrameParser({ onMessage: () => {}, onError: (r) => errors.push(r) }).push(encodeFrame(OP.TEXT, 'hi', false));
  createFrameParser({ maxMessageBytes: 10, onMessage: () => {}, onError: (r) => errors.push(r) }).push(encodeFrame(OP.TEXT, 'x'.repeat(11), true));
  assert.deepEqual(errors, ['unmasked client frame', 'frame too large']);
});

// ---- Server --------------------------------------------------------------------------

/** Minimal WebSocket client over a raw socket (Node 20 has no built-in client). */
function connect(port, origin = 'http://localhost:8020') {
  return new Promise((resolve, reject) => {
    const socket = net.connect(port, '127.0.0.1');
    const messages = [];
    let waiters = [];
    let handshake = Buffer.alloc(0);
    let upgraded = false;
    const parser = createFrameParser({
      requireMask: false,
      maxMessageBytes: 1 << 20,
      onError: reject,
      onMessage: (op, payload) => {
        if (op === OP.PING) { socket.write(encodeFrame(OP.PONG, payload, true)); return; }
        if (op !== OP.TEXT) return;
        messages.push(JSON.parse(payload.toString()));
        waiters.forEach((w) => w());
      },
    });
    const client = {
      messages,
      closed: false,
      send: (obj) => socket.write(encodeFrame(OP.TEXT, JSON.stringify(obj), true)),
      close: () => socket.destroy(),
      /** Resolve when predicate(messages) is true (or fail after ms). */
      until(predicate, ms = 3000) {
        return new Promise((ok, fail) => {
          const check = () => { if (predicate(messages)) { waiters = waiters.filter((w) => w !== check); clearTimeout(t); ok(messages); } };
          const t = setTimeout(() => fail(new Error('timeout; got ' + JSON.stringify(messages))), ms);
          waiters.push(check);
          check();
        });
      },
    };
    socket.on('connect', () => {
      socket.write(`GET /relay/ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n` +
        `Sec-WebSocket-Key: ${randomBytes(16).toString('base64')}\r\nSec-WebSocket-Version: 13\r\nOrigin: ${origin}\r\n\r\n`);
    });
    socket.on('data', (chunk) => {
      if (upgraded) return parser.push(chunk);
      handshake = Buffer.concat([handshake, chunk]);
      const end = handshake.indexOf('\r\n\r\n');
      if (end === -1) return;
      const head = handshake.subarray(0, end).toString();
      if (!head.startsWith('HTTP/1.1 101')) { socket.destroy(); return reject(new Error(head.split('\r\n')[0])); }
      upgraded = true;
      resolve(client);
      const rest = handshake.subarray(end + 4);
      if (rest.length) parser.push(rest);
    });
    socket.on('close', () => { client.closed = true; });
    socket.on('error', reject);
  });
}

async function startRelay(extra = {}) {
  const logFile = path.join(mkdtempSync(path.join(tmpdir(), 'relay-test-')), 'worker.log');
  process.env.FAKE_WORKER_LOG = logFile;
  const relay = createRelayServer({
    port: 0, host: '127.0.0.1', php: process.execPath, worker: path.join(here, 'fixtures', 'fake-worker.mjs'),
    origins: 'http://localhost:8020', quiet: true, ...extra,
  });
  const port = await relay.listen();
  const workerLog = () => (existsSync(logFile) ? readFileSync(logFile, 'utf8') : '');
  return { relay, port, workerLog };
}

const forId = (msgs, id) => msgs.filter((m) => m.id === id);

test('a job streams its events in order and ends with done', async () => {
  const { relay, port } = await startRelay();
  const c = await connect(port);
  c.send({ t: 'start', id: 1, token: token('a') });
  const msgs = await c.until((m) => m.some((x) => x.id === 1 && x.done));
  assert.deepEqual(forId(msgs, 1), [
    { id: 1, started: true },
    { id: 1, e: { d: '{"m":[' } },
    { id: 1, e: { d: '{"s":"b1","c":"hi"}' } },
    { id: 1, e: { d: ']}' } },
    { id: 1, done: true },
  ]);
  c.close();
  await relay.close();
});

test('cancel closes the worker input; the worker stops and nothing more is sent', async () => {
  const { relay, port, workerLog } = await startRelay();
  const c = await connect(port);
  c.send({ t: 'start', id: 7, token: token('b') });
  await c.until((m) => forId(m, 7).length >= 3);
  c.send({ t: 'cancel', id: 7 });
  await sleep(400);
  assert.match(workerLog(), /b-aborted/);
  const count = forId(c.messages, 7).length;
  await sleep(200);
  assert.equal(forId(c.messages, 7).length, count, 'no events after cancel');
  assert.ok(!forId(c.messages, 7).some((m) => m.done), 'a cancelled request gets no done');
  assert.equal(relay.stats().active, 0);
  c.close();
  await relay.close();
});

test('a page that disconnects cancels its running jobs', async () => {
  const { relay, port, workerLog } = await startRelay();
  const c = await connect(port);
  c.send({ t: 'start', id: 1, token: token('b') });
  await c.until((m) => forId(m, 1).length >= 2);
  c.close();
  await sleep(400);
  assert.match(workerLog(), /b-aborted/);
  assert.equal(relay.stats().active, 0);
  await relay.close();
});

test('a crashing worker is reported as a relay error, then done', async () => {
  const { relay, port } = await startRelay();
  const c = await connect(port);
  c.send({ t: 'start', id: 2, token: token('c') });
  const msgs = forId(await c.until((m) => m.some((x) => x.id === 2 && x.done)), 2);
  assert.deepEqual(msgs[1], { id: 2, e: { d: 'partial' } });
  assert.equal(msgs[2].e.relay, true);
  assert.match(msgs[2].e.error, /stopped unexpectedly/);
  assert.equal(msgs[2].e.final.ok, false);
  c.close();
  await relay.close();
});

test('malformed tokens and too many parallel requests are refused without starting a worker', async () => {
  const { relay, port } = await startRelay({ maxPerConnection: 1 });
  const c = await connect(port);
  c.send({ t: 'start', id: 1, token: 'not-a-token' });
  c.send({ t: 'start', id: 2, token: token('b') });
  c.send({ t: 'start', id: 3, token: token('a') });
  const msgs = await c.until((m) => m.some((x) => x.id === 1 && x.done) && m.some((x) => x.id === 3 && x.done));
  assert.equal(forId(msgs, 1)[0].e.code, 400);
  assert.equal(forId(msgs, 3)[0].e.code, 429);
  c.close();
  await relay.close();
});

test('beyond the worker limit requests wait in line and then run', async () => {
  const { relay, port, workerLog } = await startRelay({ maxWorkers: 1 });
  const c = await connect(port);
  c.send({ t: 'start', id: 1, token: token('d') });
  c.send({ t: 'start', id: 2, token: token('d') });
  await sleep(100);
  assert.deepEqual(relay.stats(), { active: 1, queued: 1, connections: 1 });
  await c.until((m) => m.some((x) => x.id === 1 && x.done) && m.some((x) => x.id === 2 && x.done), 5000);
  assert.equal(workerLog().match(/d-done/g).length, 2);
  c.close();
  await relay.close();
});

test('foreign origins cannot open the relay', async () => {
  const { relay, port } = await startRelay();
  await assert.rejects(connect(port, 'https://evil.example'), /403/);
  await relay.close();
});

test('with a job directory, a token without a job file is refused before a worker starts', async () => {
  const jobDir = mkdtempSync(path.join(tmpdir(), 'relay-jobs-'));
  const { relay, port } = await startRelay({ jobDir });
  const c = await connect(port);
  const real = token('a');
  writeFileSync(path.join(jobDir, real + '.json'), '{}'); // the fake worker does not read it
  c.send({ t: 'start', id: 1, token: token('a') });       // made up: no file
  c.send({ t: 'start', id: 2, token: real });
  const msgs = await c.until((m) => m.some((x) => x.id === 1 && x.done) && m.some((x) => x.id === 2 && x.done));
  assert.equal(forId(msgs, 1)[0].e.code, 410);
  assert.ok(!forId(msgs, 1).some((x) => x.started), 'no worker for the made-up token');
  assert.ok(forId(msgs, 2).some((x) => x.started), 'the real job runs');
  c.close();
  await relay.close();
});

test('a full waiting line refuses further requests (503)', async () => {
  const { relay, port } = await startRelay({ maxWorkers: 1, maxQueue: 1, maxPerConnection: 4 });
  const c = await connect(port);
  c.send({ t: 'start', id: 1, token: token('d') }); // runs
  c.send({ t: 'start', id: 2, token: token('d') }); // waits
  c.send({ t: 'start', id: 3, token: token('d') }); // refused
  const msgs = await c.until((m) => [1, 2, 3].every((id) => m.some((x) => x.id === id && x.done)), 5000);
  assert.equal(forId(msgs, 3)[0].e.code, 503);
  assert.ok(forId(msgs, 2).some((x) => x.started), 'the waiting request ran');
  c.close();
  await relay.close();
});
