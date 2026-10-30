/**
 * format.js — how the studio pages write amounts of money, tokens and counts
 * (the settings page and the data page).
 *
 * @author Marc Becker
 */

/** "$0.0042", "$0.12", "$3.40": enough digits to be useful for small amounts. */
export function formatUsd(amount) {
  if (amount === null || amount === undefined || !Number.isFinite(amount)) return '';
  if (amount === 0) return '$0.00';
  if (amount < 0.01) return '$' + amount.toPrecision(2).replace(/0+$/, '').replace(/\.$/, '');
  if (amount < 10) return '$' + amount.toFixed(amount < 1 ? 3 : 2);
  return '$' + amount.toFixed(2);
}

/**
 * Amounts for headlines and totals: "$0.0042", "$0.019", "$0.42", "$18.84",
 * "$188", "$1,884". Like formatUsd, without a trailing zero below one dollar
 * and in whole dollars from 100 dollars on.
 */
export function formatUsdShort(amount) {
  if (amount === null || amount === undefined || !Number.isFinite(amount)) return '';
  if (amount < 1) return formatUsd(amount).replace(/(\.\d\d)0$/, '$1');
  if (amount < 100) return '$' + amount.toFixed(2);
  return '$' + Math.round(amount).toLocaleString('en-US');
}

/** 1234 → "1,234"; 123456 → "123,456"; 1234567 → "1.23M"; 4800000000 → "4.80B". */
export function formatTokens(n) {
  n = Math.round(Number(n) || 0);
  if (n < 1e6) return n.toLocaleString('en-US');
  if (n < 999.95e6) return (n / 1e6).toFixed(n < 1e7 ? 2 : 1) + 'M';
  return (n / 1e9).toFixed(n < 1e10 ? 2 : 1) + 'B';
}

/** "1 message", "8 messages", "1,200 participants". */
export function countText(n, word) {
  return n.toLocaleString('en-US') + ' ' + word + (n === 1 ? '' : 's');
}
