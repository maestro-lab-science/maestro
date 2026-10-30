/**
 * spending.js — what a study has cost so far, written the same way on the
 * settings page (Step 4, "Spent so far" in the costs card) and on the data
 * page (the "Costs" dialog).
 *
 * Both pages read the conversations of api/data.php?action=list (each with
 * is_test) and, from action=usage (a separate request, because estimating takes
 * a few seconds in large studies), the usage buckets of includes/usage.php, which are
 *   all          every conversation, the test conversations included
 *   real         the conversations with participants (no TEST id)
 *   assistants   the AI Assistant of the settings page
 *   total        all + assistants
 * each { calls, input, output, cached, cache_write, cost (USD at list prices,
 * null when nothing in it has a price), priced_calls, unpriced_calls,
 * estimated_calls (calls without the provider's token counts) }.
 *
 * The DOM helpers use AIT.el from app.js, which both pages load first.
 *
 * @author Marc Becker
 */

import { formatUsd, formatUsdShort, formatTokens, countText } from './format.js';

const el = (tag, cls, text) => window.AIT.el(tag, cls, text);

/**
 * The kinds of use with their counts: { real, tests, assistants, total }.
 * real and tests carry the number of conversations as `count`; the test
 * conversations are everything that the conversations cost beyond those with
 * participants.
 */
export function spendingParts(usage, conversations) {
  const tests = conversations.filter((c) => c.is_test).length;
  const a = usage.all;
  const r = usage.real;
  const testsPriced = a.priced_calls - r.priced_calls;
  return {
    real: Object.assign({}, r, { count: conversations.length - tests }),
    tests: {
      count: tests,
      calls: a.calls - r.calls,
      input: a.input - r.input,
      output: a.output - r.output,
      estimated_calls: a.estimated_calls - r.estimated_calls,
      unpriced_calls: a.unpriced_calls - r.unpriced_calls,
      priced_calls: testsPriced,
      cost: testsPriced > 0 ? Math.max(0, a.cost - (r.cost || 0)) : null,
    },
    assistants: usage.assistants,
    total: usage.total,
  };
}

/**
 * A big number for a bucket, optionally divided into an average:
 * { text: "$0.37" | "40,101 tokens" | "$0.00", about: some of it is estimated }.
 * Money when the bucket has a price, its tokens otherwise.
 */
export function amountOf(bucket, divideBy = 1) {
  if (!bucket || !bucket.calls || !divideBy) return { text: '$0.00', about: false };
  const about = bucket.estimated_calls > 0;
  if (bucket.cost === null) return { text: formatTokens((bucket.input + bucket.output) / divideBy) + ' tokens', about };
  return { text: formatUsdShort(bucket.cost / divideBy), about };
}

/** Put an amount into an element, with a small "about" in front when it is partly estimated. */
export function showAmount(target, amount) {
  target.textContent = '';
  if (amount.about) target.appendChild(el('small', null, 'about '));
  target.append(amount.text);
}

/** "3 test conversations and the AI Assistant in Step 3": the parts that were used, as words. */
export function partsText(parts, assistantsWhere, { real = true, tests = true, assistants = true } = {}) {
  const list = [];
  if (real && parts.real.count) list.push(countText(parts.real.count, 'conversation') + ' with participants');
  if (tests && parts.tests.count) list.push(countText(parts.tests.count, 'test conversation'));
  if (assistants && parts.assistants.calls) list.push('the AI Assistant ' + assistantsWhere);
  return list.length > 1 ? list.slice(0, -1).join(', ') + ' and ' + list[list.length - 1] : (list[0] || '');
}

/** The cost of a bucket in a table cell: "–" when unused, "no price", or the amount ("about …" when partly estimated). */
export function costCellText(bucket) {
  if (!bucket.calls) return '–';
  if (bucket.cost === null) return 'no price';
  return (bucket.estimated_calls ? 'about ' : '') + formatUsd(bucket.cost);
}

/**
 * The table of what the money was spent on: one row per kind of use and the
 * total, with tokens and costs. Returns a sentence instead when nothing was
 * spent yet. assistantsLabel names the row of the AI Assistant.
 */
export function spendingTable(parts, assistantsLabel) {
  const rows = [];
  if (parts.real.count || parts.real.calls) {
    rows.push(['Conversations with participants', countText(parts.real.count, 'conversation') +
      (parts.real.count && parts.real.cost !== null ? ', ' + formatUsd(parts.real.cost / parts.real.count) + ' on average' : ''), parts.real]);
  }
  if (parts.tests.count || parts.tests.calls) rows.push(['Test conversations', countText(parts.tests.count, 'conversation'), parts.tests]);
  if (parts.assistants.calls) rows.push([assistantsLabel, countText(parts.assistants.calls, 'request'), parts.assistants]);
  if (!rows.length) {
    return el('p', 'part-lead', 'Nothing has been spent yet. The tokens (the billing units of text) and the costs appear here as soon as conversations are recorded.');
  }
  if (rows.length > 1) rows.push(['Total', '', parts.total, 'total']);
  const table = el('table', 'cost-table');
  table.innerHTML = '<thead><tr><th></th><th>Input tokens</th><th>Output tokens</th><th>Cost</th></tr></thead>';
  const tbody = el('tbody');
  rows.forEach(([label, note, bucket, cls]) => {
    const tr = el('tr', cls || null);
    const first = el('td', null, label);
    if (note) first.appendChild(el('span', 'sub', note));
    tr.append(first, el('td', null, bucket.calls ? formatTokens(bucket.input) : '–'),
      el('td', null, bucket.calls ? formatTokens(bucket.output) : '–'), el('td', null, costCellText(bucket)));
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  return table;
}

/** What the amounts are based on: the list prices (and their date), or tokens only when nothing has a price. */
export function priceBasisText(usage) {
  if (usage.total.calls && usage.total.cost === null) {
    return 'No list price is known for these requests, for example because they went through a custom gateway (a server address you enter instead of the provider\'s), so only tokens are shown.';
  }
  const asOf = new Date(usage.as_of + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
  return 'The costs use the list prices of the providers on ' + asOf + '. Your bill can differ, for example because of taxes or discounts.' +
    (usage.total.unpriced_calls ? ' Requests without a list price, for example through a custom gateway (a server address you enter), are not included.' : '');
}
