/**
 * embed.test.mjs — tests that the participant chat can be embedded in a survey
 * (an iframe in a Qualtrics question, for example): the chat page allows
 * framing by any site, and neither it nor the two endpoints it talks to set a
 * cookie. The page is served by PHP's built-in server; no database is needed,
 * because the headers are sent before the project is loaded. Skipped when no
 * `php` binary is on the PATH.
 *
 *   node --test app/tests
 *
 * @author Marc Becker
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const hasPhp = spawnSync('php', ['-v'], { stdio: 'ignore' }).status === 0;

function freePort() {
  return new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.on('error', reject);
    probe.listen(0, '127.0.0.1', () => {
      const { port } = probe.address();
      probe.close(() => resolve(port));
    });
  });
}

/** Run fn(baseUrl) against PHP's built-in server serving the project root. */
async function withPhpServer(fn) {
  const port = await freePort();
  const server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', root], { stdio: 'ignore' });
  const base = 'http://127.0.0.1:' + port + '/';
  try {
    for (let attempt = 0; attempt < 50; attempt++) {
      try { await fetch(base + 'favicon.ico'); break; } catch (_) { await new Promise((r) => setTimeout(r, 100)); }
    }
    await fn(base);
  } finally {
    server.kill();
  }
}

test('the chat page may be framed by any site and sets no cookie', { skip: !hasPhp && 'php not found on the PATH' }, async () => {
  await withPhpServer(async (base) => {
    const page = await fetch(base + 'app/aiTeam.php?project=ZZZZZZZZZZZZZZZZ&conversationID=TEST', { redirect: 'manual' });
    assert.equal(page.headers.get('content-security-policy'), 'frame-ancestors *', 'every site may embed the chat');
    assert.equal(page.headers.get('x-frame-options'), null, 'nothing forbids framing');
    assert.equal(page.headers.get('set-cookie'), null, 'the chat page opens no session');
    assert.ok([200, 404].includes(page.status), 'the page or its notice renders without a database');
  });
});

test('the participant endpoints open no session either', { skip: !hasPhp && 'php not found on the PATH' }, async () => {
  await withPhpServer(async (base) => {
    for (const endpoint of ['app/api/director.php', 'app/api/save_message.php']) {
      const res = await fetch(base + endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      assert.equal(res.status, 400, endpoint + ' refuses an empty request');
      assert.equal(res.headers.get('set-cookie'), null, endpoint + ' sets no cookie');
      assert.equal(res.headers.get('x-frame-options'), null, endpoint + ' forbids no framing');
    }
  });
});
