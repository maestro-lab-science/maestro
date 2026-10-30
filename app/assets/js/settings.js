/**
 * settings.js — the study settings page (settings.php), in four steps:
 *
 *   1 Study setup   study name, study context for the AI Assistant, session flow
 *   2 AI model      provider, model (with its price), response speed, API key
 *                   with a test, endpoint, format
 *   3 Conversation  participants (the human participant first, then the AI
 *                   teammates: names, colors, pictures), the sample conversation
 *                   (divided into segments if needed) and the director instructions
 *   4 Launch        readiness check with the study status (pause / continue),
 *                   costs (the estimate for the whole study and what was spent
 *                   so far), participant link (also as a test link)
 * The top bar shows the study status and offers "View data", "Add collaborator"
 * (a dialog with the link to these settings and the study password) and
 * "Duplicate".
 *
 * Everything is edited locally and stored with one Save (the save bar at the
 * bottom, or Cmd/Ctrl+S) through api/save_project.php. The server injects the
 * study and the price list as window.SETTINGS. The AI Assistant panel
 * live in settings-assistants.js; the token estimate in estimate.js; the editor
 * for the instructions above the chat in richtext.js.
 *
 * @author Marc Becker
 */

import { estimateRewrites, estimateTokens, priceFor, tokenFamily, rewriteCost, conversationCost } from './estimate.js';
import { formatUsd, formatUsdShort, formatTokens, countText } from './format.js';
import { spendingParts, amountOf, showAmount, partsText, spendingTable, priceBasisText } from './spending.js';
import { createAssistants } from './settings-assistants.js';
import { createRichText, plainTextToRichText, RICHTEXT_MAX_CHARS } from './richtext.js';

const CFG = window.SETTINGS;
const AIT = window.AIT;
const $ = (id) => document.getElementById(id);

const PROJECT_ID = CFG.projectId;
const PROJECT = CFG.project; // the stored values; updated after every save
const SERVER = CFG.server;
// A draft copy of an example study (settings.php?example=ID): { source, source_name,
// password } until the first Save creates the project, then null.
let draft = CFG.draft || null;
const PROVIDERS = ['openai', 'anthropic', 'custom'];
const PROVIDER_LABELS = { openai: 'OpenAI', anthropic: 'Anthropic', custom: 'your custom gateway' };

// =============================================================================
// Steps
// =============================================================================
const STEP_KEY = 'aiteamstudio.step.' + PROJECT_ID;
let currentStep = 1;

// The page keeps its scroll position when a step is switched; a caller passes
// { scroll: true } only when the tabs really should come into view.
function showStep(n, { scroll = false } = {}) {
  n = Math.min(4, Math.max(1, Number(n) || 1));
  currentStep = n;
  document.querySelectorAll('.step').forEach((s) => s.classList.toggle('active', Number(s.dataset.step) === n));
  document.querySelectorAll('#stepTabs button').forEach((b) => {
    const active = Number(b.dataset.step) === n;
    b.classList.toggle('active', active);
    if (active) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
  });
  history.replaceState(null, '', location.pathname + location.search + '#step' + n);
  try { localStorage.setItem(STEP_KEY, String(n)); } catch (_) { /* per-browser convenience only */ }
  if (n === 3) dirRegrow();
  if (n === 4) { refreshReadiness(); renderEstimate(); loadUsage(); fitShareField(); }
  if (scroll) $('stepTabs').scrollIntoView({ block: 'start', behavior: 'smooth' });
}
document.querySelectorAll('#stepTabs button').forEach((b) => b.addEventListener('click', () => showStep(b.dataset.step)));
document.querySelectorAll('[data-goto]').forEach((b) => b.addEventListener('click', () => showStep(b.dataset.goto)));

// =============================================================================
// "?" help notes
// =============================================================================
document.querySelectorAll('.help-btn[data-help], .link-btn[data-help]').forEach((btn) => {
  btn.setAttribute('aria-expanded', 'false');
  btn.setAttribute('aria-controls', btn.dataset.help);
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    const note = $(btn.dataset.help);
    const open = !note.classList.contains('open');
    note.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', String(open));
  });
});

// =============================================================================
// Unsaved changes
// =============================================================================
let dirty = false;
let editCount = 0; // every change counts, so a save knows whether edits arrived while it ran
function setDirty(v) {
  if (v) editCount++;
  dirty = !!v || !!draft; // a draft copy is unsaved by definition
  $('savebar').classList.toggle('dirty', dirty);
  $('saveStatus').textContent = draft ? 'Not saved yet. Saving creates a new study from this copy.' : (dirty ? 'Unsaved changes' : 'All changes saved');
  $('saveBtn').disabled = !dirty; // nothing to save: the button goes quiet
  // Leaving a draft without saving discards the copy, so it warns like any unsaved change.
  window.onbeforeunload = dirty ? (e) => { e.preventDefault(); e.returnValue = ''; return ''; } : null;
  scheduleChecks();
}

// The assistant drawer of narrow screens sits right above the save bar, whose
// height changes with its text (three lines on phones for a draft copy).
if ('ResizeObserver' in window) {
  new ResizeObserver(() => document.documentElement.style.setProperty('--savebarH', $('savebar').offsetHeight + 'px')).observe($('savebar'));
}

/** Readiness, estimate and step markers follow every edit (debounced). */
let checksTimer = null;
function scheduleChecks() {
  clearTimeout(checksTimer);
  checksTimer = setTimeout(() => {
    refreshStepIssues();
    if (currentStep === 4) { refreshReadiness(); scheduleEstimate(0); }
  }, 250);
}

// Number fields keep to their range: when a field is left with a value outside
// it, the value becomes the nearest limit, so the page shows what is used and
// saved (the server clamps the same way). An empty field keeps its default.
document.addEventListener('change', (e) => {
  const input = e.target;
  if (!(input instanceof HTMLInputElement) || input.type !== 'number' || input.value.trim() === '') return;
  const v = Number(input.value);
  if (!Number.isFinite(v)) return;
  const min = input.min !== '' ? Number(input.min) : -Infinity;
  const max = input.max !== '' ? Number(input.max) : Infinity;
  let fixed = Math.min(max, Math.max(min, v));
  if (input.step !== 'any') fixed = Math.round(fixed);
  if (fixed !== v) {
    input.value = String(fixed);
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }
}, true);

// ---- Undo stacks (director instructions + sample conversation) ----------------------
function createUndo(btn) {
  const countEl = btn.querySelector('.count');
  const stack = [];
  let current = null;
  let burstStart = null; // the state before the typing that is still going on (null: none)
  let debounce = null;
  const MAX = 100;
  function paint() { btn.disabled = stack.length === 0; countEl.textContent = stack.length; }
  function add(state) { stack.push(state); if (stack.length > MAX) stack.shift(); }
  function closeBurst() { // the typing that went on becomes one undo step
    clearTimeout(debounce);
    if (burstStart !== null && burstStart !== current) add(burstStart);
    burstStart = null;
    paint();
  }
  return {
    init(state) { clearTimeout(debounce); burstStart = null; current = state; stack.length = 0; paint(); },
    snapshot(state) { // debounced: typing collapses into one undo step
      if (burstStart === null) burstStart = current;
      clearTimeout(debounce);
      current = state;
      debounce = setTimeout(closeBurst, 600);
    },
    push(state) { // immediate: discrete actions (delete, reorder, add)
      closeBurst();
      if (current !== null && current !== state) add(current);
      current = state; paint();
    },
    undo() {
      closeBurst(); // Undo right after typing takes back that typing
      if (!stack.length) return null;
      current = stack.pop(); paint(); return current;
    },
  };
}
const directorUndo = createUndo($('undoDirector'));
const scriptUndo = createUndo($('undoScript'));
scriptUndo.init(PROJECT.conversation_script);

// =============================================================================
// Step 1 · Study setup
// =============================================================================
$('projectName').value = PROJECT.name;
$('studyContext').value = PROJECT.study_context;
$('participantInstructions').value = PROJECT.participant_instructions;
$('showReadyModal').checked = !!PROJECT.show_ready_modal;
$('showInstructions').checked = !!PROJECT.show_instructions;
$('readyWaitSeconds').value = PROJECT.ready_wait_seconds;
$('timerEnabled').checked = !!PROJECT.timer_enabled;
$('timerMinutes').value = PROJECT.timer_minutes;

/** Toggles hide their inputs when off. */
function bindTogglePanel(toggleId, panelId) {
  const t = $(toggleId);
  const p = $(panelId);
  const sync = () => p.classList.toggle('hidden', !t.checked);
  t.addEventListener('change', sync);
  sync();
}
bindTogglePanel('showReadyModal', 'readyGatePanel');
bindTogglePanel('timerEnabled', 'timerPanel');
bindTogglePanel('showInstructions', 'instructionsPanel');

// Instructions above the chat: a formatted text (assets/js/richtext.js). The
// editor is only visible while the option is switched on; its text is kept
// either way, so switching the option off and on again loses nothing.
$('showChatInstructions').checked = !!PROJECT.show_chat_instructions;
const chatInstructions = createRichText($('chatInstructionsEditor'), {
  value: PROJECT.chat_instructions,
  label: 'Instructions for participants, shown above the chat',
  placeholder: 'Example: Together with your teammates, come up with a name for a new coworking space. You have ten minutes.',
  onChange: () => setDirty(true),
});
bindTogglePanel('showChatInstructions', 'chatInstructionsPanel');

function refreshBadge() {
  $('projectBadge').textContent = ($('projectName').value.trim() || 'Untitled study') + ' · ' + PROJECT_ID;
}
refreshBadge();

/** "320 characters · about 80 tokens" under the study context. */
function refreshContextCount() {
  const text = $('studyContext').value.trim();
  $('contextCount').textContent = text
    ? text.length.toLocaleString('en-US') + ' characters · about ' + formatTokens(estimateTokens(text, currentFamily())) + ' tokens (billing units of text) for the AI Assistant in Step 3'
    : 'Optional. The AI Assistant in Step 3 also works without a study context.';
}

// =============================================================================
// Step 2 · AI model
// =============================================================================
const CATALOG = CFG.catalog;
function catalogIds(provider) {
  return (CATALOG[provider] || []).flatMap((g) => g.models.map((m) => m.id));
}

// The live director's thinking effort. OpenAI-style models take a reasoning
// effort ("minimal" is sent as the lowest value the model accepts, see
// includes/llm.php); Claude models take off/low/medium/high.
const DIRECTOR_EFFORT_OPTIONS = {
  openai: [
    ['minimal', 'Fastest: no thinking (recommended)'],
    ['low', 'Short thinking'],
    ['medium', 'Medium thinking, noticeably slower'],
    ['high', 'Long thinking, very slow'],
    ['default', 'The model’s own default'],
  ],
  anthropic: [
    ['off', 'Fastest: no thinking (recommended)'],
    ['low', 'Short thinking'],
    ['medium', 'Medium thinking, noticeably slower'],
    ['high', 'Long thinking, very slow'],
  ],
};
DIRECTOR_EFFORT_OPTIONS.custom = DIRECTOR_EFFORT_OPTIONS.openai;
const FAST_EFFORTS = ['minimal', 'off', 'none'];

/** Models that think a little even at the fastest setting. */
function alwaysThinks(provider, model) {
  const m = String(model || '').toLowerCase();
  return provider === 'anthropic' ? /^claude-(opus-5-5|fable|mythos)/.test(m) : /^gpt-\d+(\.\d+)?-astra/.test(m);
}

let currentProvider = PROVIDERS.includes(PROJECT.api_provider) ? PROJECT.api_provider : 'openai';
const providerButtons = Array.from(document.querySelectorAll('#providerTabs button'));

function rebuildModelSelect(provider, desiredModel) {
  const sel = $('modelSelect');
  sel.innerHTML = '';
  (CATALOG[provider] || []).forEach((group) => {
    const og = document.createElement('optgroup');
    og.label = group.group;
    group.models.forEach((m) => {
      const o = AIT.el('option', null, m.id + (m.note ? '  ·  ' + m.note : ''));
      o.value = m.id;
      og.appendChild(o);
    });
    sel.appendChild(og);
  });
  const other = AIT.el('option', null, 'Other model…');
  other.value = '__custom';
  sel.appendChild(other);

  const options = catalogIds(provider);
  const wanted = (desiredModel || '').trim();
  if (wanted !== '' && options.includes(wanted)) {
    sel.value = wanted;
  } else if (wanted !== '') {
    sel.value = '__custom';
    $('modelInput').value = wanted;
  } else {
    const def = SERVER.default_model[provider];
    sel.value = def && options.includes(def) ? def : (options[0] || '__custom');
  }
  $('modelInput').classList.toggle('hidden', sel.value !== '__custom');
}

function getModelValue() {
  const sel = $('modelSelect');
  return sel.value === '__custom' ? $('modelInput').value.trim() : sel.value;
}

function fillEffortSelect(sel, options, desired) {
  sel.innerHTML = '';
  options.forEach(([value, label]) => {
    const o = AIT.el('option', null, label);
    o.value = value;
    sel.appendChild(o);
  });
  sel.value = options.some(([v]) => v === desired) ? desired : options[0][0];
}
/** Labels of the response-speed buttons: [title, subtitle]. */
const EFFORT_BUTTONS = {
  minimal: ['Fastest', 'no thinking'], off: ['Fastest', 'no thinking'],
  low: ['Short thinking', 'a little slower'], medium: ['Medium thinking', 'noticeably slower'],
  high: ['Long thinking', 'very slow'], default: ['Model default', 'provider setting'],
};
/** The hidden select holds the value; the segmented buttons show and change it. */
function rebuildDirectorEffortSelect(provider, effort) {
  const sel = $('directorEffortSelect');
  fillEffortSelect(sel, DIRECTOR_EFFORT_OPTIONS[provider], effort);
  const host = $('effortSeg');
  host.innerHTML = '';
  DIRECTOR_EFFORT_OPTIONS[provider].forEach(([value]) => {
    const [title, sub] = EFFORT_BUTTONS[value] || [value, ''];
    const b = AIT.el('button');
    b.type = 'button';
    b.dataset.value = value;
    b.setAttribute('role', 'radio');
    b.appendChild(document.createTextNode(title));
    if (sub) b.appendChild(AIT.el('small', null, sub));
    b.addEventListener('click', () => {
      if (sel.value === value) return;
      sel.value = value;
      sel.dispatchEvent(new Event('change'));
    });
    host.appendChild(b);
  });
  paintEffortButtons();
}
function paintEffortButtons() {
  const v = $('directorEffortSelect').value;
  document.querySelectorAll('#effortSeg button').forEach((b) => {
    const on = b.dataset.value === v;
    b.classList.toggle('active', on);
    b.setAttribute('aria-checked', String(on));
  });
}

function currentPrice() {
  return currentProvider === 'custom' ? null : priceFor(CFG.prices, currentProvider, getModelValue());
}
function currentFamily() {
  return tokenFamily(currentProvider, getModelValue());
}
/** "$0.10", "$0.125", "$2.00": a price per million tokens with two or three decimals. */
function perMillion(v) {
  return '$' + Number(v).toFixed(3).replace(/0$/, '');
}
function asOfText() {
  const d = new Date(CFG.pricesAsOf + 'T12:00:00');
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** The short description of a model in the catalog ("fastest, lowest cost"), or ''. */
function catalogNote(provider, model) {
  for (const group of CATALOG[provider] || []) {
    const hit = group.models.find((m) => m.id === model);
    if (hit) return hit.note || '';
  }
  return '';
}

/** Chips under the model select (what it is like, what it costs) and the "always thinks" note. */
function refreshModelInfo() {
  const model = getModelValue();
  const price = currentPrice();
  const host = $('modelChips');
  host.innerHTML = '';
  const chip = (cls, icon, text, title) => {
    const c = AIT.el('span', 'chip ' + cls);
    c.innerHTML = '<i class="' + icon + '"></i>';
    c.appendChild(document.createTextNode(text));
    if (title) c.title = title;
    host.appendChild(c);
  };
  const note = catalogNote(currentProvider, model);
  if (note) chip('c-violet', 'fas fa-tachometer-alt', note);
  if (currentProvider === 'custom') {
    chip('c-plain', 'fas fa-tag', 'Price set by your gateway contract');
  } else if (price) {
    const asOf = 'List price on ' + asOfText();
    chip('c-green', 'fas fa-sign-in-alt', perMillion(price.input) + ' per million input tokens', asOf);
    chip('c-blue', 'fas fa-sign-out-alt', perMillion(price.output) + ' per million output tokens', asOf);
  } else if (model) {
    chip('c-amber', 'fas fa-question-circle', 'No list price is known for this model, so only tokens are estimated');
  }
  $('alwaysThinksNote').classList.toggle('hidden', !alwaysThinks(currentProvider, model));
}

// ---- API key -----------------------------------------------------------------------
// The field always shows the key that will effectively be used: the study's
// own key (stored with its provider), otherwise the server default.
function prefillKeyField(provider) {
  $('apiKeyInput').value = (PROJECT.api_key && PROJECT.api_provider === provider)
    ? PROJECT.api_key
    : (SERVER.keys[provider] || '');
}
/** True when the field still holds a prefilled (untouched) value. */
function keyFieldIsPrefill() {
  const v = $('apiKeyInput').value.trim();
  return v === '' || v === PROJECT.api_key || Object.values(SERVER.keys).includes(v);
}
function effectiveKey() {
  return $('apiKeyInput').value.trim() || SERVER.keys[currentProvider] || '';
}
function refreshKeyHint() {
  const v = $('apiKeyInput').value.trim();
  let text;
  if (v === '') {
    text = SERVER.keys[currentProvider]
      ? 'The field is empty. When you save, the key stored with the study is removed and the server’s default key is used instead.'
      : 'A key is needed, because the server has no default key for this provider.';
  } else if (PROJECT.api_key && v === PROJECT.api_key && PROJECT.api_provider === currentProvider) {
    text = 'This is the key stored with this study.';
  } else if (v === SERVER.keys[currentProvider]) {
    text = 'This is the server’s default key, filled in for you. It is stored with this study when you save.';
  } else {
    text = 'This key will be stored with the study when you save.';
  }
  $('apiKeyHint').textContent = text;
}

// ---- Key test ----------------------------------------------------------------------
// api/test_key.php stores every test with the study at once (no Save
// needed), so the last test is still known after a reload or in another
// browser. A test belongs to the provider, model, key, endpoint and format it
// was run with: their fingerprint ("signature") is stored with it, and the
// test only counts while the form still holds those values. A test that
// worked more than a week ago is shown in yellow, as a reminder to test again.
const KEY_TEST_FRESH_DAYS = 7;
let keyTesting = false; // a test is running right now
let keyTest = CFG.keyTest // { ok, at, signature, model, hint }
  ? { ok: !!CFG.keyTest.ok, at: new Date(CFG.keyTest.at * 1000), signature: CFG.keyTest.signature, model: CFG.keyTest.model, hint: CFG.keyTest.hint }
  : null;

/** A short fingerprint of a text (FNV-1a, 32 bit, as 8 hex digits). Only this page computes and compares it. */
function fingerprint(text) {
  let h = 0x811c9dc5;
  for (const byte of new TextEncoder().encode(text)) {
    h ^= byte;
    h = Math.imul(h, 0x01000193);
  }
  return (h >>> 0).toString(16).padStart(8, '0');
}
function keySignature() {
  return fingerprint([currentProvider, getModelValue(), effectiveKey(), $('endpointInput').value.trim(), $('apiFormatSelect').value].join('|'));
}
function currentKeyTest() {
  return keyTest && keyTest.signature === keySignature() ? keyTest : null;
}
/**
 * What the last test says about the values in the form: 'none' (never
 * tested), 'other' (tested with other values), 'failed', 'old' (it worked,
 * but more than a week ago) or 'ok'.
 */
function keyTestState() {
  if (!keyTest) return 'none';
  if (!currentKeyTest()) return 'other';
  if (!keyTest.ok) return 'failed';
  return Date.now() - keyTest.at.getTime() > KEY_TEST_FRESH_DAYS * 86400000 ? 'old' : 'ok';
}
/** "today at 12:03", "yesterday at 12:03", "on 24 September" (with the year when it is not this year). */
function whenText(date) {
  const now = new Date();
  const dayOf = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((dayOf(now) - dayOf(date)) / 86400000);
  const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  if (days <= 0) return 'today at ' + time;
  if (days === 1) return 'yesterday at ' + time;
  return 'on ' + date.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric' });
}
/** "12 days ago" for a test that is more than a week old. */
function daysAgoText(date) {
  return Math.floor((Date.now() - date.getTime()) / 86400000) + ' days ago';
}
/** "with gpt-5-mini today at 12:03": which model the last test used, and when it ran. */
function keyTestRun() {
  return 'with ' + (keyTest.model || 'the default model') + ' ' + whenText(keyTest.at);
}

const KEY_NOTE_ICONS = { ok: 'fas fa-check-circle', warn: 'far fa-clock', busy: 'fas fa-spinner fa-spin', err: 'fas fa-exclamation-circle' };
function showKeyNote(kind, text, detail) {
  const note = $('keyTestNote');
  note.className = 'test-note show ' + kind;
  note.querySelector('i').className = KEY_NOTE_ICONS[kind];
  $('keyTestText').textContent = text;
  $('keyTestDetail').textContent = detail || '';
}
/** The note under the key field: the last test, as far as it is about the values in the form. */
function syncKeyNote() {
  if (keyTesting) return;
  const state = keyTestState();
  if (state === 'ok') {
    showKeyNote('ok', 'The key works.', 'Last tested ' + keyTestRun() + '.');
  } else if (state === 'old') {
    showKeyNote('warn', 'The key was last tested ' + daysAgoText(keyTest.at) + '.',
      'The key worked ' + keyTestRun() + '. You can test the key again to make sure that it still works.');
  } else if (state === 'failed') {
    showKeyNote('err', keyTest.hint || 'The last test failed.', 'Tested ' + keyTestRun() + '.');
  } else {
    $('keyTestNote').className = 'test-note';
  }
}

async function testKey() {
  const btn = $('testKeyBtn');
  const model = getModelValue();
  if (draft) {
    showKeyNote('err', 'Save your copy first.', 'Saving creates the study, so that MAESTRO can test the key.');
    return;
  }
  if (!effectiveKey()) {
    showKeyNote('err', 'There is no key to test yet.', 'Paste a key into the field first.');
    return;
  }
  btn.disabled = true;
  keyTesting = true;
  showKeyNote('busy', 'Testing the key with ' + (model || 'the default model') + '…');
  if (currentStep === 4) refreshReadiness();
  const signature = keySignature();
  let result;
  try {
    const res = await fetch('api/test_key.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        project_id: PROJECT_ID, provider: currentProvider, model,
        api_key: $('apiKeyInput').value.trim(), api_endpoint: $('endpointInput').value.trim(), api_format: $('apiFormatSelect').value,
        signature,
      }),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'The server sent an unexpected answer (HTTP ' + res.status + ').' }));
    // The server's time of the test: the one that is stored with the project.
    const at = data.tested_at ? new Date(data.tested_at * 1000) : new Date();
    if (data.ok) {
      result = { ok: true, at, model: data.model };
      showKeyNote('ok', 'The key works. ' + data.model + ' answered in ' + (data.ms / 1000).toFixed(1) + ' seconds.',
        data.reply ? 'Reply of the model: “' + data.reply + '”' : '');
    } else {
      result = { ok: false, at, model: data.model || model, hint: data.hint || data.error };
      showKeyNote('err', data.hint || 'The test failed.', data.error ? 'Message from the provider: ' + data.error + (data.status ? ' (HTTP ' + data.status + ')' : '') : '');
    }
  } catch (err) {
    result = { ok: false, at: new Date(), model, hint: 'The test could not be run.' };
    showKeyNote('err', 'The test could not be run. Please check your connection and try again.', err.message);
  } finally {
    btn.disabled = false;
    keyTesting = false;
  }
  keyTest = Object.assign(result, { signature });
  if (keySignature() !== signature) syncKeyNote(); // the form changed during the test
  refreshStepIssues();
  if (currentStep === 4) refreshReadiness();
}
$('testKeyBtn').addEventListener('click', testKey);

// ---- Provider switch -----------------------------------------------------------------
function setProvider(p, initial) {
  currentProvider = p;
  providerButtons.forEach((b) => {
    b.classList.toggle('active', b.dataset.provider === p);
    b.setAttribute('aria-checked', String(b.dataset.provider === p));
  });
  $('endpointField').classList.toggle('hidden', p !== 'custom');
  $('apiFormatField').classList.toggle('hidden', p === 'anthropic');
  if (p === 'custom' && $('endpointInput').value.trim() === '') {
    $('endpointInput').value = SERVER.custom_endpoint || ''; // the server's gateway as a starting point
  }
  if (initial) {
    // Restore exactly what is stored for this project.
    rebuildModelSelect(p, PROJECT.model);
    rebuildDirectorEffortSelect(p, PROJECT.director_effort);
    prefillKeyField(p);
  } else {
    // Manual switch: the provider's default model, keep the effort when valid,
    // and swap the prefilled key unless the field holds a hand-typed key.
    $('modelInput').value = '';
    rebuildModelSelect(p, '');
    rebuildDirectorEffortSelect(p, $('directorEffortSelect').value);
    if (keyFieldIsPrefill()) prefillKeyField(p);
  }
  refreshKeyHint();
  refreshModelInfo();
  syncKeyNote();
  if (!initial) setDirty(true);
}
providerButtons.forEach((b) => b.addEventListener('click', () => { if (b.dataset.provider !== currentProvider) setProvider(b.dataset.provider, false); }));

$('endpointInput').value = PROJECT.api_endpoint;
$('apiFormatSelect').value = ['auto', 'chat', 'responses'].includes(PROJECT.api_format) ? PROJECT.api_format : 'auto';
setProvider(currentProvider, true);

$('modelSelect').addEventListener('change', () => {
  $('modelInput').classList.toggle('hidden', $('modelSelect').value !== '__custom');
  if ($('modelSelect').value === '__custom') $('modelInput').focus({ preventScroll: true });
  refreshModelInfo();
  syncKeyNote();
  setDirty(true);
});
$('modelInput').addEventListener('input', () => { refreshModelInfo(); syncKeyNote(); setDirty(true); });
$('directorEffortSelect').addEventListener('change', () => { paintEffortButtons(); setDirty(true); });
$('apiFormatSelect').addEventListener('change', () => { syncKeyNote(); setDirty(true); });
$('endpointInput').addEventListener('input', () => { syncKeyNote(); setDirty(true); });
$('apiKeyInput').addEventListener('input', () => { refreshKeyHint(); syncKeyNote(); setDirty(true); });

// =============================================================================
// Step 3 · Participants
// =============================================================================
// TEAM is the working copy of everyone in the chat: [{ id, name, color,
// avatar? }]. The real participant ("human") is always the first row;
// the AI teammates follow with stable ids (bot1 … bot10), shown as Bot 1,
// Bot 2, … and used by the sample conversation and the saved messages. Every
// change is pushed into the shared row builders (AIT.setTeam), so the editors
// follow immediately; it is stored with the study on save.
const isHuman = (m) => m.id === 'human';
let TEAM = (Array.isArray(PROJECT.team) ? PROJECT.team : []).map((m) => Object.assign({}, m))
  .sort((a, b) => Number(isHuman(b)) - Number(isHuman(a))); // older studies stored the participant last
function bots() { return TEAM.filter((m) => !isHuman(m)); }
function human() { return TEAM.find(isHuman) || null; }
function teamIds() { return bots().map((m) => m.id); } // who may speak in the sample conversation
function teamMember(id) { return TEAM.find((m) => m.id === id) || null; }
function displayName(m) { return m.name.trim() || (isHuman(m) ? 'Participant' : m.id); }
function teamName(id) { const m = teamMember(id); return m ? displayName(m) : id; }

/** Role map for the shared row builders and the estimate (the participant under their name). */
function teamRolesMap() {
  const map = {};
  TEAM.forEach((m) => {
    map[m.id] = { name: displayName(m), color: m.color, avatar: m.avatar || '', promptName: displayName(m).toUpperCase() };
  });
  return map;
}
AIT.setTeam(teamRolesMap());

/** Options of a sender <select>: the AI teammates, plus the row's own sender if it is no longer one. */
function fillSenderSelect(sel, current) {
  sel.innerHTML = '';
  bots().forEach((m) => {
    const opt = AIT.el('option', null, displayName(m));
    opt.value = m.id;
    sel.appendChild(opt);
  });
  if (!bots().some((m) => m.id === current)) {
    const opt = AIT.el('option', null, current + ' (not an AI teammate)');
    opt.value = current;
    sel.appendChild(opt);
  }
  sel.value = current;
}

/** Refresh everything that shows the team: the count, the script rows, the add button. */
function applyTeam() {
  AIT.setTeam(teamRolesMap());
  const n = bots().length;
  document.querySelectorAll('#scriptRows .s-row').forEach((row) => {
    fillSenderSelect(row.querySelector('select.sender'), row.dataset.sender);
    AIT.applyRole(row, row.dataset.sender);
  });
  const full = n >= CFG.teamMax;
  $('addMemberBtn').disabled = full;
  $('addMemberBtn').innerHTML = full ? 'The maximum of ' + CFG.teamMax + ' AI actors has been reached' : '<i class="fas fa-plus"></i> Add an AI actor';
}

/** How many sample-conversation lines a teammate has (visual rows or JSON, whichever is live). */
function scriptLinesFor(id) {
  try {
    const msgs = mode === 'visual' ? readRowsFromDom() : parseScriptJson().messages;
    return msgs.filter((m) => m.sender === id).length;
  } catch (_) { return 0; }
}

function renderTeamRows() {
  const host = $('teamRows');
  host.innerHTML = '';
  TEAM.forEach((m) => {
    const hum = isHuman(m);
    const row = AIT.applyRole(AIT.el('div', 't-row' + (hum ? ' is-human' : '')), m.id);
    row.dataset.id = m.id;

    // Picture: click to upload, × to remove.
    const wrap = AIT.el('span', 't-avatar-wrap');
    const pic = AIT.el('button', 't-avatar');
    pic.type = 'button';
    pic.title = m.avatar ? 'Change the picture' : 'Upload a picture';
    pic.setAttribute('aria-label', pic.title + ' of ' + displayName(m));
    const cam = AIT.el('span', 't-cam');
    cam.innerHTML = '<i class="fas fa-camera"></i>';
    pic.append(AIT.avatar(m.id), cam);
    pic.addEventListener('click', () => pickAvatar(m.id));
    wrap.appendChild(pic);
    if (m.avatar) {
      const del = AIT.el('button', 't-avatar-del');
      del.type = 'button';
      del.title = 'Remove the picture';
      del.innerHTML = '<i class="fas fa-times"></i>';
      del.addEventListener('click', () => { delete m.avatar; teamChanged(false); });
      wrap.appendChild(del);
    }

    const color = AIT.el('input');
    color.type = 'color';
    color.value = m.color;
    color.title = 'Color';
    color.addEventListener('input', () => { m.color = color.value; teamChanged(true); });

    const name = AIT.el('input', 'name');
    name.type = 'text';
    name.maxLength = CFG.nameMax;
    name.placeholder = hum ? 'Name of the participant' : 'Name';
    name.value = m.name;
    name.setAttribute('aria-label', hum ? 'Name of the participant' : 'Name of ' + m.id);
    name.addEventListener('input', () => { m.name = name.value; teamChanged(true); });

    const label = AIT.el('span', 't-label', hum ? 'Human' : 'AI Actor #' + m.id.slice(3));
    label.title = hum ? 'The real participant of your study' : 'An AI actor (called ' + m.id + ' in the JSON of the sample conversation)';

    row.append(wrap, label, color, name);

    if (!hum) {
      const lines = scriptLinesFor(m.id);
      row.append(AIT.el('span', 't-count', lines ? lines + ' message' + (lines === 1 ? '' : 's') + ' in the sample conversation' : 'no messages yet'));
      const del = AIT.el('button', 't-del');
      del.type = 'button';
      del.innerHTML = '<i class="fas fa-times"></i>';
      del.disabled = bots().length <= 1;
      del.title = del.disabled ? 'The chat needs at least one AI actor' : 'Remove this AI actor';
      del.addEventListener('click', () => removeMember(m.id));
      row.append(del);
    }
    if (!hum) row.appendChild(dirActorField(m)); // wraps to a second line inside the row
    host.appendChild(row);
  });
}

/** The instructions for one AI actor only: a growing text box on the second line of the row. */
function dirActorField(m) {
  const field = AIT.el('div', 't-ins');
  const ta = AIT.el('textarea', 'textarea');
  ta.id = 'dirActor_' + m.id;
  ta.setAttribute('aria-label', 'Instructions for ' + displayName(m) + ' only');
  ta.rows = 1;
  ta.placeholder = 'Describe what makes ' + displayName(m) + ' unique, for example the role or the writing style.';
  ta.value = dirActorText(m.id);
  ta.addEventListener('input', () => {
    if (ta.value.trim() === '') delete DIR.actors[m.id]; else DIR.actors[m.id] = ta.value;
    autoGrow(ta, 1, 5);
    directorUndo.snapshot(dirMarkerText());
    dirSectionSnapshot('team');
    refreshDirPreviews();
    dirChanged();
  });
  field.appendChild(ta);
  requestAnimationFrame(() => { dirAlignActorField(field); autoGrow(ta, 1, 5); });
  return field;
}

/** Start the actor's text box where the name field starts. */
function dirAlignActorField(field) {
  const name = field.parentElement && field.parentElement.querySelector('input.name');
  if (!name) return;
  const left = name.getBoundingClientRect().left - field.getBoundingClientRect().left; // the field's box starts at the row's content edge
  if (left > 0) field.style.paddingLeft = Math.round(left) + 'px';
}

/** The team changed. inPlace = true keeps the row inputs (someone is typing in them). */
function teamChanged(inPlace) {
  AIT.setTeam(teamRolesMap());
  if (inPlace) {
    document.querySelectorAll('#teamRows .t-row').forEach((row) => {
      const old = row.querySelector('.t-avatar .avatar');
      if (old) old.replaceWith(AIT.avatar(row.dataset.id));
      AIT.applyRole(row, row.dataset.id);
      const m = teamMember(row.dataset.id);
      const ins = row.querySelector('.t-ins textarea');
      if (m && ins) {
        ins.setAttribute('aria-label', 'Instructions for ' + displayName(m) + ' only');
        ins.placeholder = 'Describe what makes ' + displayName(m) + ' unique, for example the role or the writing style.';
      }
    });
  } else {
    renderTeamRows();
  }
  applyTeam();
  refreshDirPillNames();
  refreshDirPreviews();
  refreshDirectorCount();
  refreshDirFull();
  setDirty(true);
}

function addMember() {
  if (bots().length >= CFG.teamMax) return;
  let n = 1;
  while (teamMember('bot' + n)) n++;
  const usedNames = new Set(TEAM.map((m) => m.name.trim().toLowerCase()));
  const usedColors = new Set(TEAM.map((m) => m.color.toLowerCase()));
  const palette = CFG.palette;
  const pick = palette.find(([nm, color]) => !usedNames.has(nm.toLowerCase()) && !usedColors.has(color.toLowerCase()))
    || palette.find(([, color]) => !usedColors.has(color.toLowerCase()))
    || palette[(n - 1) % palette.length];
  const member = { id: 'bot' + n, name: usedNames.has(pick[0].toLowerCase()) ? 'Teammate ' + n : pick[0], color: pick[1] };
  delete DIR.actors[member.id]; // a text left from an earlier actor with this id (Undo) does not carry over
  TEAM.push(member);
  teamChanged(false);
  const input = document.querySelector('#teamRows .t-row[data-id="' + member.id + '"] input.name');
  if (input) { input.focus({ preventScroll: true }); input.select(); }
}

function removeMember(id) {
  if (bots().length <= 1) return;
  const lines = scriptLinesFor(id);
  if (lines) {
    AIT.toast(teamName(id) + ' has ' + lines + ' message' + (lines === 1 ? '' : 's') + ' in the sample conversation. Please give the messages to another AI actor or delete the messages first.', { error: true, duration: 4500 });
    return;
  }
  TEAM = TEAM.filter((m) => m.id !== id);
  if (DIR.actors[id]) { delete DIR.actors[id]; dirRemember(); dirSectionRemember('team'); }
  teamChanged(false);
}
$('addMemberBtn').addEventListener('click', addMember);

/** '' when the team can be saved, otherwise what is wrong. */
function teamProblem() {
  if (!bots().length) return 'The chat needs at least one AI actor.';
  if (!human()) return 'The human participant is missing. Reload the page and try again.';
  const seen = new Set();
  for (const m of TEAM) {
    const name = m.name.trim();
    if (!name) return isHuman(m) ? 'The participant needs a name.' : 'Every AI actor needs a name.';
    const key = name.toLowerCase();
    if (key === 'you') return '“' + name + '” cannot be used as a name, because the chat labels the participant’s messages “You”.';
    if (seen.has(key)) return 'Two people are called “' + name + '”. Names must be unique.';
    seen.add(key);
  }
  return '';
}

// ---- Pictures ------------------------------------------------------------------------
// Uploaded pictures are cropped to a centered square and scaled to 160 px, so
// a team of eleven stays small enough to travel with every chat page.
let avatarTarget = null;
function pickAvatar(id) {
  avatarTarget = id;
  $('avatarFile').value = '';
  $('avatarFile').click();
}
function resizeAvatar(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      const size = 160;
      const canvas = document.createElement('canvas');
      canvas.width = size;
      canvas.height = size;
      const ctx = canvas.getContext('2d');
      const side = Math.min(img.naturalWidth, img.naturalHeight);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, size, size);
      ctx.drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, size, size);
      URL.revokeObjectURL(url);
      let data = canvas.toDataURL('image/webp', 0.86);
      if (!data.startsWith('data:image/webp')) data = canvas.toDataURL('image/jpeg', 0.88); // browsers without WebP encoding
      if (data.length > 140000) data = canvas.toDataURL('image/jpeg', 0.7);
      resolve(data);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('unreadable image')); };
    img.src = url;
  });
}
$('avatarFile').addEventListener('change', async () => {
  const file = $('avatarFile').files[0];
  const member = avatarTarget && teamMember(avatarTarget);
  if (!file || !member) return;
  if (file.size > 20 * 1024 * 1024) {
    AIT.toast('This picture is too large. Please use a file smaller than 20 MB.', { error: true, duration: 4000 });
    return;
  }
  try {
    member.avatar = await resizeAvatar(file);
    teamChanged(false);
  } catch (_) {
    AIT.toast('This picture could not be read. Please use a PNG, JPEG, WebP or GIF file.', { error: true, duration: 4000 });
  }
});

// =============================================================================
// Step 3 · Sample conversation editor
// =============================================================================
// The stored format has absolute times (send_at, see includes/assist.php
// script_format_spec); the visual editor works with the pause before each
// message and the share of that pause during which the typing dots show.
//
// The conversation can be divided into segments (includes/segments.php): a
// segment header row starts each one, with its start time and a short prompt.
// The rows below a header belong to that segment, and the first message's
// pause counts from the segment's start. A message that would come at or
// after the next segment's start is cut (shown crossed out).
let mode = 'visual';
let scriptMeta = null; // non-message fields of the JSON, kept as they are
$('scriptJson').value = PROJECT.conversation_script;

const SEGMENT_PROMPT_MAX = 400; // 1 to 3 sentences (includes/segments.php)
const SEGMENT_START_MAX_S = 180 * 60;
const SEGMENT_COLORS = ['#6366f1', '#0d9488', '#d97706', '#db2777', '#0284c7', '#65a30d', '#9333ea'];
const segmentColor = (number) => SEGMENT_COLORS[(number - 1) % SEGMENT_COLORS.length];

/** 300 → "5:00" */
function clockText(s) { return Math.floor(s / 60) + ':' + String(Math.round(s % 60)).padStart(2, '0'); }
/** "5:00" or "5" (minutes) → seconds; null when it cannot be read. */
function parseClock(text) {
  const t = String(text || '').trim();
  const mmss = t.match(/^(\d{1,3}):([0-5]\d)$/);
  if (mmss) return Number(mmss[1]) * 60 + Number(mmss[2]);
  return /^\d{1,3}$/.test(t) ? Number(t) * 60 : null;
}

/** What is wrong with a list of segments [{start, prompt}], or '' (the rules of includes/segments.php). */
function segmentListProblem(segments) {
  for (let i = 0; i < segments.length; i++) {
    const s = segments[i];
    const n = i + 1;
    if (!Number.isFinite(s.start)) return 'Segment ' + n + ' needs a start time, written as minutes:seconds, for example 5:00.';
    if (i === 0 && s.start !== 0) return 'Segment 1 has to start at 0:00.';
    if (s.start < 0 || s.start > SEGMENT_START_MAX_S) return 'Segment ' + n + ' needs a start time between 0:01 and 180:00.';
    if (i > 0 && s.start <= segments[i - 1].start) return 'Segment ' + n + ' has to start after segment ' + i + ' (' + clockText(segments[i - 1].start) + ').';
    if (String(s.prompt || '').trim().length > SEGMENT_PROMPT_MAX) return 'The prompt of segment ' + n + ' is too long. Please keep the prompt to 1 to 3 sentences.';
  }
  return '';
}

function parseScriptJson() {
  const raw = $('scriptJson').value.trim();
  if (!raw) return { meta: null, messages: [], segments: [] };
  const parsed = JSON.parse(raw);
  let msgs;
  let segments = [];
  if (Array.isArray(parsed)) {
    msgs = parsed;
  } else if (parsed && Array.isArray(parsed.messages)) {
    msgs = parsed.messages;
    const { messages, segments: segs, ...rest } = parsed;
    scriptMeta = Object.keys(rest).length ? rest : null;
    if (segs !== undefined) {
      if (!Array.isArray(segs)) throw new Error('"segments" must be a list');
      segments = segs.map((s) => ({ start: Number(s && s.start), prompt: String((s && s.prompt) || '') }));
      const problem = segmentListProblem(segments);
      if (problem) throw new Error(problem);
      if (segments.length < 2) segments = [];
    }
  } else {
    throw new Error('The JSON must be a "messages" array or an object with a "messages" array');
  }
  msgs.forEach((m, i) => {
    if (!teamIds().includes(m.sender)) throw new Error('Message #' + (i + 1) + ': the sender "' + m.sender + '" is not an AI teammate (' + teamIds().join(', ') + ')');
    if (!m.is_empty && typeof m.send_at !== 'number') throw new Error('Message #' + (i + 1) + ' has no "send_at"');
    if (m.is_empty && (typeof m.typing_start !== 'number' || typeof m.typing_end !== 'number')) {
      throw new Error('Ghost message #' + (i + 1) + ' needs "typing_start" and "typing_end"');
    }
  });
  return { meta: scriptMeta, messages: msgs, segments };
}

/**
 * Absolute times → the editor's items in order: segment headers
 * { kind: 'segment', start, prompt } when the conversation is divided, and
 * message rows { kind: 'message', sender, content, gap, isGhost, ghostDuration,
 * typingPct }. The first message of a segment gets its pause from the start.
 */
function itemsFromScript(messages, segments) {
  const items = [];
  const place = (list, prevEnd) => list.forEach((m) => {
    const row = rowFromMessageAt(m, prevEnd);
    prevEnd = row.end;
    items.push(row.item);
  });
  if (!segments.length) {
    place(messages, 0);
    return items;
  }
  // Each message belongs to its stated segment, or to the one its time falls into.
  const byTime = (t) => segments.reduce((n, s, i) => (s.start <= t ? i + 1 : n), 1);
  const groups = segments.map(() => []);
  messages.forEach((m) => {
    const t = m.is_empty ? (m.typing_start || 0) : (m.send_at || 0);
    const n = Number.isInteger(m.segment) && m.segment >= 1 && m.segment <= segments.length ? m.segment : byTime(t);
    groups[n - 1].push(m);
  });
  segments.forEach((s, i) => {
    items.push({ kind: 'segment', start: s.start, prompt: s.prompt });
    place(groups[i], s.start);
  });
  return items;
}

/**
 * One stored message as an editor row, given when the previous one ended: { item, end }.
 * end is where jsonFromRows will place the row's end (the pauses add up), not
 * the stored time: a message that overlaps the previous one starts right after
 * it, and the rows after it keep their stored times instead of moving with it.
 */
function rowFromMessageAt(m, prevEnd) {
  if (m.is_empty) {
    const start = m.typing_start || 0;
    const gap = Math.max(0, Math.round(start - prevEnd));
    const ghostDuration = Math.max(1, Math.round((m.typing_end || 0) - start));
    return {
      item: { kind: 'message', sender: m.sender, content: '', isGhost: true, gap, ghostDuration, typingPct: 100 },
      end: prevEnd + gap + ghostDuration,
    };
  }
  let end = prevEnd;
  const item = Object.assign({ kind: 'message' }, rowFromMessageAfter(m, prevEnd, (e) => { end = e; }));
  return { item, end };
}
/** One regular message as a row, given when the previous one ended. */
function rowFromMessageAfter(m, prevEnd, setEnd) {
  const sendAt = m.send_at || 0;
  const slot = sendAt - prevEnd;
  let pct;
  if (typeof m.typing_pct === 'number') pct = Math.round(m.typing_pct);
  else if (typeof m.typing_start === 'number' && slot > 0) pct = Math.max(0, Math.round((sendAt - m.typing_start) / slot * 100));
  else pct = 60;
  const gap = Math.max(0, Math.round(slot));
  setEnd(prevEnd + gap); // where jsonFromRows places it (see rowFromMessageAt)
  return { sender: m.sender, content: m.content || '', isGhost: false, gap, ghostDuration: 0, typingPct: pct };
}

/** The start (seconds) of a segment header row. */
function segmentStartOf(header) {
  return header.classList.contains('seg-first-part') ? 0 : Number(header.dataset.start) || 0;
}

/**
 * The editor → the stored JSON: absolute times, and when the conversation is
 * divided, the segments and each message's segment number.
 */
function jsonFromRows() {
  const segments = [];
  const messages = [];
  let prevEnd = 0;
  Array.from($('scriptRows').children).forEach((el) => {
    if (el.classList.contains('seg-row')) {
      const start = segmentStartOf(el);
      segments.push({ start, prompt: el.querySelector('textarea').value.trim() });
      prevEnd = start;
      return;
    }
    const r = rowData(el);
    const id = messages.length + 1;
    let msg;
    if (r.isGhost) {
      const start = prevEnd + r.gap;
      const end = start + Math.max(1, r.ghostDuration);
      prevEnd = end;
      msg = { id, sender: r.sender, content: '', typing_start: start, typing_end: end, is_empty: true };
    } else {
      const sendAt = prevEnd + r.gap;
      const typingDur = Math.round(r.gap * r.typingPct / 100);
      msg = {
        id, sender: r.sender, content: r.content,
        typing_pct: r.typingPct, typing_start: Math.max(0, sendAt - typingDur),
        send_at: sendAt, is_empty: false,
      };
      prevEnd = sendAt;
    }
    if (segments.length) msg.segment = segments.length;
    messages.push(msg);
  });
  if (segments.length < 2) messages.forEach((m) => { delete m.segment; });
  if (!messages.length && !scriptMeta && segments.length < 2) return '';
  const out = Object.assign({}, scriptMeta || {});
  if (segments.length > 1) out.segments = segments;
  out.messages = messages;
  return JSON.stringify(out, null, 2);
}

/** The current sample conversation as JSON, whichever editor is active. */
function currentScriptJson() {
  return mode === 'visual' ? jsonFromRows() : $('scriptJson').value;
}

function showScriptError(msg) {
  $('scriptError').textContent = msg || '';
  $('scriptError').style.display = msg ? 'block' : 'none';
}

// ---- Rows ------------------------------------------------------------------------------
function stepper(value, unit, min, step, title, onChange) {
  const wrap = AIT.el('span', 'stepper');
  wrap.title = title;
  const minus = AIT.el('button', null, '−'); minus.type = 'button'; minus.tabIndex = -1;
  const val = AIT.el('span', 'val', value + unit);
  val.dataset.value = value;
  const plus = AIT.el('button', null, '+'); plus.type = 'button'; plus.tabIndex = -1;
  function set(v) {
    v = Math.max(min, v);
    val.dataset.value = v;
    val.textContent = v + unit;
    onChange(v);
  }
  minus.addEventListener('click', () => { snapshotScript(); set((parseInt(val.dataset.value, 10) || 0) - step); });
  plus.addEventListener('click', () => { snapshotScript(); set((parseInt(val.dataset.value, 10) || 0) + step); });
  wrap.append(minus, val, plus);
  return wrap;
}

let draggedRow = null;
function buildRow(data) {
  const row = AIT.applyRole(AIT.el('div', 's-row' + (data.isGhost ? ' is-ghost' : '')), data.sender);
  row.dataset.sender = data.sender;
  row.dataset.ghost = data.isGhost ? '1' : '0';
  row.draggable = true;

  row.appendChild(AIT.el('span', 'drag', '⋮⋮'));
  row.appendChild(AIT.el('span', 'num', ''));

  const sel = AIT.el('select', 'sender');
  fillSenderSelect(sel, data.sender);
  sel.title = 'Who writes this message';
  sel.addEventListener('change', () => {
    pushScript();
    row.dataset.sender = sel.value;
    AIT.applyRole(row, sel.value);
    syncScript();
  });
  row.appendChild(sel);

  row.appendChild(stepper(data.gap, 's', 0, 1, 'The pause in seconds after the previous message', (v) => {
    row.dataset.gap = v; syncScript();
  }));
  row.dataset.gap = data.gap;

  // typing % (regular messages) — hidden for ghosts
  const pct = stepper(data.typingPct, '%', 0, 10, 'How much of the pause shows the typing dots', (v) => {
    row.dataset.pct = v; syncScript();
  });
  pct.classList.add('pct');
  if (data.isGhost) pct.classList.add('hidden');
  row.appendChild(pct);
  row.dataset.pct = data.typingPct;

  // ghost duration — hidden for regular messages
  const ghostDur = stepper(data.ghostDuration || 3, 's', 1, 1, 'How long the typing dots are shown before the teammate stops without sending', (v) => {
    row.dataset.ghostDur = v; syncScript();
  });
  ghostDur.classList.add('ghost-dur');
  if (!data.isGhost) ghostDur.classList.add('hidden');
  row.appendChild(ghostDur);
  row.dataset.ghostDur = data.ghostDuration || 3;

  // A text box that grows with the message, so long messages stay readable.
  // One message is one paragraph of chat: Enter and pasted line breaks add no new line.
  const content = AIT.el('textarea', 'content');
  content.rows = 1;
  content.placeholder = 'Message text…';
  content.value = data.content || '';
  content.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
  content.addEventListener('input', () => {
    if (/[\r\n]/.test(content.value)) content.value = content.value.replace(/\s*[\r\n]+\s*/g, ' ');
    fitMessages([content]);
    snapshotScript(); syncScript();
  });
  if (data.isGhost) content.classList.add('hidden');
  row.appendChild(content);

  const ghostNote = AIT.el('span', 'ghost-note', 'ghost typing: the teammate starts typing and stops without sending');
  if (!data.isGhost) ghostNote.classList.add('hidden');
  row.appendChild(ghostNote);

  // Filled in by refreshSegmentsView when the message comes after the next segment's start.
  row.appendChild(AIT.el('span', 'cut-note'));

  const actions = AIT.el('div', 'actions');
  const splitBtn = AIT.el('button', 'split-btn');
  splitBtn.innerHTML = '<i class="fas fa-cut"></i>';
  splitBtn.type = 'button';
  splitBtn.title = 'Start a new segment with this message';
  splitBtn.addEventListener('click', () => splitBefore(row));
  const ghostBtn = AIT.el('button', 'ghost-btn' + (data.isGhost ? ' on' : ''));
  ghostBtn.innerHTML = '<i class="fas fa-ghost"></i>';
  ghostBtn.type = 'button';
  ghostBtn.title = 'Ghost typing: the teammate starts typing and stops without sending';
  ghostBtn.addEventListener('click', () => {
    pushScript();
    const nowGhost = row.dataset.ghost !== '1';
    row.dataset.ghost = nowGhost ? '1' : '0';
    row.classList.toggle('is-ghost', nowGhost);
    ghostBtn.classList.toggle('on', nowGhost);
    content.classList.toggle('hidden', nowGhost);
    if (!nowGhost) fitMessages([content]);
    ghostNote.classList.toggle('hidden', !nowGhost);
    pct.classList.toggle('hidden', nowGhost);
    ghostDur.classList.toggle('hidden', !nowGhost);
    syncScript();
  });
  const dupBtn = AIT.el('button');
  dupBtn.innerHTML = '<i class="far fa-clone"></i>';
  dupBtn.type = 'button';
  dupBtn.title = 'Duplicate this message';
  dupBtn.addEventListener('click', () => {
    pushScript();
    const copy = buildRow(rowData(row));
    row.after(copy);
    fitMessages(copy.querySelectorAll('textarea.content'));
    renumber(); syncScript();
  });
  const delBtn = AIT.el('button');
  delBtn.innerHTML = '<i class="fas fa-times"></i>';
  delBtn.type = 'button';
  delBtn.title = 'Delete this message';
  delBtn.addEventListener('click', () => { pushScript(); row.remove(); renumber(); syncScript(); });
  actions.append(splitBtn, ghostBtn, dupBtn, delBtn);
  row.appendChild(actions);
  row.appendChild(AIT.el('span', 's-break')); // narrow screens: the timing starts a new line (settings.css)

  const insert = AIT.el('button', 'insert-below');
  insert.innerHTML = '<i class="fas fa-plus"></i>';
  insert.type = 'button';
  insert.title = 'Insert a message below';
  insert.addEventListener('click', () => {
    pushScript();
    row.after(buildRow({ sender: nextSender(row.dataset.sender), content: '', gap: 8, isGhost: false, ghostDuration: 3, typingPct: 60 }));
    renumber(); syncScript();
  });
  row.appendChild(insert);

  // drag & drop reorder
  row.addEventListener('dragstart', (e) => {
    draggedRow = row;
    row.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  row.addEventListener('dragend', () => {
    row.classList.remove('dragging');
    draggedRow = null;
    document.querySelectorAll('.s-row.drag-over').forEach((r) => r.classList.remove('drag-over'));
    renumber(); syncScript();
  });
  row.addEventListener('dragover', (e) => {
    e.preventDefault();
    if (!draggedRow || draggedRow === row) return;
    row.classList.add('drag-over');
  });
  row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
  row.addEventListener('drop', (e) => {
    e.preventDefault();
    row.classList.remove('drag-over');
    if (draggedRow && draggedRow !== row) { pushScript(); row.before(draggedRow); }
  });
  return row;
}

/** The teammate after s in team order (new rows rotate through the team). */
function nextSender(s) {
  const ids = teamIds();
  return ids[(ids.indexOf(s) + 1) % ids.length] || ids[0];
}
function rowData(row) {
  return {
    sender: row.dataset.sender,
    content: row.querySelector('textarea.content').value,
    gap: parseInt(row.dataset.gap, 10) || 0,
    isGhost: row.dataset.ghost === '1',
    ghostDuration: parseInt(row.dataset.ghostDur, 10) || 3,
    typingPct: parseInt(row.dataset.pct, 10) || 0,
  };
}
/** The message rows of the visual editor (without the segment headers). */
function messageRows() {
  return Array.from($('scriptRows').children).filter((el) => el.classList.contains('s-row'));
}
function readRowsFromDom() {
  return messageRows().map(rowData);
}
function renumber() {
  messageRows().forEach((row, i) => {
    row.querySelector('.num').textContent = i + 1;
  });
}

function renderRowsFromJson(silent) {
  try {
    const { messages, segments } = parseScriptJson();
    const host = $('scriptRows');
    host.innerHTML = '';
    itemsFromScript(messages, segments).forEach((it) => host.appendChild(it.kind === 'segment' ? buildSegmentRow(it) : buildRow(it)));
    fitMessages();
    renumber();
    refreshSegmentsView();
    showScriptError('');
    return true;
  } catch (e) {
    if (!silent) showScriptError('The visual editor cannot open this JSON: ' + e.message);
    return false;
  }
}

// The message boxes fit their text again whenever the column changes width, for
// example when the AI Assistant docks beside Step 3 (dirRegrow covers the window
// size and Step 3 becoming visible).
let scriptRowsWidth = 0;
if ('ResizeObserver' in window) {
  new ResizeObserver(([entry]) => {
    const w = Math.round(entry.contentRect.width); // 0 while Step 3 is hidden
    if (w !== scriptRowsWidth) { scriptRowsWidth = w; if (w) fitMessages(); }
  }).observe($('scriptRows'));
}

/** Visual → JSON sync. */
function syncScript() {
  if (mode !== 'visual') return;
  $('scriptJson').value = jsonFromRows();
  // A discrete action (pushScript before it) is complete: its result becomes
  // the undo stack's current state, so one Undo reverts exactly one action.
  if (actionPending) { actionPending = false; scriptUndo.push($('scriptJson').value); }
  refreshSegmentsView();
  updateStats();
  setDirty(true);
}

// ---- Segments ------------------------------------------------------------------------------

/** A segment header row: number, start time, span and the segment's prompt. */
function buildSegmentRow(seg) {
  const first = seg.start === 0; // segment 1 always starts with the chat
  const el = AIT.el('div', 'seg-row' + (first ? ' seg-first-part' : ''));
  el.dataset.start = seg.start;

  const top = AIT.el('div', 'seg-top');
  const badge = AIT.el('span', 'seg-badge');
  badge.innerHTML = '<i class="fas fa-cut"></i> ';
  badge.appendChild(AIT.el('span', 'seg-num'));
  top.appendChild(badge);

  const time = AIT.el('span', 'seg-time');
  time.innerHTML = '<i class="far fa-clock"></i> starts at ';
  if (first) {
    time.appendChild(AIT.el('b', null, '0:00'));
  } else {
    const input = AIT.el('input');
    input.type = 'text';
    input.value = clockText(seg.start);
    input.setAttribute('aria-label', 'Start of this segment, minutes:seconds after the chat starts');
    input.addEventListener('input', () => {
      snapshotScript();
      const v = parseClock(input.value);
      const ok = v !== null && v >= 1 && v <= SEGMENT_START_MAX_S;
      input.classList.toggle('invalid', !ok);
      if (ok) el.dataset.start = v; // the segment's messages keep their pauses and move with it
      syncScript();
    });
    input.addEventListener('blur', () => {
      if (!input.classList.contains('invalid')) input.value = clockText(segmentStartOf(el));
    });
    time.appendChild(input);
  }
  top.appendChild(time);
  top.appendChild(AIT.el('span', 'seg-span'));
  if (!first) {
    const del = AIT.el('button', 'seg-del');
    del.type = 'button';
    del.title = 'Remove this segment (its messages join the segment before)';
    del.innerHTML = '<i class="fas fa-times"></i>';
    del.addEventListener('click', () => removeSegment(el));
    top.appendChild(del);
  }

  const wrap = AIT.el('div', 'seg-prompt');
  const prompt = AIT.el('textarea');
  prompt.rows = 2;
  prompt.maxLength = SEGMENT_PROMPT_MAX;
  prompt.value = seg.prompt || '';
  prompt.placeholder = first
    ? 'Optional: what this first part of the conversation is about, in 1 to 3 sentences.'
    : 'What changes at this moment? 1 to 3 sentences, for example: Blue has to answer an urgent email and says so in one short line. Until the next segment Blue writes nothing.';
  prompt.setAttribute('aria-label', 'Prompt of this segment, 1 to 3 sentences');
  const count = AIT.el('span', 'seg-count');
  const paintCount = () => { count.textContent = prompt.value.length + ' / ' + SEGMENT_PROMPT_MAX; };
  prompt.addEventListener('input', () => { snapshotScript(); paintCount(); syncScript(); });
  paintCount();
  wrap.append(prompt, count);
  el.append(top, wrap);

  // A message dropped on the header becomes the segment's first message.
  el.addEventListener('dragover', (e) => { if (!draggedRow) return; e.preventDefault(); el.classList.add('drag-over'); });
  el.addEventListener('dragleave', () => el.classList.remove('drag-over'));
  el.addEventListener('drop', (e) => {
    e.preventDefault();
    el.classList.remove('drag-over');
    if (draggedRow) { pushScript(); el.after(draggedRow); }
  });
  return el;
}

/** After every change: segment numbers, colors, spans, and the messages that are cut. */
function refreshSegmentsView() {
  const host = $('scriptRows');
  const children = Array.from(host.children);
  const headers = children.filter((el) => el.classList.contains('seg-row'));
  host.classList.toggle('segmented', headers.length > 0);
  const stats = headers.map(() => ({ messages: 0, cut: 0 }));
  let segIndex = -1;
  let prevEnd = 0;
  let firstInSegment = true;
  children.forEach((el) => {
    if (el.classList.contains('seg-row')) {
      segIndex++;
      prevEnd = segmentStartOf(el);
      firstInSegment = true;
      el.style.setProperty('--seg-color', segmentColor(segIndex + 1));
      el.querySelector('.seg-num').textContent = 'Segment ' + (segIndex + 1);
      return;
    }
    const r = rowData(el);
    const at = prevEnd + r.gap;                                     // when the message (or ghost typing) starts
    const end = r.isGhost ? at + Math.max(1, r.ghostDuration) : at; // when it is over
    const next = headers[segIndex + 1];
    const cut = !!next && end >= segmentStartOf(next);
    el.classList.toggle('is-cut', cut);
    el.classList.toggle('seg-first', firstInSegment); // no scissors on a segment's first message
    if (segIndex >= 0) el.style.setProperty('--seg-color', segmentColor(segIndex + 1));
    else el.style.removeProperty('--seg-color');
    el.querySelector('.cut-note').textContent = cut ? 'cut at ' + clockText(segmentStartOf(next)) : '';
    el.title = cut ? 'This message would come after the next segment starts, so it is cut and not shown.' : '';
    if (segIndex >= 0) { stats[segIndex].messages++; if (cut) stats[segIndex].cut++; }
    prevEnd = end;
    firstInSegment = false;
  });
  headers.forEach((h, i) => {
    const start = segmentStartOf(h);
    const next = headers[i + 1];
    const span = h.querySelector('.seg-span');
    const s = stats[i];
    span.textContent = (next ? clockText(start) + ' to ' + clockText(segmentStartOf(next)) : 'from ' + clockText(start)) +
      ' · ' + s.messages + ' message' + (s.messages === 1 ? '' : 's');
    if (s.cut) span.appendChild(AIT.el('span', 'cut', ' · ' + s.cut + ' cut'));
    const input = h.querySelector('.seg-time input');
    if (input) input.classList.toggle('out-of-order', i > 0 && start <= segmentStartOf(headers[i - 1]));
  });
  $('addSegmentBtn').disabled = headers.length >= 20;
}

/** Rewrite the editor from changed JSON data (segment edits work on absolute times, so nothing moves). */
function applyScriptData(data) {
  if (!data.segments || data.segments.length < 2) {
    delete data.segments;
    (data.messages || []).forEach((m) => { delete m.segment; });
  }
  $('scriptJson').value = JSON.stringify(data, null, 2);
  renderRowsFromJson(true);
  syncScript();
}

/**
 * Start a new segment with this message. It starts a second after the
 * previous message, which stays in the segment before; no message moves
 * (unless it followed its predecessor within the same second).
 */
function splitBefore(row) {
  const index = messageRows().indexOf(row);
  if (index <= 0) return;
  if (document.querySelectorAll('#scriptRows .seg-row').length >= 20) {
    AIT.toast('A sample conversation can have at most 20 segments.', { error: true });
    return;
  }
  pushScript();
  const data = JSON.parse(jsonFromRows());
  const messages = data.messages;
  const prev = messages[index - 1];
  const start = (prev.is_empty ? prev.typing_end : prev.send_at) + 1;
  const segments = data.segments || [{ start: 0, prompt: '' }];
  const current = messages[index].segment || 1;
  const after = segments[current]; // the segment that follows the current one, if any
  if (start <= segments[current - 1].start || (after && start >= after.start)) {
    AIT.toast('A new segment has to start after the current segment and before the next segment. Please try another message.', { error: true });
    return;
  }
  segments.splice(current, 0, { start, prompt: '' });
  messages.forEach((m, i) => {
    const n = m.segment || 1;
    m.segment = n > current || (n === current && i >= index) ? n + 1 : n;
  });
  data.segments = segments;
  applyScriptData(data);
  const header = $('scriptRows').querySelectorAll('.seg-row')[current];
  if (header) {
    header.scrollIntoView({ block: 'center', behavior: 'smooth' });
    header.querySelector('textarea').focus({ preventScroll: true });
  }
}

/** Remove a segment: its messages join the segment before, at the same times. */
function removeSegment(header) {
  const number = Array.from($('scriptRows').querySelectorAll('.seg-row')).indexOf(header) + 1;
  if (number < 2) return;
  pushScript();
  const data = JSON.parse(jsonFromRows());
  data.segments.splice(number - 1, 1);
  data.messages.forEach((m) => { if (m.segment >= number) m.segment -= 1; });
  applyScriptData(data);
}

// While the AI Assistant writes a new conversation: its segments' headers
// appear as their first messages arrive (ctx.script.live below).
let liveSegments = []; // the segments of the conversation being written
let liveShown = 0;     // how many of their headers are on screen
function showLiveSegmentsUpTo(number) {
  while (liveShown < number && liveShown < liveSegments.length) {
    $('scriptRows').appendChild(buildSegmentRow(liveSegments[liveShown]));
    liveShown++;
  }
  refreshSegmentsView();
}

$('addSegmentBtn').addEventListener('click', () => {
  pushScript();
  const data = JSON.parse(jsonFromRows() || '{"messages":[]}');
  const messages = data.messages || [];
  const last = messages[messages.length - 1];
  const lastEnd = last ? (last.is_empty ? last.typing_end : last.send_at) : 0;
  const segments = data.segments || [{ start: 0, prompt: '' }];
  if (!data.segments) messages.forEach((m) => { m.segment = 1; });
  const start = Math.min(SEGMENT_START_MAX_S, Math.max(Math.ceil((lastEnd + 5) / 10) * 10, segments[segments.length - 1].start + 10));
  segments.push({ start, prompt: '' });
  data.segments = segments;
  data.messages = messages;
  applyScriptData(data);
  const headers = $('scriptRows').querySelectorAll('.seg-row');
  const header = headers[headers.length - 1];
  if (header) {
    header.scrollIntoView({ block: 'center', behavior: 'smooth' });
    header.querySelector('textarea').focus({ preventScroll: true });
  }
});

/** What keeps the segments from being saved, or ''. (In the JSON tab, parseScriptJson reports it.) */
function segmentsProblem() {
  if (mode !== 'visual') return '';
  const headers = Array.from($('scriptRows').querySelectorAll('.seg-row'));
  if (headers.some((h) => { const i = h.querySelector('.seg-time input'); return i && i.classList.contains('invalid'); })) {
    return 'Every segment needs a start time between 0:01 and 180:00, written as minutes:seconds, for example 5:00.';
  }
  return segmentListProblem(headers.map((h) => ({ start: segmentStartOf(h), prompt: h.querySelector('textarea').value })));
}
function snapshotScript() { scriptUndo.snapshot($('scriptJson').value); }
/** Before a discrete action (delete, move, cut …): record the state it starts from. */
let actionPending = false;
function pushScript() {
  if (mode === 'visual') $('scriptJson').value = jsonFromRows();
  scriptUndo.push($('scriptJson').value);
  actionPending = true;
}

/** Message count, length and segments of the sample conversation. */
function scriptSummary() {
  const empty = { messages: 0, ghosts: 0, endS: 0, segments: 0, segmentStarts: [], cut: 0 };
  const raw = $('scriptJson').value.trim();
  if (!raw) return Object.assign({ ok: true }, empty);
  try {
    const parsed = JSON.parse(raw);
    const msgs = Array.isArray(parsed) ? parsed : (parsed.messages || []);
    const real = msgs.filter((m) => !m.is_empty).length;
    const endS = msgs.reduce((mx, m) => Math.max(mx, m.send_at || m.typing_end || 0), 0);
    const segs = !Array.isArray(parsed) && Array.isArray(parsed.segments) && parsed.segments.length > 1 ? parsed.segments : [];
    const starts = segs.map((s) => Number(s.start) || 0);
    // Messages that come at or after the start of the segment after their own are cut.
    const byTime = (t) => starts.reduce((n, st, i) => (st <= t ? i + 1 : n), 1);
    const cut = starts.length ? msgs.filter((m) => {
      const t = m.is_empty ? m.typing_end : m.send_at;
      const n = Number.isInteger(m.segment) ? m.segment : byTime(t);
      return starts[n] !== undefined && t >= starts[n];
    }).length : 0;
    return { ok: true, messages: real, ghosts: msgs.length - real, endS, segments: segs.length, segmentStarts: starts, cut };
  } catch (_) {
    return Object.assign({ ok: false }, empty);
  }
}
function minutesText(s) {
  return Math.floor(s / 60) + ':' + String(Math.round(s % 60)).padStart(2, '0') + ' min';
}
function updateStats() {
  const s = scriptSummary();
  $('scriptStats').textContent = !s.ok ? 'invalid JSON'
    : !s.messages && !s.ghosts ? 'empty'
      : countText(s.messages, 'message') + (s.ghosts ? ' + ' + countText(s.ghosts, 'ghost') : '') + ' · ends at ' + minutesText(s.endS) +
        (s.segments ? ' · ' + s.segments + ' segments' : '');
  refreshTeamCounts();
}
/** Line counts in the participants card follow the editor. */
function refreshTeamCounts() {
  document.querySelectorAll('#teamRows .t-row').forEach((row) => {
    const id = row.dataset.id;
    if (id === 'human') return;
    const lines = scriptLinesFor(id);
    const el = row.querySelector('.t-count');
    if (el) el.textContent = lines ? lines + ' message' + (lines === 1 ? '' : 's') + ' in the sample conversation' : 'no messages yet';
  });
}

// Tabs
$('tabVisual').addEventListener('click', () => {
  if (mode === 'visual') return;
  if (!renderRowsFromJson(false)) return; // stay on JSON if it does not parse
  mode = 'visual';
  $('tabVisual').classList.add('active');
  $('tabJson').classList.remove('active');
  $('scriptRows').classList.remove('hidden');
  fitMessages(); // the rows were built while hidden
  $('scriptAdd').classList.remove('hidden');
  $('segNote').classList.remove('hidden');
  $('scriptJsonWrap').classList.add('hidden');
  $('validateBtn').classList.add('hidden');
});
$('tabJson').addEventListener('click', () => {
  if (mode === 'json') return;
  $('scriptJson').value = jsonFromRows();
  mode = 'json';
  $('tabJson').classList.add('active');
  $('tabVisual').classList.remove('active');
  $('scriptRows').classList.add('hidden');
  $('scriptAdd').classList.add('hidden');
  $('segNote').classList.add('hidden');
  $('scriptJsonWrap').classList.remove('hidden');
  $('validateBtn').classList.remove('hidden');
  updateStats();
});

$('addRowBtn').addEventListener('click', () => {
  pushScript();
  const lastRow = messageRows().pop();
  const lastSender = lastRow ? lastRow.dataset.sender : teamIds()[teamIds().length - 1];
  $('scriptRows').appendChild(buildRow({ sender: nextSender(lastSender), content: '', gap: 8, isGhost: false, ghostDuration: 3, typingPct: 60 }));
  renumber();
  syncScript();
});

$('scriptJson').addEventListener('input', () => {
  scriptUndo.snapshot($('scriptJson').value);
  updateStats();
  setDirty(true);
});

$('validateBtn').addEventListener('click', () => {
  if (mode === 'visual') $('scriptJson').value = jsonFromRows();
  try {
    const { messages } = parseScriptJson();
    showScriptError('');
    AIT.toast('The JSON is valid and contains ' + countText(messages.filter((m) => !m.is_empty).length, 'message') + '.');
  } catch (e) {
    showScriptError(e.message);
  }
});

$('undoScript').addEventListener('click', () => {
  const prev = scriptUndo.undo();
  if (prev !== null) {
    $('scriptJson').value = prev;
    if (mode === 'visual') renderRowsFromJson(true);
    updateStats();
    setDirty(true);
  }
});

// =============================================================================
// Step 3 · Conductor instructions in sections
// =============================================================================
// The instructions are edited in text boxes (the sections of
// includes/director_sections.php, see DIR_KEYS) plus a text per AI actor. The
// boxes are grouped into four cards whose display order is fixed; DIR.order is
// the order in the prompt only, changed in the map of the "Full prompt" card.
// DIR holds the current state; the prompt the conductor receives is assembled
// from DIR exactly as the server does (sections in order, empty sections
// skipped, one blank line between them). For undo and the AI Assistant the
// state travels as one text with marker lines ("## [goal] Purpose"), see
// dirMarkerText() and dirParseMarkerText().
const DIR_KEYS = ['goal', 'situation', 'team', 'bots', 'manip', 'rewrite', 'tech']; // bots: the texts per actor, a place in the order only
const DIR_TITLES = { goal: 'Purpose', situation: 'Situation', team: 'AI actors', bots: 'Text per actor', manip: 'Dynamics', rewrite: 'Rewrite rules', tech: 'Output format' };
const DIR_CARDS = { situation: ['situation'], actors: ['team'], dynamics: ['manip'], conductor: ['goal', 'rewrite', 'tech'] };
const DIR_CARD_TITLES = { situation: 'Situation', actors: 'AI actors', dynamics: 'Dynamics', conductor: 'Conductor' };
const DIR_CARD_OF = {};
Object.keys(DIR_CARDS).forEach((card) => DIR_CARDS[card].forEach((key) => { DIR_CARD_OF[key] = card; }));
DIR_CARD_OF.bots = 'actors';
const DIR_MARKER = /^## \[([a-z0-9]+)\]/;

function dirNormalize(raw) {
  const out = { order: [], text: {}, actors: {} };
  const src = raw && typeof raw === 'object' ? raw : {};
  DIR_KEYS.forEach((k) => { out.text[k] = typeof (src.text || {})[k] === 'string' ? src.text[k].replace(/\r\n/g, '\n') : ''; });
  (Array.isArray(src.order) ? src.order : []).forEach((k) => { if (DIR_KEYS.includes(k) && !out.order.includes(k)) out.order.push(k); });
  if (!out.order.includes('bots') && out.order.includes('team')) out.order.splice(out.order.indexOf('team') + 1, 0, 'bots'); // earlier shapes
  DIR_KEYS.forEach((k) => { if (!out.order.includes(k)) out.order.push(k); });
  out.text.bots = '';
  Object.keys(src.actors || {}).forEach((id) => {
    if (/^bot(10|[1-9])$/.test(id) && typeof src.actors[id] === 'string' && src.actors[id].trim() !== '') out.actors[id] = src.actors[id].replace(/\r\n/g, '\n');
  });
  return out;
}
let DIR = dirNormalize(PROJECT.director_sections);

// One undo stack per card (the hidden global stack serves the AI Assistant).
const dirUndos = {};
function dirCardState(card) {
  const o = { text: {} };
  DIR_CARDS[card].forEach((k) => { o.text[k] = DIR.text[k]; });
  if (card === 'actors') o.actors = DIR.actors;
  return JSON.stringify(o);
}
function dirApplyCardState(card, state) {
  const o = JSON.parse(state);
  Object.assign(DIR.text, o.text);
  if (o.actors) { DIR.actors = o.actors; renderTeamRows(); }
  renderDirSections();
}
function dirCardSnapshot(card) { dirUndos[card].snapshot(dirCardState(card)); }
function dirCardRemember(card) { dirUndos[card].push(dirCardState(card)); }
function dirSectionSnapshot(key) { dirCardSnapshot(DIR_CARD_OF[key]); }
function dirSectionRemember(key) { dirCardRemember(DIR_CARD_OF[key]); }

const dirCardEl = (card) => document.querySelector('#dirSections .dir-sec[data-card="' + card + '"]');
const dirFieldEl = (key) => document.querySelector('#dirSections .dir-field[data-key="' + key + '"]');
const dirTextarea = (key) => document.querySelector('#dirSections .dir-ta[data-key="' + key + '"]');
function dirActorText(id) { return DIR.actors[id] || ''; }

/** The texts per actor as the prompt shows them ("Instructions for Blue only:"), in team order. */
function dirActorsText() {
  const parts = [];
  bots().forEach((m) => {
    const t = dirActorText(m.id);
    if (t.trim() !== '') parts.push('Instructions for ' + displayName(m) + ' only:\n' + t);
  });
  return parts.join('\n\n');
}
function dirKeyText(key) { return key === 'bots' ? dirActorsText() : DIR.text[key]; }

/** The prompt the conductor receives: the boxes in the prompt order, empty boxes skipped. */
function assembleDirector() {
  const parts = [];
  DIR.order.forEach((key) => {
    const text = dirKeyText(key);
    if (text.trim() !== '') parts.push(text);
  });
  return parts.join('\n\n');
}

/** One text for undo and the AI Assistant: every box under a marker line, in the prompt order. */
function dirMarkerText() {
  const blocks = [];
  DIR.order.forEach((key) => {
    if (key === 'bots') { // the texts per actor, one marker per actor
      bots().forEach((m) => {
        blocks.push('## [' + m.id + '] AI actors: only ' + displayName(m) + '\n' + dirActorText(m.id));
      });
      return;
    }
    const title = key === 'team' ? 'AI actors: all actors' : DIR_TITLES[key];
    blocks.push('## [' + key + '] ' + title + '\n' + DIR.text[key]);
  });
  return blocks.join('\n\n');
}

/** Back from the marker text. Boxes and actors that the text does not mention keep their current value. */
function dirParseMarkerText(text, base) {
  const out = { order: [], text: Object.assign({}, base.text), actors: Object.assign({}, base.actors) };
  const lines = String(text).replace(/\r\n/g, '\n').split('\n');
  let key = null;
  let body = [];
  const flush = () => {
    if (key === null) return;
    let t = body.join('\n');
    if (t.endsWith('\n\n')) t = t.slice(0, -2); // the blank line before the next marker
    else if (t.endsWith('\n')) t = t.slice(0, -1);
    if (DIR_KEYS.includes(key) && key !== 'bots') { out.text[key] = t; if (!out.order.includes(key)) out.order.push(key); }
    else if (/^bot(10|[1-9])$/.test(key)) {
      if (t.trim() !== '') out.actors[key] = t; else delete out.actors[key];
      if (!out.order.includes('bots')) out.order.push('bots'); // the texts per actor take the place of the first actor marker
    }
  };
  lines.forEach((line) => {
    const m = line.match(DIR_MARKER);
    if (m) { flush(); key = m[1]; body = []; }
    else if (key !== null) body.push(line);
  });
  flush();
  base.order.forEach((k) => { if (!out.order.includes(k)) out.order.push(k); });
  return dirNormalize(out);
}

/** Apply a state (marker text): after undo, or when the AI Assistant answers. */
function dirApplyState(text) {
  DIR = dirParseMarkerText(text, DIR);
  renderDirSections();
  renderTeamRows();
  Object.keys(DIR_CARDS).forEach((card) => dirCardRemember(card));
}
function dirRemember() { directorUndo.push(dirMarkerText()); } // discrete actions
function dirChanged() { // after every change to DIR
  refreshDirectorCount();
  refreshDirFull();
  scheduleEstimate();
  setDirty(true);
}

/**
 * Fit the message boxes of the sample conversation to their text (all of them
 * by default). Hidden boxes measure as empty and keep their height until the
 * rows become visible (dirRegrow, the Visual tab, the ResizeObserver below).
 */
function fitMessages(boxes = $('scriptRows').querySelectorAll('textarea.content')) {
  const shown = Array.from(boxes).filter((ta) => ta.offsetParent !== null);
  shown.forEach((ta) => { ta.style.height = 'auto'; });
  const heights = shown.map((ta) => ta.scrollHeight); // read all at once: one layout pass
  shown.forEach((ta, i) => { ta.style.height = heights[i] + 'px'; });
}

/** Grow a text box with its content, between minRows and maxRows lines. */
function autoGrow(ta, minRows, maxRows) {
  const cs = getComputedStyle(ta);
  const line = parseFloat(cs.lineHeight) || 20;
  const pad = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom) + parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth);
  const min = Math.round(line * minRows + pad);
  const max = Math.round(line * maxRows + pad);
  ta.style.height = min + 'px';
  if (ta.value === '') { ta.classList.remove('is-max'); return; } // the placeholder does not count
  const want = Math.min(max, Math.max(min, ta.scrollHeight + 1));
  ta.style.height = want + 'px';
  ta.classList.toggle('is-max', ta.scrollHeight > max);
}

/** Show the texts of DIR in the boxes (the cards keep their fixed order). */
function renderDirSections() {
  DIR_KEYS.forEach((key) => {
    const ta = dirTextarea(key);
    if (!ta) return; // bots has no box of its own
    if (ta.value !== DIR.text[key]) ta.value = DIR.text[key];
    autoGrow(ta, 3, 14);
    dirEditorSet(key, DIR.text[key]);
    syncDirPills(key);
  });
  refreshDirPreviews();
  refreshDirectorCount();
  refreshDirFull();
}

/** The filled marks on the cards and, while the map is open, the map rows. */
function refreshDirPreviews() {
  Object.keys(DIR_CARDS).forEach((card) => {
    const filled = DIR_KEYS.some((key) => DIR_CARD_OF[key] === card && dirKeyText(key).trim() !== '');
    dirCardEl(card).classList.toggle('is-filled', filled);
  });
  refreshDirFull();
}

function refreshDirectorCount() {
  const text = assembleDirector().trim();
  const words = text ? text.split(/\s+/).length : 0;
  $('directorCount').textContent = words
    ? words.toLocaleString('en-US') + ' words · about ' + formatTokens(estimateTokens(text, currentFamily())) + ' tokens (billing units of text), sent to the conductor with every rewrite'
    : '';
}
/** The full prompt as blocks, when the prompt is shown. */
function refreshDirFull() {
  if (!$('dirFull').classList.contains('hidden')) renderDirFull();
}
$('dirShowPrompt').addEventListener('click', () => {
  const open = $('dirFull').classList.toggle('hidden') === false;
  $('dirShowPrompt').textContent = open ? 'Hide the full prompt' : 'Show the full prompt';
  $('dirShowPrompt').setAttribute('aria-expanded', open ? 'true' : 'false');
  if (!open) setDirReordering(false);
  refreshDirFull();
});

// Typing in a box
document.querySelectorAll('#dirSections .dir-ta').forEach((ta) => {
  ta.addEventListener('input', () => {
    DIR.text[ta.dataset.key] = ta.value;
    autoGrow(ta, 3, 14);
    syncDirPills(ta.dataset.key);
    directorUndo.snapshot(dirMarkerText());
    dirSectionSnapshot(ta.dataset.key);
    refreshDirPreviews();
    dirChanged();
  });
});

// Suggested instructions: one click appends the sentence as a new paragraph, a second click removes the sentence.
function pillText(btn) { return btn.dataset.text.replace(/\{name\}/g, dirFirstActorName()); }
function dirFirstActorName() { const b = bots()[0]; return b ? displayName(b) : 'Blue'; }
function syncDirPills(key) {
  const text = DIR.text[key];
  dirFieldEl(key).querySelectorAll('.dir-pill[data-text]').forEach((btn) => {
    const on = text.includes(pillText(btn));
    btn.classList.toggle('is-on', on);
    btn.querySelector('i').className = on ? 'fas fa-check' : 'fas fa-plus';
  });
}
function refreshDirPillNames() { // "{name} keeps time" follows the first AI actor
  const name = dirFirstActorName();
  document.querySelectorAll('#dirSections .dir-pill[data-text*="{name}"]').forEach((btn) => {
    btn.querySelector('span').textContent = btn.dataset.label.replace(/\{name\}/g, name);
    btn.title = pillText(btn);
    syncDirPills(btn.closest('.dir-field').dataset.key);
  });
}
document.querySelectorAll('#dirSections .dir-pill[data-text]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const key = btn.closest('.dir-field').dataset.key;
    const sentence = pillText(btn);
    let text = DIR.text[key];
    if (text.includes(sentence)) {
      text = text.replace(sentence, '').replace(/\n[ \t]*\n[ \t]*\n+/g, '\n\n').replace(/^\s+|\s+$/g, '');
    } else {
      text = text.replace(/\s+$/, '');
      text = (text ? text + '\n\n' : '') + sentence;
    }
    DIR.text[key] = text;
    const ta = dirTextarea(key);
    ta.value = text;
    autoGrow(ta, 3, 14);
    dirEditorSet(key, text);
    syncDirPills(key);
    dirRemember();
    dirSectionRemember(key);
    dirChanged();
    refreshDirPreviews();
  });
});

// Expert boxes start folded: a click on the title line or on Show / Hide opens the box.
document.querySelectorAll('#dirSections .dir-field[data-fold]').forEach((field) => {
  const btn = field.querySelector('.dir-fold');
  btn.addEventListener('click', () => {
    const open = field.classList.toggle('is-open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.textContent = open ? 'Hide' : 'Show';
    if (open) dirRegrow();
  });
  field.querySelector('.dir-field-head').addEventListener('click', (e) => {
    if (e.target.closest('button')) return;
    btn.click();
  });
});

// The full prompt (in the "Full prompt" card): every block the conductor
// receives, in the prompt order. The boxes are movable blocks, tinted like
// their card; the parts the software adds are grey and fixed, with curly
// placeholders for what the software fills in at every rewrite. "Reorder"
// collapses the blocks to their title lines: drag a block or use the arrows.
const DIR_MAP_LABELS = Object.assign({}, DIR_TITLES, { team: 'AI actors', bots: 'Text per actor' });
let dirReordering = false;
function dirBlock(cls, title, sub, body) {
  const el = document.createElement('div');
  el.className = 'dir-block ' + cls;
  const head = document.createElement('div');
  head.className = 'dir-block-head';
  head.innerHTML = '<i class="' + (cls.includes('is-move') ? 'fas fa-grip-vertical' : 'fas fa-lock') + '" aria-hidden="true"></i>' +
    '<span class="dir-block-title"></span><span class="dir-block-sub"></span>';
  head.querySelector('.dir-block-title').textContent = title;
  head.querySelector('.dir-block-sub').textContent = sub;
  el.appendChild(head);
  if (body !== null) {
    const pre = document.createElement('pre');
    pre.className = 'dir-block-body scroll-slim';
    pre.textContent = body;
    el.appendChild(pre);
  }
  return el;
}
/** The fixed parts of the prompt, from the scaffold of the stored study, with placeholders for what every rewrite fills in. */
function dirFixedBlocks() {
  const system = String((CFG.scaffold || {}).system || '').trim();
  const user = String((CFG.scaffold || {}).user || '')
    .replace(/now = \d+s since the conversation started, (\d+s remain on the timer|no session timer)/, (m, t) => 'now = {seconds since the conversation started}, ' + (t.startsWith('no') ? t : '{seconds left on the timer}'))
    .replace('(nothing yet)', '{every message of the chat so far, each with its pause}')
    .replace('(no script)', '{the sample conversation from card ' + $('scriptNum').textContent + ', each message with its pause}')
    .replace('(nothing was planned)', '{the messages that were still planned when the participant wrote}')
    .replace(/just wrote:\n""/, 'just wrote:\n"{the message the participant just sent}"');
  const blocks = [];
  user.split(/\n\n(?=#{2,3} )/).forEach((part) => {
    const nl = part.indexOf('\n');
    const heading = (nl < 0 ? part : part.slice(0, nl)).replace(/^#+\s*/, '').replace(/:$/, '');
    let body = nl < 0 ? '' : part.slice(nl + 1).trim();
    // the heading without the notation for the model ("(+Ns = seconds after the previous message)");
    // the opening heading carries the clock, which becomes the body of that block
    let title = heading.replace(/\s*\([^)]*\)\s*$/, '');
    const clock = heading.match(/^Conversation \((now = .*)\)$/);
    if (clock) { title = 'The clock'; body = clock[1]; }
    blocks.push({ title, body });
  });
  return { system, user: blocks };
}
function renderDirFull() {
  const host = $('dirMapRows');
  host.innerHTML = '';
  DIR.order.forEach((key, i) => {
    const card = DIR_CARD_OF[key];
    const text = dirKeyText(key);
    const empty = text.trim() === '';
    const sub = [key === 'team' ? 'for all actors' : (DIR_KEYS.filter((k) => DIR_CARD_OF[k] === card).length > 1 ? DIR_CARD_TITLES[card] : ''), empty ? 'empty, skipped' : ''].filter(Boolean).join(', ');
    const el = dirBlock('is-move k-' + key + (empty ? ' is-empty' : ''), DIR_MAP_LABELS[key], sub, empty ? null : text);
    el.dataset.key = key;
    el.draggable = dirReordering;
    const tools = document.createElement('span');
    tools.className = 'dir-block-tools';
    tools.innerHTML =
      '<button class="dir-move" type="button" data-dir="-1" aria-label="Move ' + DIR_TITLES[key] + ' up"' + (i === 0 ? ' disabled' : '') + '><i class="fas fa-chevron-up"></i></button>' +
      '<button class="dir-move" type="button" data-dir="1" aria-label="Move ' + DIR_TITLES[key] + ' down"' + (i === DIR.order.length - 1 ? ' disabled' : '') + '><i class="fas fa-chevron-down"></i></button>';
    el.querySelector('.dir-block-head').appendChild(tools);
    host.appendChild(el);
  });
  const fixed = dirFixedBlocks();
  const sys = $('dirFixedSystem');
  sys.innerHTML = '';
  const nl = fixed.system.indexOf('\n');
  sys.appendChild(dirBlock('is-fixed', fixed.system.slice(0, nl).replace(/^#+\s*/, '').replace(/\s*\(.*\)\s*$/, ''), '', fixed.system.slice(nl + 1)));
  const usr = $('dirFixedUser');
  usr.innerHTML = '';
  fixed.user.forEach((b) => usr.appendChild(dirBlock('is-fixed', b.title, '', b.body === '' ? null : b.body)));
  const isDefault = DIR.order.join() === DIR_KEYS.join();
  $('dirResetOrder').classList.toggle('hidden', isDefault || !dirReordering);
  $('dirOrderNote').textContent = dirReordering ? 'Drag a colored block, or use the arrows. Grey blocks are added by the software and cannot move.' : '';
  // Long blocks are cut after a few lines, with "Show more" under them
  document.querySelectorAll('#dirFull .dir-block-body').forEach((body) => {
    if (body.scrollHeight <= body.clientHeight + 2) return;
    body.classList.add('is-clamped');
    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'link-btn dir-block-more';
    more.textContent = 'Show more';
    more.addEventListener('click', () => {
      const open = body.classList.toggle('is-open');
      more.textContent = open ? 'Show less' : 'Show more';
    });
    body.insertAdjacentElement('afterend', more);
  });
}
function dirMove(key, delta) {
  const i = DIR.order.indexOf(key);
  const j = i + delta;
  if (i < 0 || j < 0 || j >= DIR.order.length) return;
  dirPlace(key, DIR.order[j], delta > 0 ? 'after' : 'before');
}
/** Put a box before or after another one in the prompt. */
function dirPlace(key, target, where) {
  if (key === target) return;
  const order = DIR.order.filter((k) => k !== key);
  const at = order.indexOf(target) + (where === 'after' ? 1 : 0);
  order.splice(at, 0, key);
  DIR.order = order;
  renderDirFull();
  dirRemember();
  dirChanged();
}
function setDirReordering(on) {
  dirReordering = on;
  $('dirFull').classList.toggle('reordering', on);
  $('dirMapBtn').setAttribute('aria-pressed', on ? 'true' : 'false');
  $('dirMapBtn').innerHTML = on ? '<i class="fas fa-check"></i> Done' : '<i class="fas fa-sort"></i> Reorder';
  if (!$('dirFull').classList.contains('hidden')) renderDirFull();
}
$('dirMapBtn').addEventListener('click', () => setDirReordering(!dirReordering));
$('dirResetOrder').addEventListener('click', () => {
  DIR.order = DIR_KEYS.slice();
  renderDirFull();
  dirRemember();
  dirChanged();
});
$('dirMapRows').addEventListener('click', (e) => {
  const btn = e.target.closest('.dir-move');
  if (btn) dirMove(btn.closest('.dir-block').dataset.key, Number(btn.dataset.dir));
});
(function dirMapDragAndDrop() {
  const host = $('dirMapRows');
  let dragKey = null;
  const rows = () => host.querySelectorAll('.dir-block');
  const clear = () => rows().forEach((r) => r.classList.remove('drop-before', 'drop-after', 'dragging'));
  host.addEventListener('dragstart', (e) => {
    const row = e.target.closest('.dir-block');
    if (!row || !dirReordering) { e.preventDefault(); return; }
    dragKey = row.dataset.key;
    row.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', dragKey); } catch (_) { /* older browsers */ }
  });
  host.addEventListener('dragover', (e) => {
    const row = e.target.closest('.dir-block');
    if (!row || !dragKey || dragKey === row.dataset.key) return;
    e.preventDefault();
    const r = row.getBoundingClientRect();
    const after = e.clientY > r.top + r.height / 2;
    row.classList.toggle('drop-after', after);
    row.classList.toggle('drop-before', !after);
  });
  host.addEventListener('dragleave', (e) => { const row = e.target.closest('.dir-block'); if (row) row.classList.remove('drop-before', 'drop-after'); });
  host.addEventListener('drop', (e) => {
    const row = e.target.closest('.dir-block');
    if (!row || !dragKey || dragKey === row.dataset.key) return;
    e.preventDefault();
    const after = row.classList.contains('drop-after');
    const key = dragKey;
    dragKey = null;
    clear();
    dirPlace(key, row.dataset.key, after ? 'after' : 'before');
  });
  host.addEventListener('dragend', () => { dragKey = null; clear(); });
})();

document.querySelectorAll('[data-scroll]').forEach((btn) => {
  btn.addEventListener('click', () => { const el = $(btn.dataset.scroll); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
});

$('undoDirector').addEventListener('click', () => {
  const prev = directorUndo.undo();
  if (prev !== null) { dirApplyState(prev); dirChanged(); }
});

// ---- The section text boxes as formatted-text editors -----------------------------
// The stored text stays plain. Every line of it is one paragraph of the editor
// (plainTextToRichText), and every paragraph one line again (richToLines);
// bold and italic made in the editor are stored as **text** and _text_, list
// items as "- " and "1. " lines. The editor shows the text in its own way (it
// trims lines, collapses spaces), so an edit replaces only the lines that
// changed: all other lines keep their exact stored text, indentation and tabs
// included (dirRichMerge).
const DIR_RICH = true;
const dirEditors = {};
const dirRichBase = {}; // per box: { lines: the stored lines, norm: the same lines as the editor reads them }
/** The editor's text as plain lines, one per paragraph or list item. */
function richToLines(html) {
  const doc = new DOMParser().parseFromString('<div>' + (html || '') + '</div>', 'text/html');
  const lines = [];
  const inline = (node) => {
    let out = '';
    node.childNodes.forEach((n) => {
      if (n.nodeType === 3) { out += n.nodeValue; return; }
      const tag = n.nodeName.toLowerCase();
      if (tag === 'br') { out += '\n'; return; }
      const inner = inline(n);
      if (tag === 'strong' || tag === 'b') out += inner.trim() ? '**' + inner + '**' : inner;
      else if (tag === 'em' || tag === 'i') out += inner.trim() ? '_' + inner + '_' : inner;
      else out += inner;
    });
    return out;
  };
  // A paragraph is one line; a line break inside it (Shift+Enter) starts another,
  // except the one the browser keeps at the end of a paragraph.
  const block = (n, prefix) => {
    const text = inline(n).replace(/\n$/, '');
    text.split('\n').forEach((l) => lines.push(prefix + l.replace(/[ \t]+$/, '')));
  };
  const walk = (node, prefix) => {
    node.childNodes.forEach((n) => {
      if (n.nodeType !== 1) { const t = inline({ childNodes: [n] }); if (t.trim()) lines.push(prefix + t.trim()); return; }
      const tag = n.nodeName.toLowerCase();
      if (tag === 'ul' || tag === 'ol') {
        let i = 0;
        n.childNodes.forEach((li) => {
          if (li.nodeName.toLowerCase() !== 'li') return;
          i++;
          const own = [];
          const nested = [];
          li.childNodes.forEach((c) => { (c.nodeType === 1 && /^(ul|ol)$/i.test(c.nodeName) ? nested : own).push(c); });
          lines.push(prefix + (tag === 'ol' ? i + '. ' : '- ') + inline({ childNodes: own }).replace(/\n/g, ' ').trim());
          nested.forEach((sub) => walk({ childNodes: [sub] }, prefix + '  '));
        });
      } else {
        block(n, prefix);
      }
    });
  };
  walk(doc.body.firstChild, '');
  return lines;
}
/** The stored text after an edit: the changed lines from the editor, all others as they were stored. */
function dirRichMerge(key, edited) {
  const base = dirRichBase[key];
  if (!base || base.lines.length !== base.norm.length) return edited.join('\n'); // no line-by-line match: as the editor reads it
  const { lines, norm } = base;
  let head = 0;
  while (head < norm.length && head < edited.length && norm[head] === edited[head]) head++;
  let tail = 0;
  while (tail < norm.length - head && tail < edited.length - head && norm[norm.length - 1 - tail] === edited[edited.length - 1 - tail]) tail++;
  const middle = [];
  for (let i = head; i < edited.length - tail; i++) {
    // Without lines added or removed, an unchanged line in between keeps its stored text too.
    middle.push(norm.length === edited.length && norm[i] === edited[i] ? lines[i] : edited[i]);
  }
  return lines.slice(0, head).concat(middle, lines.slice(lines.length - tail)).join('\n');
}
/** Remember what the editor of a box shows for which stored text. */
function dirRichRemember(key, text) {
  dirRichBase[key] = { lines: String(text).split('\n'), norm: richToLines(dirEditors[key].getHTML()) };
}
function dirEditorSet(key, text) {
  const ed = dirEditors[key];
  if (!ed || (dirRichBase[key] && dirRichBase[key].lines.join('\n') === text)) return;
  ed.setHTML(plainTextToRichText(text));
  dirRichRemember(key, text);
  dirMarkHeadings(key);
}
/**
 * Heading-like lines are shown bold in the editor: a short line that opens a
 * paragraph, starts with a capital letter and does not end like a sentence
 * ("Writing Style", "MEETING PURPOSE AND DURATION", "General climate:").
 * The class lives in the editor only; the stored text stays plain.
 */
function dirMarkHeadings(key) {
  const ed = dirEditors[key];
  if (!ed) return;
  const ps = Array.from(ed.element.querySelectorAll(':scope > p'));
  const textOf = (p) => (p ? p.textContent.trim() : '');
  ps.forEach((p, i) => {
    const t = textOf(p);
    const opens = i === 0 || textOf(ps[i - 1]) === '';
    const followed = i + 1 < ps.length && textOf(ps[i + 1]) !== '';
    const body = t.replace(/:$/, '');
    const head = t !== '' && t.length <= 70 && opens && followed && /^[A-Z=#]/.test(t) && !/[.,;!?]$/.test(body);
    p.classList.toggle('rt-h', head);
  });
}
if (DIR_RICH) {
  DIR_KEYS.forEach((key) => {
    const ta = dirTextarea(key);
    if (!ta) return; // bots has no box of its own
    const host = document.createElement('div');
    host.className = 'dir-rich';
    ta.insertAdjacentElement('afterend', host);
    ta.classList.add('hidden');
    dirEditors[key] = createRichText(host, {
      value: plainTextToRichText(DIR.text[key]),
      label: DIR_TITLES[key],
      placeholder: ta.placeholder,
      onChange: () => {
        dirMarkHeadings(key);
        const edited = richToLines(dirEditors[key].getHTML());
        const text = dirRichMerge(key, edited);
        dirRichBase[key] = { lines: text.split('\n'), norm: edited };
        if (text === DIR.text[key]) return;
        DIR.text[key] = text;
        ta.value = text;
        syncDirPills(key);
        directorUndo.snapshot(dirMarkerText());
        dirSectionSnapshot(key);
        refreshDirPreviews();
        dirChanged();
      },
    });
    dirRichRemember(key, DIR.text[key]);
    dirMarkHeadings(key);
  });
}
/** Re-measure the text boxes (needed when Step 3 becomes visible: hidden boxes measure as empty). */
function dirRegrow() {
  document.querySelectorAll('#dirSections .dir-ta').forEach((ta) => autoGrow(ta, 3, 14));
  document.querySelectorAll('#teamRows .t-ins').forEach((field) => { dirAlignActorField(field); autoGrow(field.querySelector('textarea'), 1, 5); });
  fitMessages();
}
directorUndo.init(dirMarkerText());
Object.keys(DIR_CARDS).forEach((card) => {
  const btn = dirCardEl(card).querySelector('.dir-undo');
  dirUndos[card] = createUndo(btn);
  dirUndos[card].init(dirCardState(card));
  btn.addEventListener('click', () => {
    const prev = dirUndos[card].undo();
    if (prev !== null) { dirApplyCardState(card, prev); dirChanged(); }
  });
});
let dirRegrowTimer = null;
window.addEventListener('resize', () => { clearTimeout(dirRegrowTimer); dirRegrowTimer = setTimeout(dirRegrow, 150); });


// =============================================================================
// Step 3 · AI Assistant (settings-assistants.js)
// =============================================================================
// The assistant edits the boxes one at a time ("targets": situation, team,
// actor:bot1 …, manip, goal, rewrite, tech, script). Each box it writes is
// outlined and scrolled into view; a finished box goes through the same undo
// stacks as typing does.
const ASSIST_TITLES = { situation: 'Situation', team: 'AI actors', manip: 'Dynamics', goal: 'Purpose', rewrite: 'Rewrite rules', tech: 'Output format', script: 'Sample conversation' };
const assistActor = (target) => (target.startsWith('actor:') ? target.slice(6) : null);
/** The element of a target: the box as shown (the formatted-text editor or the actor's text box), or the sample conversation card. */
function assistTargetEl(target) {
  if (target === 'script') return $('scriptCard');
  const id = assistActor(target);
  if (id) return $('dirActor_' + id);
  const ta = dirTextarea(target);
  return ta && dirEditors[target] ? ta.nextElementSibling : ta;
}
function assistRemember(target) {
  dirSectionRemember(assistActor(target) ? 'team' : target);
  directorUndo.push(dirMarkerText());
  dirChanged();
}
const assistants = createAssistants({
  projectId: PROJECT_ID,
  project: PROJECT,
  server: SERVER,
  catalog: CATALOG,
  isDraft: () => !!draft,
  team: () => TEAM,
  isTeammate: (id) => bots().some((m) => m.id === id),
  studyContext: () => $('studyContext').value,
  participantInfo: () => ($('showInstructions') && !$('showInstructions').checked ? '' : $('participantInstructions').value),
  setDirty,
  openModal,
  closeModal,
  /** A new AI actor from the assistant's plan ({ id, name, color }); false when the id is taken or the team is full. */
  addActor(m) {
    if (teamMember(m.id) || bots().length >= CFG.teamMax || !/^bot(10|[1-9])$/.test(m.id)) return false;
    const name = String(m.name || '').trim().slice(0, CFG.nameMax);
    if (!name || TEAM.some((t) => t.name.trim().toLowerCase() === name.toLowerCase())) return false;
    delete DIR.actors[m.id]; // the run writes the new actor's text itself
    TEAM.push({ id: m.id, name, color: /^#[0-9a-f]{6}$/i.test(m.color) ? m.color : CFG.palette[bots().length % CFG.palette.length][1] });
    teamChanged(false);
    return true;
  },
  script: {
    /** The current sample conversation as JSON (the visual editor is flushed first). */
    flush() { if (mode === 'visual') $('scriptJson').value = jsonFromRows(); return $('scriptJson').value; },
    validate: () => parseScriptJson(),
    undo: scriptUndo,
    // While the assistant streams a new conversation, it appears row by row,
    // each message under its segment's header.
    live: {
      begin() {
        $('scriptMain').classList.add('streaming');
        $('scriptRows').innerHTML = '';
        $('scriptJson').value = '';
        liveSegments = [];
        liveShown = 0;
        updateStats();
      },
      /** The new conversation's segments (written before its messages); segment 1's header shows right away. */
      segments(list) {
        const segs = (Array.isArray(list) ? list : []).map((s) => ({ start: Number(s && s.start) || 0, prompt: String((s && s.prompt) || '') }));
        liveSegments = segs.length > 1 && !segmentListProblem(segs) ? segs : [];
        if (liveSegments.length && mode === 'visual') showLiveSegmentsUpTo(1);
      },
      append(message, prevEnd, allMessages) {
        $('scriptJson').value = JSON.stringify(liveSegments.length ? { segments: liveSegments, messages: allMessages } : { messages: allMessages }, null, 2);
        let end = prevEnd;
        if (mode === 'visual') {
          // A message of a later segment: that segment's header first, and the pause counts from its start.
          const t = message.is_empty ? (message.typing_start || 0) : (message.send_at || 0);
          const stated = Number(message.segment);
          const seg = liveSegments.length
            ? (Number.isInteger(stated) && liveSegments[stated - 1] && liveSegments[stated - 1].start <= t
              ? stated : liveSegments.reduce((n, s, i) => (s.start <= t ? i + 1 : n), 1))
            : 0;
          if (seg > liveShown) {
            showLiveSegmentsUpTo(seg);
            prevEnd = liveSegments[seg - 1].start;
          }
          const placed = rowFromMessageAt(message, prevEnd); // the same rows as after a reload
          end = placed.end;
          const added = $('scriptRows').appendChild(buildRow(placed.item));
          fitMessages(added.querySelectorAll('textarea.content'));
          renumber();
          refreshSegmentsView();
          updateStats();
          return end;
        }
        updateStats();
        return message.is_empty ? (message.typing_end || prevEnd) : (message.send_at || end);
      },
      finish(json) {
        $('scriptMain').classList.remove('streaming');
        $('scriptJson').value = json;
        if (mode === 'visual') renderRowsFromJson(true);
        updateStats();
      },
      restore(json, started) {
        $('scriptMain').classList.remove('streaming');
        if (!started) return;
        $('scriptJson').value = json;
        if (mode === 'visual') renderRowsFromJson(true);
        updateStats();
      },
    },
  },
  fields: {
    /** Everything the assistant reads: the texts of the boxes and the sample conversation. */
    state() {
      const boxes = {};
      ['situation', 'team', 'manip', 'goal', 'rewrite', 'tech'].forEach((k) => { boxes[k] = DIR.text[k]; });
      return { boxes, actors: Object.assign({}, DIR.actors), script: currentScriptJson() };
    },
    get(target) {
      const id = assistActor(target);
      return id ? dirActorText(id) : (DIR.text[target] || '');
    },
    /** A finished box: stored in DIR, shown, and remembered for undo. */
    set(target, text) {
      const id = assistActor(target);
      if (id) {
        if (String(text).trim() === '') delete DIR.actors[id]; else DIR.actors[id] = text;
        const ta = $('dirActor_' + id);
        if (ta) { ta.value = dirActorText(id); autoGrow(ta, 1, 5); }
        refreshDirPreviews();
      } else {
        DIR.text[target] = text;
        renderDirSections();
      }
      assistRemember(target);
    },
    title(target) {
      const id = assistActor(target);
      if (!id) return ASSIST_TITLES[target] || target;
      const m = bots().find((b) => b.id === id);
      return 'Only ' + (m ? displayName(m) : id);
    },
    /** Outline the box the assistant works on and bring the box into view (always when asked by a click). */
    focus(target, byClick) {
      const field = target === 'script' ? null : (assistActor(target) ? null : dirFieldEl(target));
      if (field && field.dataset.fold && !field.classList.contains('is-open')) field.querySelector('.dir-fold').click();
      const el = assistTargetEl(target);
      if (!el) return;
      if (!byClick) el.classList.add('ai-writing');
      const r = el.getBoundingClientRect();
      if (byClick || r.top < 70 || r.top > window.innerHeight * 0.6) el.scrollIntoView({ behavior: 'smooth', block: target === 'script' ? 'start' : 'center' });
      if (byClick) { el.classList.add('ai-flash'); setTimeout(() => el.classList.remove('ai-flash'), 1200); }
    },
    blur(target) {
      const el = assistTargetEl(target);
      if (el) el.classList.remove('ai-writing');
    },
    live: {
      begin() {},
      /** The text so far, while the model writes the box (no undo step yet). */
      write(target, text) {
        if (target === 'script') return;
        const id = assistActor(target);
        const ta = id ? $('dirActor_' + id) : dirTextarea(target);
        if (!ta) return;
        ta.value = text;
        if (!id && dirEditors[target]) dirEditorSet(target, text);
        else autoGrow(ta, id ? 1 : 3, id ? 5 : 14);
      },
    },
    /** The team, every box and the sample conversation, for Undo of a whole run. */
    snapshot: () => ({ team: JSON.stringify(TEAM), dir: dirMarkerText(), script: currentScriptJson() }),
    /**
     * Undo of a run: snap is the state before the run, after the state at its
     * end. Only what the run changed goes back, so edits made since then in
     * other places stay. Without after, everything goes back.
     */
    restore(snap, after) {
      // AI actors the run added leave again, with their texts; renames and pictures stay.
      if (!after || snap.team !== after.team) {
        const had = JSON.parse(snap.team).map((m) => m.id);
        const added = after ? JSON.parse(after.team).map((m) => m.id).filter((id) => !had.includes(id)) : null;
        TEAM = added ? TEAM.filter((m) => !added.includes(m.id)) : JSON.parse(snap.team);
        Object.keys(DIR.actors).forEach((id) => { if (!teamMember(id)) delete DIR.actors[id]; });
        teamChanged(false);
      }
      // The boxes the run changed get their text from before the run.
      const was = dirParseMarkerText(snap.dir, DIR);
      const ran = after ? dirParseMarkerText(after.dir, DIR) : null;
      const next = { order: DIR.order.slice(), text: Object.assign({}, DIR.text), actors: Object.assign({}, DIR.actors) };
      Object.keys(was.text).forEach((k) => {
        if (!ran || was.text[k] !== ran.text[k]) next.text[k] = was.text[k];
      });
      new Set(Object.keys(was.actors).concat(ran ? Object.keys(ran.actors) : [])).forEach((id) => {
        if (!teamMember(id) || (ran && (was.actors[id] || '') === (ran.actors[id] || ''))) return;
        if (was.actors[id]) next.actors[id] = was.actors[id];
        else delete next.actors[id];
      });
      DIR = dirNormalize(next);
      renderDirSections();
      renderTeamRows();
      Object.keys(DIR_CARDS).forEach((card) => dirCardRemember(card));
      directorUndo.push(dirMarkerText());
      dirChanged();
      if ((!after || snap.script !== after.script) && snap.script !== currentScriptJson()) {
        $('scriptJson').value = snap.script;
        if (mode === 'visual') renderRowsFromJson(true);
        updateStats();
        scriptUndo.push(snap.script);
      }
    },
  },
});

// =============================================================================
// Step 4 · Costs: the estimate for the whole study and what was spent so far
// =============================================================================
// The card starts small: two numbers to enter (messages per participant and
// number of participants), the estimated cost of the study and what was spent
// so far. "Show more" opens the calculation behind both.
const ESTIMATE_KEY = 'aiteamstudio.estimate.' + PROJECT_ID;
const ESTIMATE_LIMITS = { messages: [1, 200], participants: [1, 100000] };
// The numbers the estimate uses. Messages start at what participants of this
// study wrote on average so far (or 8), participants at 100.
const estimateInput = { messages: CFG.typical.value, participants: 100 };
// A number the researcher entered is kept in this browser per study, so it
// is still there on the next visit. A field that was never changed keeps
// following its default.
let estimateKept = {};
try {
  const stored = JSON.parse(localStorage.getItem(ESTIMATE_KEY) || 'null');
  if (stored && typeof stored === 'object' && !Array.isArray(stored)) estimateKept = stored;
} catch (_) { /* the defaults are fine */ }
Object.keys(estimateInput).forEach((key) => {
  const v = parseInt(estimateKept[key], 10);
  if (v >= ESTIMATE_LIMITS[key][0] && v <= ESTIMATE_LIMITS[key][1]) estimateInput[key] = v;
  else delete estimateKept[key];
});

let estimateTimer = null;
const est = {}; // cells of the estimate table, built once

/** One of the two number fields: every usable number counts at once. */
function bindEstimateField(id, key) {
  const input = $(id);
  const [min, max] = ESTIMATE_LIMITS[key];
  const take = (v) => {
    estimateInput[key] = v;
    estimateKept[key] = v;
    try { localStorage.setItem(ESTIMATE_KEY, JSON.stringify(estimateKept)); } catch (_) { /* per-browser convenience only */ }
    scheduleEstimate(150);
  };
  input.value = estimateInput[key];
  input.addEventListener('input', () => {
    const v = parseInt(input.value, 10);
    const usable = v >= min && v <= max;
    input.classList.toggle('invalid', !usable);
    if (usable) take(v);
  });
  // Leaving the field: a number outside the range becomes the nearest one that
  // is allowed, anything else the last number that counted.
  input.addEventListener('blur', () => {
    const v = parseInt(input.value, 10);
    if (Number.isFinite(v) && (v < min || v > max)) take(Math.min(max, Math.max(min, v)));
    input.value = estimateInput[key];
    input.classList.remove('invalid');
  });
}

/** Once at the start: the table of the calculation, the two number fields, "Show more". */
function setupCostsCard() {
  const table = AIT.el('table', 'cost-table');
  table.innerHTML =
    '<thead><tr><th></th><th>Input tokens</th><th>Output tokens</th><th>Estimated cost</th></tr></thead>' +
    '<tbody>' +
    '<tr><td>One conductor rewrite early in the conversation<span class="sub">after the participant’s first message, about a tenth into the sample conversation</span></td>' +
    '<td data-cell="earlyIn"></td><td data-cell="earlyOut"></td><td data-cell="earlyCost"></td></tr>' +
    '<tr><td>One conductor rewrite later in the conversation<span class="sub">after a message about three quarters into the sample conversation</span></td>' +
    '<td data-cell="lateIn"></td><td data-cell="lateOut"></td><td data-cell="lateCost"></td></tr>' +
    '<tr><td>One participant<span class="sub" data-cell="convSub"></span></td>' +
    '<td data-cell="convIn"></td><td data-cell="convOut"></td><td data-cell="convCost"></td></tr>' +
    '<tr class="total"><td>The whole study<span class="sub" data-cell="studySub"></span></td>' +
    '<td data-cell="studyIn"></td><td data-cell="studyOut"></td><td data-cell="studyCost"></td></tr>' +
    '</tbody>';
  table.querySelectorAll('[data-cell]').forEach((c) => { est[c.dataset.cell] = c; });
  $('estimateBody').appendChild(table);
  est.table = table;

  bindEstimateField('estMessages', 'messages');
  bindEstimateField('estParticipants', 'participants');
  if (CFG.typical.conversations >= 3) {
    $('estMessagesHint').textContent = 'Your ' + CFG.typical.conversations + ' recorded conversations had ' + CFG.typical.value +
      ' message' + (CFG.typical.value === 1 ? '' : 's') + ' per participant on average.';
  }

  $('costMoreBtn').addEventListener('click', () => {
    const open = $('costMore').classList.toggle('hidden') === false;
    $('costMoreBtn').classList.toggle('open', open);
    $('costMoreBtn').setAttribute('aria-expanded', String(open));
    $('costMoreLabel').textContent = open ? 'Show less' : 'Show more';
  });
}

/** Messages and characters of the sample conversation as it is now (for the source tiles). */
function scriptTextStats() {
  try {
    const raw = currentScriptJson().trim();
    if (!raw) return { messages: 0, chars: 0 };
    const parsed = JSON.parse(raw);
    const msgs = (Array.isArray(parsed) ? parsed : (parsed.messages || [])).filter((m) => m && !m.is_empty);
    return { messages: msgs.length, chars: msgs.reduce((n, m) => n + String(m.content || '').length, 0) };
  } catch (_) {
    return { messages: 0, chars: 0 };
  }
}

/** Three colored tiles: what the estimate is based on. */
function renderSourceTiles(instructionChars, stats) {
  const host = $('sourceTiles');
  host.innerHTML = '';
  const tile = (cls, icon, num, caption, empty) => {
    const t = AIT.el('div', 'tile ' + (empty ? 'empty' : cls));
    t.innerHTML = '<i class="' + icon + '"></i>';
    const text = AIT.el('span');
    text.append(AIT.el('span', 't-num', num.toLocaleString('en-US')), AIT.el('span', 't-cap', caption));
    t.appendChild(text);
    host.appendChild(t);
  };
  tile('t-violet', 'fas fa-scroll', instructionChars, 'characters in the conductor instructions', !instructionChars);
  tile('t-blue', 'fas fa-comments', stats.messages, 'messages in the sample conversation', !stats.messages);
  tile('t-teal', 'fas fa-font', stats.chars, 'characters in the sample conversation', !stats.chars);
}

function scheduleEstimate(delay = 250) {
  clearTimeout(estimateTimer);
  estimateTimer = setTimeout(renderEstimate, delay);
}

function renderEstimate() {
  const provider = currentProvider;
  const model = getModelValue();
  const family = tokenFamily(provider, model);
  const price = currentPrice();
  const instructions = assembleDirector().trim();
  const stats = scriptTextStats();
  const { messages, participants } = estimateInput;
  $('costModelName').textContent = model || 'no model chosen yet';
  renderSourceTiles(instructions.length, stats);

  // Both texts come from Step 3; without them there is nothing to estimate yet.
  // Every segment start is one more director call per conversation.
  const segmentCalls = Math.max(0, scriptSummary().segments - 1);
  const result = instructions && stats.messages >= 2 ? estimateRewrites({
    roles: teamRolesMap(),
    script: currentScriptJson(),
    instructions,
    scaffold: CFG.scaffold,
    family,
    messages: messages + segmentCalls,
  }) : null;

  // Without an estimate the tile "per participant" is hidden and the tile of
  // the study, twice as wide, asks for the texts.
  $('costPerStat').classList.toggle('hidden', !result);
  $('costEstimateStat').classList.toggle('is-missing', !result);
  $('costGoStep3').classList.toggle('hidden', !!result);
  est.table.classList.toggle('hidden', !result);
  const thinking = !FAST_EFFORTS.includes($('directorEffortSelect').value);
  $('estimateThinkingNote').classList.toggle('hidden', !result || !(thinking || alwaysThinks(provider, model)));
  $('estimateThinkingText').textContent = (thinking ? 'Thinking is switched on in Step 2.' : 'This model always thinks a little before it writes.') +
    ' Thinking tokens are billed as output tokens and are not included here, so the real costs can be considerably higher.';
  const estimatesDiffer = 'The real costs can differ, for example when participants write more or fewer messages than you entered here.';
  $('costNoteText').textContent = 'These amounts are estimates. ' + estimatesDiffer;
  if (!result) {
    $('costStudyLabel').textContent = 'Estimated costs';
    $('costStudy').textContent = '';
    $('costStudySub').textContent = (!instructions && stats.messages < 2
      ? 'Write the conductor instructions and the sample conversation in Step 3 first.'
      : !instructions ? 'Write the conductor instructions in Step 3 first.'
        : stats.messages === 1 ? 'The sample conversation needs at least two messages. Add a second message in Step 3 first.'
          : stats.messages ? 'The sample conversation cannot be read yet. Please check the sample conversation in Step 3.'
            : 'Write the sample conversation in Step 3 first.') + ' The estimate appears here when Step 3 is complete.';
    $('estimateFoot').textContent = '';
    return;
  }

  // The first rewrite of a conversation fills the provider's prompt cache; later ones read from it.
  const cost = (r, cached) => (price ? formatUsd(rewriteCost(price, provider, r, cached)) : '–');
  const convCost = price ? conversationCost(price, provider, result.conversation.items) : null;
  const studyIn = result.conversation.input * participants;
  const studyOut = result.conversation.output * participants;
  est.earlyIn.textContent = formatTokens(result.early.input);
  est.earlyOut.textContent = formatTokens(result.early.output);
  est.earlyCost.textContent = cost(result.early, false);
  est.lateIn.textContent = formatTokens(result.late.input);
  est.lateOut.textContent = formatTokens(result.late.output);
  est.lateCost.textContent = cost(result.late, true);
  est.convIn.textContent = formatTokens(result.conversation.input);
  est.convOut.textContent = formatTokens(result.conversation.output);
  est.convCost.textContent = price ? formatUsd(convCost) : '–';
  est.convSub.textContent = countText(messages, 'message') +
    (segmentCalls ? ', plus ' + countText(segmentCalls, 'conductor call') + ' at the start of segments' : '');
  est.studyIn.textContent = formatTokens(studyIn);
  est.studyOut.textContent = formatTokens(studyOut);
  est.studyCost.textContent = price ? formatUsdShort(convCost * participants) : '–';
  est.studySub.textContent = countText(participants, 'participant');

  let foot;
  if (provider === 'custom') {
    foot = 'Your gateway contract sets the price, so only tokens are shown.';
  } else if (!price) {
    foot = model
      ? 'There is no list price for “' + model + '” in this tool, so only tokens are shown.'
      : 'No model is chosen in Step 2 yet, so only tokens are shown.';
  } else {
    foot = 'Prices for ' + model + ': ' + perMillion(price.input) + ' per million input tokens and ' + perMillion(price.output) +
      ' per million output tokens (list prices on ' + asOfText() + ').' +
      (provider === 'openai' ? ' The costs take OpenAI’s prompt cache into account (repeated parts of a request cost less), as the question mark at the top of this card explains.' : '');
  }
  $('estimateFoot').textContent = foot;

  // The results of the first view, per participant and for the study: money
  // when the model has a list price, otherwise tokens.
  $('costPerLabel').textContent = price ? 'Estimated cost per participant' : 'Estimated tokens per participant';
  $('costPer').textContent = price ? formatUsdShort(convCost) : formatTokens(result.conversation.input + result.conversation.output) + ' tokens';
  $('costPerSub').textContent = 'With ' + countText(messages, 'message') + ' per participant.';
  $('costStudyLabel').textContent = price ? 'Estimated cost of the study' : 'Estimated tokens of the study';
  $('costStudy').textContent = price ? formatUsdShort(convCost * participants) : formatTokens(studyIn + studyOut) + ' tokens';
  $('costStudySub').textContent = 'With ' + countText(participants, 'participant') + '.';
  if (!price) $('costNoteText').textContent = 'These amounts are estimates. ' + foot + ' ' + estimatesDiffer;
}

// =============================================================================
// Step 4 · Readiness check, participant link, what was spent so far
// =============================================================================
function readinessItems() {
  const items = [];
  if (paused) {
    items.push({ kind: 'warn', text: 'The study is paused.', sub: 'New participants who open the link see a message that the study is not active.', action: ['Continue study', () => setPaused(false)] });
  }
  const s = scriptSummary();
  const instructions = assembleDirector().trim();
  const problem = teamProblem();
  const provider = PROVIDER_LABELS[currentProvider];

  items.push(draft
    ? { kind: 'warn', text: 'This copy is not saved yet.', sub: 'Saving creates the study with a new ID and password. The participant link works after the first save.', action: ['Save now', () => save()] }
    : dirty
      ? { kind: 'warn', text: 'You have unsaved changes.', sub: 'Until you save the changes, participants get the previous version.', action: ['Save now', () => save()] }
      : { kind: 'ok', text: 'All changes are saved.' });

  // The key: the last test is stored with the study, and one that is older than a week turns yellow.
  const keyState = keyTestState();
  if (!effectiveKey()) {
    items.push({ kind: 'bad', text: 'There is no API key.', sub: 'Add a key for ' + provider + ' in Step 2, so that the teammates can react to the participant.', action: ['Go to Step 2', () => showStep(2)] });
  } else if (keyTesting) {
    items.push({ kind: 'info', text: 'The API key is being tested.', sub: 'The test takes a few seconds.' });
  } else if (keyState === 'ok') {
    items.push({ kind: 'ok', text: 'The API key works.', sub: 'Tested ' + keyTestRun() + '.' });
  } else if (keyState === 'old') {
    items.push({ kind: 'warn', text: 'The API key was last tested ' + daysAgoText(keyTest.at) + '.', sub: 'The key worked ' + keyTestRun() + '. You can test the key again to make sure that the key, the credit and the model still work.', action: ['Test now', () => testKey()] });
  } else if (keyState === 'failed') {
    items.push({ kind: 'bad', text: 'The key test failed.', sub: (keyTest.hint ? keyTest.hint + ' ' : '') + 'Tested ' + keyTestRun() + '.', action: ['Go to Step 2', () => showStep(2)] });
  } else if (keyState === 'other') {
    items.push({ kind: 'warn', text: 'The API key has not been tested with the current settings.', sub: 'The model, the key or the connection was changed after the last test. A short test shows whether the model, the key and the connection work together.', action: ['Test now', () => testKey()] });
  } else {
    items.push({ kind: 'warn', text: 'The API key has not been tested yet.', sub: 'A key for ' + provider + ' is set. A short test shows whether the key, the credit and the model work.', action: ['Test now', () => testKey()] });
  }

  if (!s.ok) {
    items.push({ kind: 'bad', text: 'The sample conversation is not valid JSON.', sub: 'Fix the JSON in Step 3 so that the sample conversation can be saved.', action: ['Go to Step 3', () => showStep(3)] });
  } else if (!s.messages) {
    items.push({ kind: 'bad', text: 'The sample conversation is empty.', sub: 'Participants would see a notice that the chat is not ready yet. Add messages in Step 3.', action: ['Go to Step 3', () => showStep(3)] });
  } else {
    items.push({ kind: 'ok', text: 'The sample conversation has ' + countText(s.messages, 'message') + ' and lasts about ' + minutesText(s.endS) + '.' });
  }

  if (!instructions) {
    items.push({ kind: 'warn', text: 'There are no conductor instructions.', sub: 'The sample conversation will play, but the teammates will not react to what the participant writes.', action: ['Go to Step 3', () => showStep(3)] });
  } else {
    items.push({ kind: 'ok', text: 'The conductor instructions are written (' + instructions.split(/\s+/).length.toLocaleString('en-US') + ' words).' });
  }

  const segmentIssue = segmentsProblem();
  if (segmentIssue) {
    items.push({ kind: 'bad', text: 'A segment needs attention.', sub: segmentIssue, action: ['Go to Step 3', () => showStep(3)] });
  } else if (s.ok && s.segments) {
    const timerS = $('timerEnabled').checked ? Math.max(1, parseInt($('timerMinutes').value, 10) || 10) * 60 : 0;
    const late = timerS ? s.segmentStarts.filter((t) => t >= timerS) : [];
    if (!instructions) {
      items.push({ kind: 'warn', text: 'The segments have no effect without conductor instructions.', sub: 'The conductor makes the cuts between segments, so without conductor instructions the sample conversation simply plays as written.', action: ['Go to Step 3', () => showStep(3)] });
    } else if (late.length) {
      items.push({ kind: 'warn', text: 'A segment starts after the timer ends.', sub: 'The segment at ' + clockText(late[0]) + ' starts at or after the end of the timer (' + clockText(timerS) + '). The segment only starts if the participant is still in the chat.', action: ['Go to Step 3', () => showStep(3)] });
    } else {
      items.push({ kind: 'ok', text: 'The sample conversation has ' + s.segments + ' segments, starting at ' + s.segmentStarts.map(clockText).join(', ') + '.' });
    }
    if (s.cut) {
      items.push({ kind: 'warn', text: s.cut + ' message' + (s.cut === 1 ? '' : 's') + ' of the sample conversation ' + (s.cut === 1 ? 'is' : 'are') + ' cut.', sub: (s.cut === 1 ? 'The message comes' : 'The messages come') + ' after the next segment has started and ' + (s.cut === 1 ? 'is' : 'are') + ' crossed out in Step 3.', action: ['Go to Step 3', () => showStep(3)] });
    }
  }

  if (problem) {
    items.push({ kind: 'bad', text: 'The participants need attention.', sub: problem, action: ['Go to Step 3', () => showStep(3)] });
  } else {
    const h = human();
    items.push({ kind: 'ok', text: 'The chat has ' + bots().length + ' AI teammate' + (bots().length === 1 ? '' : 's') + ' and ' + displayName(h) + ' as the participant.' });
  }

  if ($('showChatInstructions').checked) {
    items.push(chatInstructions.isEmpty()
      ? { kind: 'warn', text: 'The instructions above the chat are empty.', sub: 'Showing instructions above the chat is switched on in Step 1, but there is no text yet. Participants see the chat without an instructions box.', action: ['Go to Step 1', () => showStep(1)] }
      : { kind: 'ok', text: 'Participants see instructions above the chat.' });
  }

  // The study context is optional: it is listed when there is one, and a
  // study without it is simply ready without it.
  if ($('studyContext').value.trim()) {
    items.push({ kind: 'ok', text: 'A study context is available to the AI Assistant.', sub: 'Only the AI Assistant in Step 3 reads the study context. The conductor and the participants do not see the study context.' });
  }
  return items;
}

function refreshReadiness() {
  const list = $('readyList');
  list.innerHTML = '';
  const icons = { ok: 'fas fa-check', warn: 'fas fa-exclamation', bad: 'fas fa-times', info: 'fas fa-minus' };
  readinessItems().forEach((it) => {
    const li = AIT.el('li', 'ready-item ' + it.kind);
    const icon = AIT.el('span', 'ri-icon');
    icon.innerHTML = '<i class="' + icons[it.kind] + '"></i>';
    const text = AIT.el('span', 'ri-text');
    text.appendChild(AIT.el('b', null, it.text));
    if (it.sub) text.appendChild(AIT.el('span', 'ri-sub', it.sub));
    li.append(icon, text);
    if (it.action) {
      const btn = AIT.el('button', 'btn btn-sm ri-action', it.action[0]);
      btn.type = 'button';
      btn.addEventListener('click', it.action[1]);
      li.appendChild(btn);
    }
    list.appendChild(li);
  });
}

/** An amber dot on steps that still need something. */
function refreshStepIssues() {
  const s = scriptSummary();
  const test = currentKeyTest();
  const issues = {
    2: !effectiveKey() || (test && !test.ok),
    3: !s.ok || !s.messages || !!teamProblem() || !!segmentsProblem(),
    4: paused,
  };
  document.querySelectorAll('#stepTabs button').forEach((b) => b.classList.toggle('has-issue', !!issues[b.dataset.step]));
}

// ---- Participant link -------------------------------------------------------------------
// One field shows the link for participants or, while "Test link" is switched
// on, the link for trying the chat yourself. A test link has its own fixed ID
// (TEST), so the conversation ID option is switched off and locked for that time.
// That option adds any text the researcher enters, usually a placeholder that
// the survey tool fills in (the templates under the field offer the common ones);
// without it the chat gives every conversation a random ID.
// "Embed code" shows the same link wrapped in an iframe, to paste into a survey
// question (aiTeam.php allows framing by any site); the choice of format lives
// in this browser only.
const chatLink = (suffix) => CFG.baseUrl + 'aiTeam.php?project=' + encodeURIComponent(PROJECT_ID) + suffix;
const TEST_LINK = chatLink('&conversationID=TEST');
const LINK_FORMAT_KEY = 'aiteamstudio.linkFormat';
const CONV_ID_KEY = 'aiteamstudio.convId.' + PROJECT_ID;
let pipedBeforeTest = false; // the conversation ID choice, given back when the test link is switched off
let linkFormat = (() => {
  try { return localStorage.getItem(LINK_FORMAT_KEY) === 'embed' ? 'embed' : 'link'; } catch (_) { return 'link'; }
})();

function participantLink() {
  if ($('testToggle').checked) return TEST_LINK;
  const id = $('pipedToggle').checked ? $('pipedValue').value.trim() : '';
  // Placeholders stay as typed, % and braces included (the survey tool must
  // recognize them); only spaces, # and & would break the link, so only they are encoded.
  return chatLink(id ? '&conversationID=' + id.replace(/[\s#&]/g, encodeURIComponent) : '');
}
// What each template does, under the field. Each must reach the
// survey tool exactly as written, so the texts say what has to match. Fixed texts only (innerHTML).
const CONV_ID_HINTS = {
  '${e://Field/conversationID}': 'Qualtrics fills in its embedded data field <b>conversationID</b>. Set this field in the survey flow, for example to the response ID. The name after <code>Field/</code> must match the name in the survey flow exactly, including upper and lower case.',
  '${e://Field/ResponseID}': 'Qualtrics fills in the ID of the survey response. Keep <code>ResponseID</code> exactly as written.',
  '${q://QID1/ChoiceTextEntryValue}': 'Qualtrics fills in the answer to a text entry question on an earlier page. <b>QID1</b> must match the ID of that question in your survey. The safest way: copy the placeholder from <b>Piped Text</b> in the Qualtrics editor.',
  '{{%PROLIFIC_PID%}}': 'Prolific fills in the participant\'s Prolific ID when this link is the study link in Prolific. Keep <code>{{%PROLIFIC_PID%}}</code> exactly as written.',
  '{SAVEDID}': 'LimeSurvey fills in the ID of the response. Keep <code>{SAVEDID}</code> exactly as written.',
};
function refreshConvId() {
  const value = $('pipedValue').value.trim();
  document.querySelectorAll('.id-tag').forEach((t) => {
    const on = t.dataset.value === value;
    t.classList.toggle('active', on);
    t.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
  $('pipedHint').innerHTML = Object.prototype.hasOwnProperty.call(CONV_ID_HINTS, value) ? CONV_ID_HINTS[value]
    : value ? 'This text goes into the link as written. A survey variable must match the variable in your survey tool exactly (in Qualtrics: copy the variable from <b>Piped Text</b>). If your survey tool does not fill in the variable, the chat gives a random ID.'
      : 'The conversation ID links each chat to its survey response. Click a template above, or type the variable of your survey tool into the field. If the field is empty, every conversation gets a random ID.';
}
const embedCode = (url) =>
  `<iframe src="${url}" title="Team chat" style="width:95%;height:640px;border:0;" allow="clipboard-write"></iframe>`;

function refreshLinks() {
  const test = $('testToggle').checked;
  const embed = linkFormat === 'embed';
  const link = participantLink();
  const field = $('shareField');
  field.value = embed ? embedCode(link) : link;
  field.classList.toggle('is-test', test);
  field.setAttribute('aria-label', (test ? 'Test ' : '') + (embed ? 'embed code' : 'link'));
  document.querySelectorAll('.link-format button').forEach((b) => {
    const on = b.dataset.format === linkFormat;
    b.classList.toggle('active', on);
    b.setAttribute('aria-checked', on ? 'true' : 'false');
  });
  // Fixed texts only, so innerHTML is safe here.
  $('linkHint').innerHTML = embed
    ? (test ? 'Paste this code into your survey to try the chat on the survey page yourself.'
      : 'Paste this code into your survey, and the chat appears on the survey page.')
      + ' In Qualtrics: add a <b>Text / Graphic</b> question, click its text, choose <b>Rich Content Editor</b>,'
      + ' then <b>Source</b> (<code>&lt;&gt;</code>) and paste. To change the height, edit <code>640px</code> in the code.'
    : (test ? 'Open this link to try the chat yourself. Every time the link is opened, a new test conversation starts.'
      : 'Send this link to participants or place the link in your survey. Every time the link is opened, a new conversation starts.');
  $('pipedLabel').textContent = 'Add a conversation ID to the ' + (embed ? 'embed code' : 'link');
  $('pipedFields').classList.toggle('hidden', !$('pipedToggle').checked);
  $('pipedSub').classList.toggle('hidden', test || $('pipedToggle').checked);
  $('testLinkNoteText').textContent = embed
    ? 'This code is for testing the chat yourself. Conversations opened with the code are marked as tests, so do not use the code in the survey participants take.'
    : 'This link is for testing the chat yourself. Conversations opened with the link are marked as tests, so do not send the link to participants.';
  $('testBadge').classList.toggle('hidden', !test);
  $('testLinkNote').classList.toggle('hidden', !test);
  $('openTestLink').classList.toggle('hidden', !test);
  $('pipedToggle').disabled = test;
  $('pipedOption').classList.toggle('is-off', test);
  $('pipedOffNote').classList.toggle('hidden', !test);
  fitShareField();
}
/** The box as high as its text: one line when the link fits, more when it (or the code) wraps. */
function fitShareField() {
  const field = $('shareField');
  if (!field.offsetParent) return; // on another step: measured once Step 4 shows
  field.style.height = 'auto';
  field.style.height = (field.scrollHeight + 2) + 'px';
}
window.addEventListener('resize', fitShareField);
document.querySelectorAll('.link-format button').forEach((b) => {
  b.addEventListener('click', () => {
    linkFormat = b.dataset.format;
    try { localStorage.setItem(LINK_FORMAT_KEY, linkFormat); } catch (_) {}
    refreshLinks();
  });
});
$('linkCopy').addEventListener('click', () => AIT.copyText($('shareField').value));
// One click selects the whole link or code, ready for Cmd/Ctrl+C.
$('shareField').addEventListener('focus', (e) => e.target.select());
$('testToggle').addEventListener('change', () => {
  const piped = $('pipedToggle');
  if ($('testToggle').checked) {
    pipedBeforeTest = piped.checked;
    piped.checked = false;
  } else {
    piped.checked = pipedBeforeTest;
  }
  refreshLinks();
});
$('pipedToggle').addEventListener('change', refreshLinks);
$('pipedValue').value = (() => { try { return localStorage.getItem(CONV_ID_KEY) || ''; } catch (_) { return ''; } })();
refreshConvId();
$('pipedValue').addEventListener('input', () => {
  try { localStorage.setItem(CONV_ID_KEY, $('pipedValue').value); } catch (_) {}
  refreshConvId();
  refreshLinks();
});
document.querySelectorAll('.id-tag').forEach((t) => {
  // A second click on the template in use takes it out again and empties the field.
  t.addEventListener('click', () => {
    const value = t.classList.contains('active') ? '' : t.dataset.value;
    $('pipedValue').value = value;
    try { localStorage.setItem(CONV_ID_KEY, value); } catch (_) {}
    refreshConvId();
    refreshLinks();
  });
});
// A draft copy has no stored study yet, so its test links open the example's chat.
function refreshTestLinks() {
  const link = draft ? CFG.baseUrl + 'aiTeam.php?project=' + encodeURIComponent(draft.source) + '&conversationID=TEST' : TEST_LINK;
  $('openTestLink').href = link;
  $('openTestChat').href = link;
}
refreshTestLinks();

// ---- Add collaborator (top bar) -----------------------------------------------------------
// The dialog shows the link to these settings and the study password. The
// link is clean (password sent separately) or carries the password; embedding
// is opt-in, and the preference lives in this browser only.
const LINK_MODE_KEY = 'aiteamstudio.linkMode';
function linkMode() {
  try { return localStorage.getItem(LINK_MODE_KEY) === 'embed' ? 'embed' : 'clean'; } catch (_) { return 'clean'; }
}
// The plaintext password, when this browser knows it: from the URL, the
// saved-in-browser store (unlock popup), the session handoff after opening,
// or the signed-in account's stored credential.
function knownPassword() {
  const fromUrl = new URLSearchParams(location.search).get('password');
  if (fromUrl) return fromUrl;
  try {
    return localStorage.getItem('aiteamstudio.savedPw.' + PROJECT_ID) ||
           sessionStorage.getItem('aiteamstudio.pwSession.' + PROJECT_ID) ||
           CFG.accountPw;
  } catch (_) { return CFG.accountPw; }
}
/**
 * The study password for requests that need the unlocked session, when the page
 * knows it: the endpoints use it only when the PHP session expired while the
 * page stayed open (a long edit without any request).
 */
function sessionFallback() {
  const pw = draft ? '' : knownPassword();
  return pw ? { password: pw } : {};
}
function refreshSettingsLink() {
  const lm = linkMode();
  const pw = knownPassword();
  const embedded = lm === 'embed' && !!pw; // the link carries the password
  $('embedPwToggle').checked = lm === 'embed';
  $('settingsPw').value = pw;
  $('settingsPw').placeholder = pw ? '' : 'Not known to this browser';
  $('settingsPwCopy').disabled = !pw;
  $('settingsPwHint').classList.toggle('hidden', !!pw);
  const clean = CFG.baseUrl + 'settings.php?project=' + encodeURIComponent(PROJECT_ID);
  $('settingsLink').value = embedded ? clean + '&password=' + encodeURIComponent(pw) : clean;
  let risky = true;
  let text;
  if (embedded) {
    text = 'Less safe: the password is part of the link, so everyone who sees the link has full access to the study, for example in a forwarded email, in the browser history or during a screen share. Only send the link through a channel you trust.';
  } else if (lm === 'embed') {
    text = 'This browser does not know the password, so the password cannot be added to the link. After you open the study once with the password, the link can include the password.';
  } else {
    risky = false;
    text = 'Safest option (recommended): the link contains no password. Send the password separately, for example in a different message.';
  }
  const note = $('settingsLinkNote');
  note.className = 'safety-note ' + (risky ? 'risky' : 'safe');
  note.querySelector('i').className = risky ? 'fas fa-exclamation-triangle' : 'fas fa-shield-alt';
  $('settingsLinkNoteText').textContent = text;
  // What the collaborator has to do depends on whether the link carries the password.
  $('collabHowGuest').textContent = embedded
    ? 'The link opens the study right away, because the link contains the password.'
    : 'Your collaborator opens the link and enters the study password.';
  $('collabHowAccount').textContent = embedded
    ? 'A collaborator who is signed in sees the button “Add to my account” at the top of the page. After the collaborator clicks the button, the study appears in the collaborator’s list of studies and opens without this link.'
    : 'A collaborator who is signed in enters the password once. Entering the password adds the study to the collaborator’s account, so the study appears in the collaborator’s list of studies and opens without the password.';
}
$('embedPwToggle').addEventListener('change', () => {
  try { localStorage.setItem(LINK_MODE_KEY, $('embedPwToggle').checked ? 'embed' : 'clean'); } catch (_) {}
  refreshSettingsLink();
});

// ---- What was spent so far (from the data page's API) -----------------------------------
// Every model call of the study with its tokens and its cost at list prices:
// the rewrites in the conversations and the AI Assistant of Step 3.
let usageLoadedAt = 0;
// conversations: the list of api/data.php?action=list; usage: the costs of action=usage
// (each null until its first reply arrives)
let usageData = { conversations: null, usage: null };
let listFailed = false;
let usageFailed = false;
async function loadUsage() {
  if (draft) { // nothing stored yet, so nothing recorded or spent
    usageData = { conversations: [], usage: null };
    renderUsage();
    return;
  }
  if (Date.now() - usageLoadedAt < 30000) return;
  usageLoadedAt = Date.now();
  // Both at once: the list arrives at once, the costs take a few seconds in large
  // studies. Each fills in its part of the page as soon as it arrives.
  const api = 'api/data.php?project=' + encodeURIComponent(PROJECT_ID) + '&action=';
  const list = AIT.getJSON(api + 'list').then(
    (data) => { usageData.conversations = data.conversations || []; listFailed = false; },
    () => { listFailed = true; }).then(renderUsage);
  const usage = AIT.getJSON(api + 'usage').then(
    (data) => { usageData.usage = data.usage || null; usageFailed = false; },
    () => { usageFailed = true; }).then(renderUsage);
  await Promise.all([list, usage]);
  if (listFailed || usageFailed) usageLoadedAt = 0; // try again the next time Step 4 opens
}

// The "View data" card under the link: its second line says how many conversations
// participants have had, their messages and when the last one was written. Test
// conversations are counted apart, so the main number is the real data.
function renderDataCard() {
  const box = $('dataStats');
  const convs = usageData.conversations;
  if (!convs) {
    box.textContent = listFailed ? 'The number of conversations could not be loaded.' : 'Loading the number of conversations.';
    return;
  }
  const real = convs.filter((c) => !c.is_test);
  const tests = convs.length - real.length;
  const testText = tests ? countText(tests, 'test conversation') : '';
  box.innerHTML = '';
  if (!real.length) {
    box.textContent = 'No participant conversations yet.' + (testText ? ' ' + testText.charAt(0).toUpperCase() + testText.slice(1) + ' so far.' : '');
    return;
  }
  const strong = (n, word) => {
    const b = AIT.el('b', '', n.toLocaleString('en-US'));
    const span = AIT.el('span', 'data-stat');
    span.append(b, ' ' + word + (n === 1 ? '' : 's'));
    return span;
  };
  const messages = real.reduce((sum, c) => sum + (c.human_count || 0), 0);
  const parts = [strong(real.length, 'conversation'), strong(messages, 'participant message')];
  const last = real.reduce((m, c) => (c.last_at > m ? c.last_at : m), '');
  const d = new Date(String(last).replace(' ', 'T'));
  if (!Number.isNaN(d.getTime())) {
    parts.push(AIT.el('span', 'data-stat', 'last on ' + d.toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })));
  }
  if (testText) parts.push(AIT.el('span', 'data-stat data-stat-test', 'plus ' + testText));
  box.append(...parts);
}

// The tile "Spent so far" and its table behind "Show more" (spending.js, the
// same as in the "Costs" dialog of the data page).
function renderUsage() {
  renderDataCard();
  const value = $('costSpent');
  const sub = $('costSpentSub');
  const body = $('usageBody');
  const foot = $('usageFoot');
  body.innerHTML = '';
  foot.textContent = '';
  if (draft) {
    value.textContent = '$0.00';
    sub.textContent = 'No conversations have been recorded yet.';
    body.appendChild(AIT.el('p', 'part-lead', sub.textContent));
    return;
  }
  // The costs are split by the conversations (with participants or tests), so both are needed.
  const u = usageData.conversations && usageData.usage;
  if (!u) {
    const failed = usageFailed || listFailed;
    value.textContent = failed ? '–' : '';
    if (!failed) value.appendChild(AIT.el('span', 'spinner'));
    sub.textContent = failed ? 'The amount could not be loaded. You can reload the page to try again.' : 'Loading the amount.';
    body.appendChild(AIT.el('p', 'part-lead', sub.textContent));
    return;
  }
  const convs = usageData.conversations;
  const parts = spendingParts(u, convs);

  // The headline: money, or tokens when none of the calls has a list price.
  if (!u.total.calls) {
    value.textContent = '$0.00';
    sub.textContent = convs.length
      ? countText(convs.length, 'conversation') + ' recorded so far. The AI model was not needed in ' + (convs.length === 1 ? 'the conversation' : 'these conversations') + ' yet.'
      : 'No conversations have been recorded yet.';
  } else {
    showAmount(value, amountOf(u.total));
    sub.textContent = 'For ' + (partsText(parts, 'in Step 3') || 'earlier requests') + '.' +
      (u.total.cost === null ? ' No list price is known for these requests, so tokens are shown.'
        : u.total.unpriced_calls ? ' Requests without a list price are not included.' : '');
  }

  // The details behind "Show more": one row per kind of use.
  const table = spendingTable(parts, 'AI Assistant in Step 3');
  body.appendChild(table);
  if (table.tagName !== 'TABLE') return; // nothing spent yet
  foot.append(priceBasisText(u) + ' ');
  const link = AIT.el('a', null, 'The data page shows every conversation.');
  link.href = $('dataNavLink').getAttribute('href');
  foot.appendChild(link);
}

// =============================================================================
// Account banner (signed in): add / remove without leaving the page
// =============================================================================
if ($('bannerAdd')) {
  $('bannerAdd').addEventListener('click', async () => {
    const manual = $('bannerPw').value.trim();
    const pw = manual || knownPassword();
    if (!pw) {
      $('bannerPw').classList.remove('hidden');
      $('bannerPw').focus({ preventScroll: true });
      $('bannerErr').textContent = 'Enter the study password once to add the study to your account.';
      return;
    }
    $('bannerErr').textContent = '';
    $('bannerAdd').disabled = true;
    try {
      await AIT.postJSON('api/account.php?action=add', { project_id: PROJECT_ID, password: pw });
      try { sessionStorage.setItem('aiteamstudio.pwSession.' + PROJECT_ID, pw); } catch (_) {}
      $('bannerAddWrap').classList.add('hidden');
      $('bannerIn').classList.remove('hidden');
      refreshSettingsLink();
      AIT.toast('The study was added to your account.');
    } catch (err) {
      // e.g. a stale saved password: let the user type it directly
      $('bannerPw').classList.remove('hidden');
      $('bannerPw').focus({ preventScroll: true });
      $('bannerErr').textContent = err.message;
    } finally {
      $('bannerAdd').disabled = false;
    }
  });
  $('bannerPw').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); $('bannerAdd').click(); }
  });
  $('bannerRemove').addEventListener('click', async () => {
    $('bannerRemove').disabled = true;
    try {
      await AIT.postJSON('api/account.php?action=remove', { project_id: PROJECT_ID });
      $('bannerIn').classList.add('hidden');
      $('bannerAddWrap').classList.remove('hidden');
      AIT.toast('The study was removed from your account. The study itself is unchanged.');
    } catch (err) {
      AIT.toast(err.message, { error: true });
    } finally {
      $('bannerRemove').disabled = false;
    }
  });
}

// ---- Sign up or sign in from here (signed out) ----------------------------------------------
// One submit does everything, in this order: save the open changes (a failed
// save stops it, with the reason in the dialog), create the account or sign in,
// add this study with its password, and reload this page, which then shows the
// study as part of the account. The step stays the same (#stepN).
if ($('acctModal')) {
  let acctMode = 'register';
  const setAcctMode = (m) => {
    acctMode = m;
    $('acctTabRegister').classList.toggle('active', m === 'register');
    $('acctTabLogin').classList.toggle('active', m === 'login');
    $('acctTabRegister').setAttribute('aria-selected', m === 'register' ? 'true' : 'false');
    $('acctTabLogin').setAttribute('aria-selected', m === 'login' ? 'true' : 'false');
    $('acctPwHint').classList.toggle('hidden', m === 'login');
    $('acctPassword').setAttribute('autocomplete', m === 'login' ? 'current-password' : 'new-password');
    $('acctSubmit').textContent = (m === 'login' ? 'Sign in' : 'Create account') + (dirty ? ' and save' : '');
    $('acctError').textContent = '';
  };
  const openAcct = (m, opener) => {
    setAcctMode(m);
    $('acctStudyPwField').classList.toggle('hidden', !!knownPassword());
    openModal($('acctModal'), opener, $('acctEmail'));
  };
  $('bannerSignup').addEventListener('click', () => openAcct('register', $('bannerSignup')));
  $('bannerSignin').addEventListener('click', () => openAcct('login', $('bannerSignin')));
  $('acctTabRegister').addEventListener('click', () => setAcctMode('register'));
  $('acctTabLogin').addEventListener('click', () => setAcctMode('login'));
  $('acctCancel').addEventListener('click', () => closeModal($('acctModal')));
  $('acctForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('acctError');
    err.textContent = '';
    const email = $('acctEmail').value.trim();
    const password = $('acctPassword').value;
    const studyPw = knownPassword() || $('acctStudyPw').value.trim();
    if (!email || !password) { err.textContent = 'Please enter your email and a password.'; return; }
    if (!studyPw) { err.textContent = 'Please enter the password of this study.'; $('acctStudyPw').focus(); return; }
    const submit = $('acctSubmit');
    submit.disabled = true;
    try {
      if (dirty) {
        submit.textContent = 'Saving your changes…';
        if (!(await save())) {
          err.textContent = 'Your changes could not be saved, so no account was created. The message at the bottom of the page says why.';
          return;
        }
      }
      submit.textContent = acctMode === 'login' ? 'Signing in…' : 'Creating your account…';
      await AIT.postJSON('api/account.php?action=' + acctMode, { email, password });
      try {
        await AIT.postJSON('api/account.php?action=add', { project_id: PROJECT_ID, password: studyPw });
        try { sessionStorage.setItem('aiteamstudio.pwSession.' + PROJECT_ID, studyPw); } catch (_) {}
        acctNoteForReload(acctMode === 'login' ? 'You are signed in, and this study is now in your account.'
          : 'Your account was created, and this study is now in your account.', false);
      } catch (addErr) {
        // Signed in, but the study is not in the account yet: the banner after
        // the reload offers "Add to my account" again.
        acctNoteForReload('You are signed in, but this study could not be added: ' + addErr.message, true);
      }
      location.reload();
    } catch (ex) {
      err.textContent = ex.message;
    } finally {
      const message = err.textContent; // setAcctMode clears it
      submit.disabled = false;
      setAcctMode(acctMode);
      err.textContent = message;
    }
  });
}
// A note for the page after the reload of the sign-up above, shown there once.
function acctNoteForReload(text, error) {
  try { sessionStorage.setItem('aiteamstudio.acctNote', JSON.stringify({ text, error })); } catch (_) {}
}
try {
  const note = JSON.parse(sessionStorage.getItem('aiteamstudio.acctNote') || 'null');
  if (note) {
    sessionStorage.removeItem('aiteamstudio.acctNote');
    AIT.toast(note.text, { error: !!note.error, duration: note.error ? 6000 : 3500 });
  }
} catch (_) {}
// Keep the plaintext around for this tab when it arrived via the URL.
(() => {
  const pw = new URLSearchParams(location.search).get('password');
  if (pw) { try { sessionStorage.setItem('aiteamstudio.pwSession.' + PROJECT_ID, pw); } catch (_) {} }
})();

document.querySelectorAll('[data-copy]').forEach((btn) => {
  btn.addEventListener('click', () => AIT.copyText(btn.dataset.copy));
});
document.querySelectorAll('[data-copy-from]').forEach((btn) => {
  btn.addEventListener('click', () => AIT.copyText($(btn.dataset.copyFrom).value));
});

// =============================================================================
// Study status: pause / continue (Step 4 and the pill in the top bar)
// =============================================================================
// Takes effect at once through api/study_status.php, independent of Save.
let paused = !!PROJECT.is_paused;
let pausedAt = PROJECT.paused_at || null;

function renderStatus() {
  $('statusPill').classList.toggle('paused', paused);
  $('statusPillText').textContent = paused ? 'Paused' : 'Active';
  $('statusBox').classList.toggle('paused', paused);
  $('statusTitle').textContent = paused ? 'The study is paused.' : 'The study is active.';
  let since = '';
  if (paused && pausedAt) {
    const d = new Date(String(pausedAt).replace(' ', 'T'));
    if (!Number.isNaN(d.getTime())) since = 'Paused since ' + d.toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) + '. ';
  }
  $('statusSub').textContent = paused
    ? since + 'New participants who open the link see a message that the study is not active.'
    : 'Participants can start the chat with the participant link.';
  const btn = $('pauseBtn');
  btn.className = 'btn ' + (paused ? 'btn-continue' : 'btn-pause');
  btn.innerHTML = paused ? '<i class="fas fa-play"></i> Continue study' : '<i class="fas fa-pause"></i> Pause study';
}

async function setPaused(next) {
  const btn = $('pauseBtn');
  btn.disabled = true;
  try {
    const res = await AIT.postJSON('api/study_status.php', Object.assign(sessionFallback(), { project_id: PROJECT_ID, paused: next ? 1 : 0 }));
    paused = !!res.paused;
    pausedAt = res.paused_at || null;
    renderStatus();
    refreshStepIssues();
    if (currentStep === 4) refreshReadiness();
    AIT.toast(paused ? 'The study is paused. New participants see a message that the study is not active.' : 'The study is active again.', { duration: 3500 });
  } catch (err) {
    AIT.toast(err.message, { error: true, duration: 4500 });
  } finally {
    btn.disabled = false;
  }
}
$('pauseBtn').addEventListener('click', () => setPaused(!paused));
$('statusPill').addEventListener('click', () => {
  showStep(4, { scroll: false });
  $('statusSection').scrollIntoView({ block: 'center', behavior: 'smooth' });
  // A soft pulse once the box is in view, so the eye knows where to look after the jump.
  const box = $('statusSection').querySelector('.status-box');
  if (box) {
    box.classList.remove('attention');
    setTimeout(() => {
      void box.offsetWidth; // restart the animation if it was still running
      box.classList.add('attention');
      box.addEventListener('animationend', () => box.classList.remove('attention'), { once: true });
    }, 450);
  }
});

// =============================================================================
// Dialogs of the top bar: "Add collaborator" and "Duplicate"
// =============================================================================
// One dialog is open at a time. Escape or a click next to it closes it, the
// Tab key stays inside it, and the focus returns to the button that opened it.
let modalOpener = null;
function openModal(modal, opener, focusOn) {
  modalOpener = opener;
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  focusOn.focus({ preventScroll: true });
}
function closeModal(modal) {
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  if (modalOpener) modalOpener.focus({ preventScroll: true });
  modalOpener = null;
  // The first Save of a draft copy created the study: show the saved study as
  // it is now, with everything a draft does not get (the key test, the AI
  // Assistant's keys). Nothing is unsaved at this point.
  if (modal.id === 'draftSavedModal') { window.onbeforeunload = null; location.reload(); }
}
document.querySelectorAll('.modal-backdrop').forEach((modal) => {
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(modal); });
});
document.addEventListener('keydown', (e) => {
  const modal = document.querySelector('.modal-backdrop.open');
  if (!modal) return;
  if (e.key === 'Escape') { closeModal(modal); return; }
  if (e.key !== 'Tab') return;
  const stops = Array.from(modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])')).filter((n) => n.offsetParent !== null);
  if (!stops.length) return;
  const first = stops[0];
  const last = stops[stops.length - 1];
  if (!modal.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
  else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
});

// ---- Add collaborator ---------------------------------------------------------------
$('collabBtn').addEventListener('click', () => {
  refreshSettingsLink();
  openModal($('collabModal'), $('collabBtn'), $('collabClose'));
});
$('collabClose').addEventListener('click', () => closeModal($('collabModal')));
$('collabDone').addEventListener('click', () => closeModal($('collabModal')));

// ---- Duplicate the study --------------------------------------------------------------
const COPY_KEY = 'aiteamstudio.copyOf';
function openDuplicate() {
  $('duplicateDirtyNote').classList.toggle('hidden', !dirty);
  $('duplicateConfirm').innerHTML = '<i class="far fa-copy"></i> ' + (dirty ? 'Save and create the copy' : 'Create the copy');
  openModal($('duplicateModal'), $('duplicateBtn'), $('duplicateCancel'));
}
function closeDuplicate() {
  closeModal($('duplicateModal'));
}
async function duplicateStudy() {
  const btn = $('duplicateConfirm');
  btn.disabled = true;
  try {
    if (dirty && !(await save())) return; // save() already said what is wrong
    const res = await AIT.postJSON('api/duplicate_project.php', Object.assign(sessionFallback(), { project_id: PROJECT_ID }));
    try { sessionStorage.setItem(COPY_KEY, JSON.stringify({ id: res.project_id, from: $('projectName').value.trim() || PROJECT_ID })); } catch (_) {}
    window.onbeforeunload = null;
    location.href = 'settings.php?project=' + encodeURIComponent(res.project_id) + '#step1';
  } catch (err) {
    AIT.toast(err.message, { error: true, duration: 4500 });
  } finally {
    btn.disabled = false;
  }
}
$('duplicateBtn').addEventListener('click', openDuplicate);
$('duplicateCancel').addEventListener('click', closeDuplicate);
$('duplicateConfirm').addEventListener('click', duplicateStudy);

// =============================================================================
// Dirty wiring + save
// =============================================================================
['projectName', 'participantInstructions', 'readyWaitSeconds', 'timerMinutes'].forEach((id) => {
  $(id).addEventListener('input', () => setDirty(true));
});
['showReadyModal', 'timerEnabled', 'showInstructions', 'showChatInstructions'].forEach((id) => {
  $(id).addEventListener('change', () => setDirty(true));
});
$('projectName').addEventListener('input', refreshBadge);
$('studyContext').addEventListener('input', () => { refreshContextCount(); setDirty(true); });
// A different model may count tokens differently.
['modelSelect', 'modelInput'].forEach((id) => $(id).addEventListener(id === 'modelInput' ? 'input' : 'change', () => { refreshContextCount(); refreshDirectorCount(); }));
providerButtons.forEach((b) => b.addEventListener('click', () => { refreshContextCount(); refreshDirectorCount(); }));

/**
 * Save everything; resolves to true when the study is saved (or nothing had to
 * be saved). One save at a time: a second call waits for the running one and
 * then saves only what changed meanwhile.
 */
let saveQueue = Promise.resolve(true);
function save() {
  const run = saveQueue.then(saveNow, saveNow);
  saveQueue = run.catch(() => false);
  return run;
}
async function saveNow() {
  if (!dirty) return true; // nothing to save (also guards the Cmd/Ctrl+S shortcut)
  if (assistants.busy()) {
    AIT.toast('Please wait until the AI Assistant has finished.', { error: true });
    return false;
  }
  const teamIssue = teamProblem();
  if (teamIssue) { AIT.toast(teamIssue, { error: true, duration: 4500 }); showStep(3); return false; }
  const segmentIssue = segmentsProblem();
  if (segmentIssue) { AIT.toast(segmentIssue, { error: true, duration: 4500 }); showStep(3); return false; }
  if (mode === 'visual') $('scriptJson').value = jsonFromRows();
  const scriptRaw = $('scriptJson').value.trim();
  if (scriptRaw) {
    try { parseScriptJson(); } catch (e) {
      showScriptError(e.message);
      AIT.toast('Please fix the sample conversation first: ' + e.message, { error: true, duration: 5000 });
      showStep(3);
      return false;
    }
  }
  const chatInstructionsHtml = chatInstructions.getHTML();
  if (chatInstructionsHtml.length > RICHTEXT_MAX_CHARS) {
    AIT.toast('The instructions above the chat are too long. Please shorten the instructions.', { error: true, duration: 4500 });
    showStep(1);
    return false;
  }
  const btn = $('saveBtn');
  btn.disabled = true;
  btn.textContent = 'Saving…';
  // What this save sends: edits made while the request runs stay unsaved.
  const editsAtStart = editCount;
  const sentKey = $('apiKeyInput').value.trim();
  const sentProvider = currentProvider;
  const sentModel = getModelValue();
  const sentSections = JSON.parse(JSON.stringify({ order: DIR.order, text: DIR.text, actors: DIR.actors }));
  Object.keys(sentSections.actors).forEach((id) => { if (!teamMember(id)) delete sentSections.actors[id]; }); // e.g. back through a card's Undo
  try {
    const res = await AIT.postJSON('api/save_project.php', Object.assign(draft ? { draft_from: draft.source, password: draft.password } : sessionFallback(), {
      project_id: PROJECT_ID,
      name: $('projectName').value.trim(),
      team: TEAM.map((m) => {
        const out = { id: m.id, name: m.name.trim(), color: m.color };
        if (m.avatar) out.avatar = m.avatar;
        return out;
      }),
      study_context: $('studyContext').value.trim(),
      participant_instructions: $('participantInstructions').value,
      show_instructions: $('showInstructions').checked ? 1 : 0,
      director_instructions: assembleDirector(),
      director_sections: sentSections,
      conversation_script: scriptRaw,
      show_ready_modal: $('showReadyModal').checked ? 1 : 0,
      ready_wait_seconds: Math.max(0, Math.min(600, parseInt($('readyWaitSeconds').value, 10) || 0)),
      timer_enabled: $('timerEnabled').checked ? 1 : 0,
      timer_duration_seconds: Math.max(1, Math.min(180, parseInt($('timerMinutes').value, 10) || 10)) * 60,
      show_chat_instructions: $('showChatInstructions').checked ? 1 : 0,
      chat_instructions: chatInstructionsHtml,
      api_provider: sentProvider,
      model: sentModel,
      director_effort: $('directorEffortSelect').value,
      api_format: $('apiFormatSelect').value,
      api_endpoint: $('endpointInput').value.trim(),
      api_key: sentKey,
      clear_api_key: sentKey === '' ? 1 : 0,
    }));
    if (res.created) draftSaved(res);
    // Keep the local state truthful without a reload: the sent key is now the
    // study's stored key (or it was cleared).
    PROJECT.director_sections = sentSections;
    PROJECT.api_key = sentKey;
    PROJECT.api_provider = sentProvider;
    PROJECT.model = sentModel;
    if (sentKey === '' && $('apiKeyInput').value.trim() === '') prefillKeyField(currentProvider);
    refreshKeyHint();
    setDirty(editCount !== editsAtStart); // edits made during the save are still unsaved
    assistants.refreshKeys(); // a key saved in Step 2 may now serve the AI Assistant
    // The assistant conversations are stored only now, so they always describe
    // what is actually saved.
    assistants.persist();
    btn.textContent = 'Saved ✓';
    setTimeout(() => { btn.textContent = 'Save changes'; }, 1600);
    return true;
  } catch (e) {
    AIT.toast(e.message, { error: true, duration: 5000 });
    btn.textContent = 'Save changes';
    return false;
  } finally {
    // Re-enable only when there is still something to save (a failed attempt).
    btn.disabled = !dirty;
  }
}
$('saveBtn').addEventListener('click', save);
document.addEventListener('keydown', (e) => {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
});

// ---- Draft copy of an example: the first Save created the study -----------------------
// The page becomes the ordinary settings page of that study (same ID, now
// unlocked in this session), the URL loses ?example, and a dialog shows the
// credentials once.
let draftCreated = null; // { id, name, password } for the download and copy buttons

// Until the first Save, the controls that need a stored study are greyed out and
// say why on hover; a click on them does nothing.
const DRAFT_LOCKED = ['dataNavLink', 'collabBtn', 'duplicateBtn', 'pauseBtn', 'linkCopy', 'dataCardLink'];
function lockDraftControls(on) {
  for (const id of DRAFT_LOCKED) {
    const el = $(id);
    if (on) {
      el.dataset.title = el.title;
      el.title = 'Save this study first.';
      el.setAttribute('aria-disabled', 'true');
    } else {
      el.title = el.dataset.title || '';
      el.removeAttribute('aria-disabled');
    }
  }
}
document.addEventListener('click', (e) => {
  if (draft && e.target.closest(DRAFT_LOCKED.map((id) => '#' + id).join(','))) {
    e.preventDefault();
    e.stopImmediatePropagation();
  }
}, true);

function draftSaved(res) {
  draftCreated = { id: PROJECT_ID, name: res.name || $('projectName').value.trim(), password: res.password };
  draft = null;
  document.body.classList.remove('draft');
  lockDraftControls(false);
  try { sessionStorage.setItem('aiteamstudio.pwSession.' + PROJECT_ID, draftCreated.password); } catch (_) {}
  history.replaceState(null, '', 'settings.php?project=' + encodeURIComponent(PROJECT_ID) + '#step' + currentStep);
  if ($('draftBanner')) $('draftBanner').remove();
  if ($('bannerIn')) { $('bannerIn').classList.remove('hidden'); $('bannerAddWrap').classList.add('hidden'); CFG.accountPw = draftCreated.password; }
  refreshTestLinks();
  refreshSettingsLink();
  $('draftCredName').textContent = draftCreated.name || '(no name)';
  $('draftCredId').textContent = draftCreated.id;
  $('draftCredPass').textContent = draftCreated.password;
  openModal($('draftSavedModal'), $('saveBtn'), $('draftCredClose'));
}
if ($('draftSavedModal')) {
  $('draftCredDownload').addEventListener('click', () => {
    if (!draftCreated) return;
    const content = [
      'MAESTRO study credentials',
      '',
      'Name:     ' + (draftCreated.name || '(no name)'),
      'Study ID: ' + draftCreated.id,
      'Password: ' + draftCreated.password,
      '',
      'Settings page: ' + CFG.baseUrl + 'settings.php?project=' + draftCreated.id,
      'Keep this file safe. You need these credentials to reopen the study.',
    ].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([content], { type: 'text/plain' }));
    a.download = 'project_' + draftCreated.id + '.txt';
    document.body.appendChild(a);
    a.click();
    a.remove();
  });
  $('draftCredCopy').addEventListener('click', () => {
    if (!draftCreated) return;
    AIT.copyText('Study ID: ' + draftCreated.id + '\nPassword: ' + draftCreated.password);
  });
  $('draftCredClose').addEventListener('click', () => closeModal($('draftSavedModal')));
}

// =============================================================================
// Start
// =============================================================================
// Initial render: the visual editor, or the JSON tab when the stored script does not parse.
if (!renderRowsFromJson(true) && $('scriptJson').value.trim() !== '') {
  $('tabJson').click();
  showScriptError('The stored sample conversation could not be read. Please fix the sample conversation in the JSON tab.');
} else {
  $('scriptAdd').classList.remove('hidden'); // the Add buttons and the segment note belong to the visual editor
  $('segNote').classList.remove('hidden');
}
updateStats();
renderDirSections();
renderTeamRows();
applyTeam();
refreshDirPillNames();
refreshContextCount();
setupCostsCard();
renderUsage();
refreshLinks();
refreshSettingsLink();
renderStatus();
refreshStepIssues();

// Arrived here right after "Duplicate": say what this study is.
try {
  const copy = JSON.parse(sessionStorage.getItem(COPY_KEY) || 'null');
  if (copy && copy.id === PROJECT_ID) {
    sessionStorage.removeItem(COPY_KEY);
    AIT.toast('This is a copy of “' + copy.from + '”. The copy has the same password and no data yet.', { duration: 6000 });
  }
} catch (_) { /* nothing to announce */ }

let startStep = parseInt((location.hash.match(/^#step([1-4])$/) || [])[1], 10);
if (!startStep) {
  try { startStep = parseInt(localStorage.getItem(STEP_KEY), 10) || 1; } catch (_) { startStep = 1; }
}
showStep(startStep, { scroll: false });
window.addEventListener('hashchange', () => {
  const m = location.hash.match(/^#step([1-4])$/);
  if (m && Number(m[1]) !== currentStep) showStep(m[1], { scroll: false });
});

// A draft copy starts unsaved: the save bar says so and Save is enabled.
if (draft) {
  setDirty(true);
  lockDraftControls(true);
}
