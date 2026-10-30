/**
 * estimate.js — token and cost estimates for the settings page (and the unit tests).
 *
 * Every participant message triggers one rewrite: the director reads the
 * setup and the conversation so far (input tokens) and writes the rest of the
 * conversation (output tokens). This module simulates those rewrites for the
 * draft on the settings page, with the very texts the chat would send:
 *
 *   input   the system prompt (director instructions and the fixed output-format
 *           section of includes/director.php) and the user message: what was
 *           said so far, the whole sample conversation and the lines still
 *           planned, each described by chat/script.js describeSequence. The
 *           study context is never part of it (only the assistants read it).
 *   output  the rest of the conversation as {"m":[{"s","c","p","d"},…]}
 *
 * No provider tokenizer is available in the browser, so estimateTokens()
 * counts word, number, punctuation and whitespace pieces the way BPE
 * tokenizers roughly split them — the same rules as pricing_estimate_tokens()
 * in includes/pricing.php, so the settings page and the data page agree.
 * Calibrated against the providers' own counts (README, "Token estimates").
 *
 * @author Marc Becker
 */

import { configureTeam } from './chat/config.js';
import { parseScript, scriptSegments, describeSequence, describeSegmented } from './chat/script.js';

/** Multipliers per tokenizer family (calibrated, see includes/pricing.php PRICING_TOKEN_FACTORS). */
export const TOKEN_FACTORS = { openai: 0.93, claude: 1.03, 'claude-new': 1.42 };

const PIECES = /[\p{L}\p{M}]+|\p{N}+|[^\s\p{L}\p{M}\p{N}]+|\s+/gu;

/** Estimated tokens of a text for a tokenizer family ('openai' | 'claude' | 'claude-new'). */
export function estimateTokens(text, family = 'openai') {
  const s = String(text || '');
  if (!s) return 0;
  let tokens = 0;
  for (const m of s.matchAll(PIECES)) tokens += pieceTokens(m[0]);
  return Math.round(tokens * (TOKEN_FACTORS[family] || 1));
}

function pieceTokens(p) {
  const len = [...p].length;
  if (/^\s+$/u.test(p)) {
    const nl = (p.match(/\n/g) || []).length;
    return nl > 0 ? Math.ceil(nl / 2) : (len > 1 ? 1 : 0);
  }
  if (/^[\p{L}\p{M}]+$/u.test(p)) {
    if (/^[A-Za-z]+$/.test(p)) {
      if (len >= 2 && p.toUpperCase() === p) return Math.ceil(len / 3); // all caps (names in the prompts) split more
      return len <= 8 ? 1 : (len <= 13 ? 2 : Math.ceil(len / 5));
    }
    if (/[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Hangul}]/u.test(p)) {
      return Math.ceil(len / 1.3); // Chinese, Japanese, Korean: about one token per character
    }
    return Math.max(1, Math.ceil(len / 4)); // accented and other non-Latin words
  }
  if (/^\p{N}+$/u.test(p)) return Math.ceil(len / 3);
  const ascii = (p.match(/[\x21-\x7e]/g) || []).length;
  return Math.ceil(ascii / 3) + Math.ceil((len - ascii) * 1.5);
}

/** What the simulated participant writes (a typical short chat message). */
const SAMPLE_MESSAGE = 'that sounds good to me, but what about the budget? i think we should keep it simple';
/** Tokens the providers add per request for the message structure. */
const MESSAGE_OVERHEAD = 8;

/** The director's reply format for a list of lines ({"m":[…]}, see includes/director.php). */
function planJson(lines, codeOf) {
  let prev = null;
  const m = lines.map((l) => {
    const p = prev === null ? 3 : Math.max(0, Math.round((l.sendAtMs - prev) / 1000));
    prev = l.sendAtMs;
    const d = Math.round(l.typingMs / 1000);
    return l.ghost ? { s: codeOf(l.sender), g: 1, p, d } : { s: codeOf(l.sender), c: l.text, p, d };
  });
  return JSON.stringify({ m });
}

/**
 * One simulated rewrite: the participant writes after `shown` lines of the
 * sample conversation, having written `before` messages earlier.
 */
function rewriteAt(ctx, shown, before) {
  const { lines, family } = ctx;
  const last = lines[shown - 1];
  const nowMs = (last ? last.sendAtMs : 0) + 3000;

  // What was said: the shown lines, with the participant's earlier messages spread in between.
  const said = [];
  const every = before > 0 ? Math.max(1, Math.floor(shown / (before + 1))) : Infinity;
  let placed = 0;
  lines.slice(0, shown).forEach((l, i) => {
    if (!l.ghost) said.push({ sender: l.sender, text: l.text, atMs: l.sendAtMs });
    if (placed < before && (i + 1) % every === 0) {
      said.push({ sender: 'human', text: SAMPLE_MESSAGE, atMs: l.sendAtMs + 2000 });
      placed++;
    }
  });
  said.push({ sender: 'human', text: SAMPLE_MESSAGE, atMs: nowMs });
  // Every message so far, like chat/session.js describeTranscript.
  const alreadySaid = describeSegmented(said, ctx.segments, nowMs);

  const rest = lines.slice(shown);
  const plan = describeSequence(rest.map((l) => ({ sender: l.sender, text: l.text, atMs: l.sendAtMs, ghost: l.ghost, typingMs: l.typingMs })), nowMs);

  const input = ctx.systemTokens + ctx.userFixed +
    estimateTokens(alreadySaid, family) + estimateTokens(plan, family) + estimateTokens(SAMPLE_MESSAGE, family);
  // The reply: a short reaction to the participant, then the rest of the conversation.
  const reaction = { sender: rest[0] ? rest[0].sender : (last ? last.sender : 'bot1'), text: 'yeah good point, let me think about that', sendAtMs: nowMs + 2000, typingMs: 3000, ghost: false };
  const output = estimateTokens(planJson([reaction].concat(rest), ctx.codeOf), family);
  return { input, output, system: ctx.systemTokens };
}

/**
 * Estimate the director's tokens for a draft setup.
 *
 * setup: {
 *   roles         role map id → { name, promptName, … } including "human"
 *   script        the sample conversation ({messages:[…]} or a JSON string)
 *   instructions  the director instructions
 *   scaffold      { system, user } — fixed prompt parts from the server
 *   family        tokenizer family of the selected model
 *   messages      participant messages per conversation (for the conversation total)
 * }
 * Returns null without a usable sample conversation, otherwise
 *   { early, late, conversation: { input, output, rewrites, items }, lines }
 * where early/late are { input, output, system } of a single rewrite: the
 * participant's first message about a tenth into the conversation, and a
 * message about three quarters in (with earlier messages before it). system is
 * the part of the input that is the same in every rewrite (the system prompt),
 * which providers can cache; items lists the rewrites of one conversation.
 */
export function estimateRewrites(setup) {
  let script = setup.script;
  if (typeof script === 'string') {
    try { script = script.trim() ? JSON.parse(script) : null; } catch (_) { return null; }
  }
  configureTeam(setup.roles);
  const lines = parseScript(script);
  if (lines.length < 2) return null;

  const family = setup.family || 'openai';
  const scaffold = setup.scaffold || { system: '', user: '' };
  const segments = scriptSegments(script);
  const scriptDescription = describeSegmented(lines.map((l) => ({ sender: l.sender, text: l.text, atMs: l.sendAtMs, ghost: l.ghost, typingMs: l.typingMs, segment: l.segment })), segments);
  const systemTokens = estimateTokens(setup.instructions || '', family) + estimateTokens(scaffold.system, family);
  const userFixed = MESSAGE_OVERHEAD + estimateTokens(scaffold.user, family) + estimateTokens(scriptDescription, family);
  const ctx = { lines, segments, family, systemTokens, userFixed, codeOf: (id) => (id.startsWith('bot') ? 'b' + id.slice(3) : id) };

  const n = lines.length;
  const at = (fraction) => Math.min(n - 1, Math.max(1, Math.round(fraction * n)));
  const messages = Math.max(1, Math.min(200, Math.round(Number(setup.messages) || 8)));

  const early = rewriteAt(ctx, at(0.1), 0);
  const late = rewriteAt(ctx, at(0.75), Math.max(0, Math.round(0.75 * messages) - 1));
  const conversation = { input: 0, output: 0, rewrites: messages, items: [] };
  for (let i = 0; i < messages; i++) {
    const r = rewriteAt(ctx, at((i + 1) / (messages + 1)), i);
    conversation.input += r.input;
    conversation.output += r.output;
    conversation.items.push(r);
  }
  return { early, late, conversation, lines: n };
}

/**
 * Cost in USD of one rewrite { input, output, system } at a price row.
 *
 * OpenAI caches the beginning of a prompt by itself (prompts of 1,024 tokens
 * and more). From the second rewrite of a conversation on, the system prompt
 * is read from that cache at the cached price; everything after it is written
 * to the cache, which GPT-5.6 and newer bill at 1.25 times the input price
 * (price.write; for older models it equals the input price). Measured on real
 * rewrites (2026-09-24): the second call read 3,386 of 6,087 input tokens from
 * the cache and wrote the other 2,698. Calls to Anthropic are not cached.
 */
export function rewriteCost(price, provider, r, systemCached) {
  if (!price) return null;
  if (provider !== 'openai' || r.input < 1024) return (r.input * price.input + r.output * price.output) / 1e6;
  const cached = systemCached && r.system >= 1024 ? Math.min(r.system, r.input) : 0;
  return (cached * price.cached + (r.input - cached) * price.write + r.output * price.output) / 1e6;
}

/** Cost of a whole conversation: the first rewrite writes the cache, the others read it. */
export function conversationCost(price, provider, items) {
  if (!price) return null;
  return items.reduce((sum, r, i) => sum + rewriteCost(price, provider, r, i > 0), 0);
}

/**
 * The price row { model, input, cached, write, output } (USD per million tokens)
 * for a model, from the table settings.php injects (includes/pricing.php): the
 * exact id, or the longest id that the model name extends ("…-2025-08-07").
 * Null for custom gateways and unknown models.
 */
export function priceFor(prices, provider, model) {
  const table = prices && prices[provider];
  const m = String(model || '').trim().toLowerCase();
  if (!table || !m) return null;
  let best = null;
  // The model or a dated snapshot of the model, as in includes/pricing.php pricing_for().
  Object.keys(table).forEach((key) => {
    const snapshot = m.startsWith(key + '-') && /^-(\d{4}-\d{2}-\d{2}|\d{8}|latest)$/.test(m.slice(key.length));
    if ((m === key || snapshot) && (!best || key.length > best.length)) best = key;
  });
  return best ? Object.assign({ model: best }, table[best]) : null;
}

/** Tokenizer family of a model (includes/pricing.php pricing_token_family). */
export function tokenFamily(provider, model) {
  if (provider !== 'anthropic') return 'openai';
  const m = String(model || '').toLowerCase();
  if (/claude-(haiku|sonnet|opus)-(3|4-[0-6])(\b|-|$)/.test(m) || m.startsWith('claude-3')) return 'claude';
  return 'claude-new';
}
