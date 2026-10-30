/**
 * estimate.test.mjs — unit tests for the token and cost estimate of the
 * settings page (assets/js/estimate.js) and the participant's name in the
 * prompts.
 *
 *   node --test app/tests
 *
 * The same counting rules exist in PHP (includes/pricing.php
 * pricing_estimate_tokens) for the data page; the expected raw counts
 * below were produced by the PHP version, so a change on one side that is
 * not made on the other fails here.
 *
 * @author Marc Becker
 */

import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = {
  CHAT_CONFIG: {},
  location: { search: '', pathname: '/app/settings.php', hash: '' },
  history: { replaceState() {} },
};

const {
  estimateTokens, estimateRewrites, rewriteCost, conversationCost, priceFor, tokenFamily, TOKEN_FACTORS,
} = await import('../assets/js/estimate.js');
const { formatUsd, formatUsdShort, formatTokens } = await import('../assets/js/format.js');
const { configureTeam, TEAM } = await import('../assets/js/chat/config.js');
const { describeLine } = await import('../assets/js/chat/script.js');

// ---- Counting rules ----------------------------------------------------------------

test('raw token counts match the PHP implementation (includes/pricing.php)', () => {
  const cases = [
    ['', 0],
    ['Hello everyone!', 3],
    ['  +5s BLUE (typing 3s): Hello everyone!\n  +12s GREEN: hey', 22],
    ['{"m":[{"s":"b1","c":"yeah good point, let me think about that","p":3,"d":3}]}', 29],
    ['Wir untersuchen, wie Neulinge in Teams ihre Ideen äußern. Alle schreiben auf Deutsch.', 19],
    ['Todos los compañeros escriben en español, ¿verdad? ¡Sí!', 18],
    ['Emoji test 😀👍 and dashes – — … “quotes”', 18],
    ['123 4567 89012345 3.14159', 10],
    ['   multiple   spaces\n\n\nand newlines\t\ttabs', 10],
    ['SUPERCALIFRAGILISTIC expialidocious internationalization', 14],
    ['日本語のテキストも少し', 9],
  ];
  for (const [text, expected] of cases) {
    assert.equal(estimateTokens(text, 'raw'), expected, JSON.stringify(text));
  }
});

test('tokenizer families scale the raw count', () => {
  const text = 'The teammates react warmly to every idea the participant brings up. '.repeat(20);
  const raw = estimateTokens(text, 'raw');
  assert.equal(estimateTokens(text, 'openai'), Math.round(raw * TOKEN_FACTORS.openai));
  assert.ok(estimateTokens(text, 'claude-new') > estimateTokens(text, 'claude'));
  assert.ok(estimateTokens(text, 'claude') > estimateTokens(text, 'openai'));
});

test('models map to their tokenizer family and price row', () => {
  assert.equal(tokenFamily('openai', 'gpt-6-luna'), 'openai');
  assert.equal(tokenFamily('custom', 'gpt-5.6-sol'), 'openai');
  assert.equal(tokenFamily('anthropic', 'claude-haiku-4-5-20251001'), 'claude');
  assert.equal(tokenFamily('anthropic', 'claude-sonnet-4-6'), 'claude');
  assert.equal(tokenFamily('anthropic', 'claude-opus-4-8'), 'claude-new');
  assert.equal(tokenFamily('anthropic', 'claude-fable-5-1'), 'claude-new');

  const prices = {
    openai: { 'gpt-5': { input: 1.25 }, 'gpt-5-mini': { input: 0.25 }, 'gpt-5.6-sol': { input: 4 } },
    anthropic: { 'claude-haiku-4-5': { input: 1 } },
  };
  assert.equal(priceFor(prices, 'openai', 'gpt-5-mini-2025-08-07').model, 'gpt-5-mini'); // dated snapshot → its family
  assert.equal(priceFor(prices, 'openai', 'gpt-5').model, 'gpt-5');
  assert.equal(priceFor(prices, 'openai', 'gpt-5.6-sol').model, 'gpt-5.6-sol');
  assert.equal(priceFor(prices, 'anthropic', 'claude-haiku-4-5-20251001').model, 'claude-haiku-4-5');
  assert.equal(priceFor(prices, 'openai', 'gpt-4o'), null);
  assert.equal(priceFor(prices, 'custom', 'gpt-5-mini'), null); // gateways have no list price
});

// ---- Rewrites --------------------------------------------------------------------------

const ROLES = {
  bot1: { name: 'Anna', color: '#3b82f6' },
  bot2: { name: 'Ben', color: '#22c55e' },
  human: { name: 'Sam', promptName: 'SAM', color: '#eab308' },
};
function script(n) {
  const messages = [];
  for (let i = 0; i < n; i++) {
    messages.push({ id: i + 1, sender: i % 2 ? 'bot2' : 'bot1', content: 'Message number ' + (i + 1) + ' about the coworking concept and its features', send_at: 5 + i * 8, typing_pct: 60, is_empty: false });
  }
  return { messages };
}
const SCAFFOLD = { system: '## Output format\nRespond with ONLY compact JSON.', user: '## Conversation\n## Your task\nWrite the rest.' };

test('no estimate without at least two lines', () => {
  assert.equal(estimateRewrites({ roles: ROLES, script: '', scaffold: SCAFFOLD }), null);
  assert.equal(estimateRewrites({ roles: ROLES, script: script(1), scaffold: SCAFFOLD }), null);
  assert.equal(estimateRewrites({ roles: ROLES, script: '{not json', scaffold: SCAFFOLD }), null);
});

test('early rewrites write more than late ones; the system part is the same for all', () => {
  const r = estimateRewrites({ roles: ROLES, script: JSON.stringify(script(40)), instructions: 'Be friendly. '.repeat(100), context: '', scaffold: SCAFFOLD, family: 'openai', messages: 6 });
  assert.equal(r.lines, 40);
  assert.ok(r.early.output > r.late.output * 2, 'most of the conversation is still ahead early on');
  assert.equal(r.early.system, r.late.system);
  assert.equal(r.conversation.items.length, 6);
  assert.equal(r.conversation.rewrites, 6);
  assert.equal(r.conversation.input, r.conversation.items.reduce((s, x) => s + x.input, 0));
});

test('the study context is not part of the rewrites (the director never sees it)', () => {
  const base = { roles: ROLES, script: script(20), instructions: 'Be friendly.', scaffold: SCAFFOLD, family: 'openai', messages: 4 };
  const withContext = estimateRewrites({ ...base, context: 'All teammates write in Spanish. '.repeat(10) });
  assert.deepEqual(withContext, estimateRewrites(base));
});

test('OpenAI costs follow the prompt cache; Anthropic is billed without it', () => {
  const price = { input: 0.10, cached: 0.01, write: 0.125, output: 0.50 };
  const r = { input: 6000, output: 1000, system: 3500 };
  // First rewrite: everything is written to the cache (1.25x input on GPT-5.6+).
  assert.equal(rewriteCost(price, 'openai', r, false).toFixed(6), ((6000 * 0.125 + 1000 * 0.5) / 1e6).toFixed(6));
  // Later: the system part is read from the cache, the rest written.
  assert.equal(rewriteCost(price, 'openai', r, true).toFixed(6), ((3500 * 0.01 + 2500 * 0.125 + 1000 * 0.5) / 1e6).toFixed(6));
  // Short prompts are not cached at all.
  assert.equal(rewriteCost(price, 'openai', { input: 800, output: 100, system: 500 }, true), (800 * 0.1 + 100 * 0.5) / 1e6);
  // Anthropic: no cache_control is sent, so the plain input price applies.
  assert.equal(rewriteCost(price, 'anthropic', r, true), (6000 * 0.1 + 1000 * 0.5) / 1e6);
  const items = [r, r, r];
  assert.equal(conversationCost(price, 'openai', items), rewriteCost(price, 'openai', r, false) + 2 * rewriteCost(price, 'openai', r, true));
  assert.equal(rewriteCost(null, 'openai', r, true), null);
});

test('money and token formats', () => {
  assert.equal(formatUsd(0.00148), '$0.0015');
  assert.equal(formatUsd(0.024), '$0.024');
  assert.equal(formatUsd(2.4321), '$2.43');
  assert.equal(formatUsd(0), '$0.00');
  assert.equal(formatTokens(48536), '48,536');
  assert.equal(formatTokens(1234567), '1.23M');
  assert.equal(formatTokens(47920000), '47.9M');
  assert.equal(formatTokens(999999999), '1.00B'); // never "1000.0M"
  assert.equal(formatTokens(4792000000), '4.79B');
});

test('headline amounts: no trailing zero below one dollar, whole dollars from 100 on', () => {
  assert.equal(formatUsdShort(0), '$0.00');
  assert.equal(formatUsdShort(0.0042), '$0.0042');
  assert.equal(formatUsdShort(0.0188), '$0.019');
  assert.equal(formatUsdShort(0.42), '$0.42');
  assert.equal(formatUsdShort(0.5), '$0.50');
  assert.equal(formatUsdShort(0.474), '$0.474');
  assert.equal(formatUsdShort(0.9996), '$1.00');
  assert.equal(formatUsdShort(1.884), '$1.88');
  assert.equal(formatUsdShort(48.02), '$48.02');
  assert.equal(formatUsdShort(188.4), '$188');
  assert.equal(formatUsdShort(47418.3), '$47,418');
  assert.equal(formatUsdShort(null), '');
  assert.equal(formatUsdShort(NaN), '');
});

test('more messages per participant cost more (the first number field of the costs card)', () => {
  const price = { input: 0.25, cached: 0.025, write: 0.25, output: 2.00 };
  const setup = { roles: ROLES, script: script(40), instructions: 'Be friendly. '.repeat(400), scaffold: SCAFFOLD, family: 'openai' };
  const cost = (messages) => conversationCost(price, 'openai', estimateRewrites({ ...setup, messages }).conversation.items);
  assert.ok(cost(1) > 0);
  assert.ok(cost(8) > cost(4));
  assert.ok(cost(12) > cost(8));
});

// ---- The participant's name in the prompts ---------------------------------------------

test('a renamed participant appears under their name in the prompt lines', () => {
  configureTeam(ROLES);
  assert.equal(TEAM.promptNames.human, 'SAM');
  assert.equal(describeLine('human', 'hello', 3000), '  +3s SAM: hello');
  configureTeam({ bot1: { name: 'Anna' } }); // no participant entry: the classic YELLOW
  assert.equal(TEAM.promptNames.human, 'YELLOW');
});
