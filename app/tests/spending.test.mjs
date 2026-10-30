/**
 * spending.test.mjs — unit tests for what a study has cost so far
 * (assets/js/spending.js), the part that the settings page (Step 4) and the
 * "Costs" dialog of the data page share, and for the amount formats of
 * assets/js/format.js.
 *
 *   node --test app/tests
 *
 * @author Marc Becker
 */

import test from 'node:test';
import assert from 'node:assert/strict';

const { spendingParts, amountOf, partsText, costCellText, priceBasisText } = await import('../assets/js/spending.js');
const { countText, formatUsdShort, formatTokens } = await import('../assets/js/format.js');

/** A usage bucket as includes/usage.php sends it (usage_public). */
function bucket(over = {}) {
  return Object.assign({ calls: 0, input: 0, output: 0, cached: 0, cache_write: 0, cost: null,
    priced_calls: 0, unpriced_calls: 0, estimated_calls: 0 }, over);
}
const conversations = [
  { conversation_id: 'R1', is_test: false }, { conversation_id: 'R2', is_test: false },
  { conversation_id: 'TEST_a', is_test: true },
];
const usage = {
  as_of: '2026-09-24',
  all: bucket({ calls: 9, input: 60000, output: 9000, cost: 0.03, priced_calls: 9, estimated_calls: 1 }),
  real: bucket({ calls: 6, input: 40000, output: 6000, cost: 0.02, priced_calls: 6, estimated_calls: 1 }),
  assistants: bucket({ calls: 2, input: 7000, output: 2600, cost: 0.002, priced_calls: 2 }),
  total: bucket({ calls: 11, input: 67000, output: 11600, cost: 0.032, priced_calls: 11, estimated_calls: 1 }),
};

test('the parts: conversations with participants, test conversations (all minus real), assistants, total', () => {
  const p = spendingParts(usage, conversations);
  assert.equal(p.real.count, 2);
  assert.equal(p.tests.count, 1);
  assert.equal(p.tests.calls, 3);
  assert.equal(p.tests.input, 20000);
  assert.equal(p.tests.output, 3000);
  assert.equal(p.tests.estimated_calls, 0);
  assert.equal(p.tests.cost.toFixed(6), (0.01).toFixed(6));
  assert.equal(p.assistants, usage.assistants);
  assert.equal(p.total, usage.total);
});

test('test conversations without a priced call have no cost (not zero)', () => {
  const noPrice = Object.assign({}, usage, {
    all: bucket({ calls: 9, input: 60000, output: 9000, cost: 0.02, priced_calls: 6, unpriced_calls: 3 }),
    real: bucket({ calls: 6, input: 40000, output: 6000, cost: 0.02, priced_calls: 6 }),
  });
  const p = spendingParts(noPrice, conversations);
  assert.equal(p.tests.cost, null);
  assert.equal(p.tests.unpriced_calls, 3);
  assert.equal(costCellText(p.tests), 'no price');
});

test('amounts: money, an average, "about" for estimates, tokens without a price, nothing spent', () => {
  const p = spendingParts(usage, conversations);
  assert.deepEqual(amountOf(p.total), { text: formatUsdShort(0.032), about: true });
  assert.deepEqual(amountOf(p.real, p.real.count), { text: '$0.01', about: true });
  assert.deepEqual(amountOf(bucket()), { text: '$0.00', about: false });
  assert.deepEqual(amountOf(bucket({ calls: 2, input: 30000, output: 10101 })), { text: '40,101 tokens', about: false });
  assert.deepEqual(amountOf(bucket({ calls: 2, input: 30000, output: 10000 }), 2), { text: '20,000 tokens', about: false });
  assert.equal(costCellText(bucket()), '–');
  assert.equal(costCellText(p.real), 'about $0.020');
  assert.equal(costCellText(p.assistants), '$0.002');
});

test('the parts in words, with the place of the assistants given by the page', () => {
  const p = spendingParts(usage, conversations);
  assert.equal(partsText(p, 'in Step 3'), '2 conversations with participants, 1 test conversation and the AI Assistant in Step 3');
  assert.equal(partsText(p, 'in the settings', { real: false }), '1 test conversation and the AI Assistant in the settings');
  const onlyReal = spendingParts(Object.assign({}, usage, { assistants: bucket() }), conversations.slice(0, 2));
  assert.equal(partsText(onlyReal, 'in Step 3'), '2 conversations with participants');
  assert.equal(partsText(onlyReal, 'in Step 3', { real: false }), '');
});

test('what the amounts are based on', () => {
  assert.match(priceBasisText(usage), /^The costs use the list prices of the providers on 24 September 2026\./);
  const gateway = Object.assign({}, usage, { total: bucket({ calls: 3, input: 100, output: 50, unpriced_calls: 3 }) });
  assert.match(priceBasisText(gateway), /^No list price is known/);
  const partly = Object.assign({}, usage, { total: bucket({ calls: 3, input: 100, output: 50, cost: 0.01, priced_calls: 2, unpriced_calls: 1 }) });
  assert.match(priceBasisText(partly), /are not included\.$/);
});

test('format.js writes tokens and counts', () => {
  assert.equal(formatTokens(1234567), '1.23M');
  assert.equal(countText(1, 'participant'), '1 participant');
  assert.equal(countText(1200, 'participant'), '1,200 participants');
});
