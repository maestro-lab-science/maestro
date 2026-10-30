/**
 * fake-worker.mjs — stand-in for app/relay/worker.php in the relay tests.
 *
 * The token's first character picks the behavior; the output follows the
 * worker's SSE line format ("data: {…}", "data: [DONE]").
 *
 * @author Marc Becker
 */
import { appendFileSync } from 'node:fs';

const token = process.argv[2] || '';
const marker = (text) => { if (process.env.FAKE_WORKER_LOG) appendFileSync(process.env.FAKE_WORKER_LOG, text + '\n'); };
const line = (obj) => process.stdout.write('data: ' + JSON.stringify(obj) + '\n\n');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

process.stdin.resume();
let stdinClosed = false;
process.stdin.on('end', () => { stdinClosed = true; });

switch (token[0]) {
  case 'a': // a normal stream
    process.stdout.write(': ping\n\n');
    line({ d: '{"m":[' });
    line({ d: '{"s":"b1","c":"hi"}' });
    line({ d: ']}' });
    process.stdout.write('data: [DONE]\n\n');
    process.exit(0);
    break;
  case 'b': // runs until cancelled (stdin closes), like a long model call
    marker('b-started');
    while (!stdinClosed) { line({ r: 'thinking' }); await sleep(50); }
    marker('b-aborted');
    process.exit(0);
    break;
  case 'c': // crashes mid-stream
    line({ d: 'partial' });
    await sleep(20);
    process.exit(3);
    break;
  case 'd': // slow but completes
    await sleep(300);
    marker('d-done');
    line({ final: { ok: true } });
    process.stdout.write('data: [DONE]\n\n');
    process.exit(0);
    break;
  default:
    process.exit(1);
}
