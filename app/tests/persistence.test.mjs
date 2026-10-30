/**
 * persistence.test.mjs — unit tests for the save queue of the participant chat
 * (assets/js/chat/persistence.js): batching under the browsers' 64 KiB limit
 * for keepalive request bodies, idempotent retries, batches the server
 * refuses, text the server could not decode, and the beacon on unload.
 *
 *   node --test app/tests
 *
 * @author Marc Becker
 */

import test from 'node:test';
import assert from 'node:assert/strict';

// persistence.js touches window (pagehide), navigator (sendBeacon) and fetch
// only when it runs; give it stand-ins that record what it sends.
const listeners = {};
const sent = [];      // bodies handed to fetch
const beacons = [];   // bodies handed to sendBeacon
let fetchOk = true;
let respond = null;   // (body) => HTTP status, when a test needs more than ok/503
let beaconAccepts = () => true;

globalThis.window = { addEventListener(type, cb) { listeners[type] = cb; } };
globalThis.navigator = {
  sendBeacon(url, blob) {
    if (!beaconAccepts(blob.size)) return false;
    beacons.push(blob.size);
    return true;
  },
};
globalThis.fetch = async (url, opts) => {
  sent.push(opts.body);
  const status = respond ? respond(opts.body) : (fetchOk ? 200 : 503);
  return { ok: status >= 200 && status < 300, status };
};

const { createSaveQueue, MAX_BATCH_BYTES, wellFormed } = await import('../assets/js/chat/persistence.js');
const log = { warn() {} };
const bytes = (s) => new TextEncoder().encode(s).length;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function drained(queue, limitMs = 5000) {
  const until = Date.now() + limitMs;
  while (queue.pendingCount() > 0 && Date.now() < until) await sleep(20);
  return queue.pendingCount() === 0;
}

test('small queues go out in one request', async () => {
  sent.length = 0;
  const queue = createSaveQueue({ projectId: 'TESTPROJECT000000', conversationId: 'TEST_small', log });
  const ids = ['hi', 'hello', 'how are you'].map((text) => queue.save({ sender: 'bot1', text, shownAt: new Date() }));
  await queue.flush();
  assert.equal(sent.length, 1);
  const payload = JSON.parse(sent[0]);
  assert.deepEqual(payload.messages.map((m) => m.client_id), ids);
  assert.equal(payload.conversation_id, 'TEST_small');
});

test('a long backlog is split into batches under the keepalive limit and nothing is lost', async () => {
  sent.length = 0;
  const queue = createSaveQueue({ projectId: 'TESTPROJECT000000', conversationId: 'TEST_backlog', log });
  const long = 'x'.repeat(1900); // the composer allows 2,000 characters per participant message
  const ids = [];
  for (let i = 0; i < 50; i++) ids.push(queue.save({ sender: i % 2 ? 'human' : 'bot1', text: long + i, shownAt: new Date() }));
  await queue.flush();
  assert.ok(await drained(queue), 'the queue drains');
  assert.ok(sent.length > 1, 'more than one request was needed');
  for (const body of sent) assert.ok(bytes(body) <= MAX_BATCH_BYTES, 'every request body stays under the limit');
  const delivered = sent.flatMap((body) => JSON.parse(body).messages.map((m) => m.client_id));
  assert.deepEqual(delivered, ids, 'every message is sent exactly once, in order');
});

test('a failed request keeps its messages for a retry', async () => {
  sent.length = 0;
  fetchOk = false;
  const queue = createSaveQueue({ projectId: 'TESTPROJECT000000', conversationId: 'TEST_retry', log });
  queue.save({ sender: 'human', text: 'kept [[//5]]', shownAt: new Date() });
  await queue.flush();
  assert.equal(queue.pendingCount(), 1, 'still queued after a 503');
  fetchOk = true;
  await queue.flush();
  assert.equal(queue.pendingCount(), 0);
  assert.equal(sent.length, 2);
  assert.equal(JSON.parse(sent[0]).messages[0].client_id, JSON.parse(sent[1]).messages[0].client_id, 'the retry carries the same id');
});

test('on unload the beacon sends the queue in accepted pieces and keeps what the browser refused', () => {
  beacons.length = 0;
  const queue = createSaveQueue({ projectId: 'TESTPROJECT000000', conversationId: 'TEST_beacon', log });
  const long = 'y'.repeat(1900);
  for (let i = 0; i < 40; i++) queue.save({ sender: 'bot2', text: long + i, shownAt: new Date() });
  queue.event({ message_client_id: 'abc', status: 'client-error', error: 'network error' });

  beaconAccepts = () => true;
  listeners.pagehide();
  assert.ok(beacons.length > 1, 'several beacons');
  for (const size of beacons) assert.ok(size <= MAX_BATCH_BYTES);
  assert.equal(queue.pendingCount(), 0, 'everything went out');

  // A browser that refuses the beacon (its keepalive budget is used up): the queue stays.
  beacons.length = 0;
  for (let i = 0; i < 3; i++) queue.save({ sender: 'bot2', text: 'later ' + i, shownAt: new Date() });
  beaconAccepts = () => false;
  listeners.pagehide();
  assert.equal(beacons.length, 0);
  assert.equal(queue.pendingCount(), 3, 'kept for a later flush');
  beaconAccepts = () => true;
});

test('a batch the server refuses goes one item at a time, and only the refused item is dropped', async () => {
  sent.length = 0;
  respond = (body) => (body.includes('BROKEN') ? 400 : 200);
  const queue = createSaveQueue({ projectId: 'TESTPROJECT000000', conversationId: 'TEST_refused', log });
  const ids = ['before', 'BROKEN', 'after'].map((text) => queue.save({ sender: 'bot1', text, shownAt: new Date() }));
  await queue.flush();
  assert.ok(await drained(queue), 'the queue drains instead of retrying the same batch forever');
  const stored = sent.filter((body) => !body.includes('BROKEN')).flatMap((body) => JSON.parse(body).messages.map((m) => m.client_id));
  assert.deepEqual(stored, [ids[0], ids[2]], 'the messages around the refused one are stored');
  respond = null;
});

test('one message above the batch limit and a pending event do not hang the queue', async () => {
  sent.length = 0;
  const queue = createSaveQueue({ projectId: 'TESTPROJECT000000', conversationId: 'TEST_large', log });
  queue.save({ sender: 'bot1', text: 'z'.repeat(MAX_BATCH_BYTES + 1000), shownAt: new Date() });
  queue.event({ message_client_id: 'abc', status: 'client-error', error: 'network error' });
  await queue.flush();
  assert.ok(await drained(queue));
  assert.equal(sent.length, 2, 'the large message goes alone, the event follows');
});

test('half of an emoji is replaced, so PHP can decode the body', async () => {
  assert.equal(wellFormed('great \ud83d'), 'great \ufffd');
  assert.equal(wellFormed('ok \ud83d\ude00 \ude00'), 'ok \ud83d\ude00 \ufffd');
  sent.length = 0;
  const queue = createSaveQueue({ projectId: 'TESTPROJECT000000', conversationId: 'TEST_surrogate', log });
  queue.save({ sender: 'bot1', text: 'great \ud83d', shownAt: new Date() });
  await queue.flush();
  assert.ok(!/\\ud83d/i.test(sent[0]), 'no lone surrogate escape in the request body');
});
