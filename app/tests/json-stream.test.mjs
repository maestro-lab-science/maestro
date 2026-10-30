/**
 * json-stream.test.mjs — unit tests for reading a string value out of JSON
 * that arrives in pieces (assets/js/json-stream.js): the AI Assistant shows a
 * box text while the model still writes {"text":"…","note":"…"}.
 *
 *   node --test app/tests
 *
 * @author Marc Becker
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { createJsonStringReader } from '../assets/js/json-stream.js';

/** Feed the text in chunks of the given size and collect what the reader emits. */
function readInChunks(text, size, key = 'text') {
  const reader = createJsonStringReader(key);
  let out = '';
  for (let i = 0; i < text.length; i += size) out += reader.feed(text.slice(i, i + size));
  return { out, done: reader.done() };
}

const full = JSON.stringify({ text: 'Line one\nSay "hi" to Blue — then \\ stop.', note: 'Added a greeting' });

test('reads the value whatever the chunk size', () => {
  for (const size of [1, 2, 3, 7, 50, full.length]) {
    const { out, done } = readInChunks(full, size);
    assert.equal(out, 'Line one\nSay "hi" to Blue — then \\ stop.', 'chunk size ' + size);
    assert.equal(done, true);
  }
});

test('emits nothing until the key starts, and stops at the end of the value', () => {
  const reader = createJsonStringReader('text');
  assert.equal(reader.feed('{"no'), '');
  assert.equal(reader.feed('te":"x","te'), '');
  assert.equal(reader.feed('xt":"ab'), 'ab');
  assert.equal(reader.done(), false);
  assert.equal(reader.feed('c","more":"ignored"}'), 'c');
  assert.equal(reader.done(), true);
  assert.equal(reader.feed('"anything"'), '');
});

test('decodes \\u escapes split across chunks', () => {
  const { out } = readInChunks('{"text":"caf\\u00e9 \\u00fcber"}', 1);
  assert.equal(out, 'café über');
});

test('a skip answer has no text', () => {
  const { out, done } = readInChunks('{"skip":"The box already fits"}', 4);
  assert.equal(out, '');
  assert.equal(done, false);
});
