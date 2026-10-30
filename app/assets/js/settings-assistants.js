/**
 * settings-assistants.js — the AI Assistant of the settings page (Step 3).
 *
 * One assistant edits every box of Step 3: the texts of the conductor
 * instructions and the sample conversation. The panel docks beside the cards
 * and stays in view while the page scrolls. A request of the researcher is a
 * run of several calls to api/director.php (includes/assist.php), which this
 * module starts one after another and shows as a checklist in the chat:
 *
 *   1. assist_plan    an answer (shown as a message, nothing changes), or a
 *                     plan: new AI actors (added to the team right away) and
 *                     the boxes that need a change, each with a task
 *   2. assist_field   one call per box of the plan; the new text streams into
 *                     the box, or the step is skipped
 *   3. assist_review  checks the result; up to a few fixes run once as more
 *                     steps, and a final review closes the run
 *
 * Undo restores every box the run changed. Stop ends the run after the
 * current call; the finished steps stay.
 *
 * The assistant can use its own API keys per provider (OpenAI, Anthropic),
 * stored with the study apart from the key of Step 2 (api/assistant_keys.php).
 * The "API key" button below the input shows whether the provider of the
 * chosen model has a key and opens the dialog for the keys.
 *
 * The editors belong to settings.js; this module reaches them only through ctx:
 *
 *   ctx.projectId, ctx.project (stored values), ctx.server (default models),
 *   ctx.catalog (models per provider), ctx.isDraft(), ctx.team() (everyone in
 *   the chat), ctx.isTeammate(id), ctx.studyContext(), ctx.participantInfo(),
 *   ctx.setDirty(bool), ctx.openModal(modal, opener, focusOn), ctx.closeModal(modal),
 *   ctx.addActor({ id, name, color }) (a new AI actor from the plan; false when not possible)
 *   ctx.script  { flush(), validate(), undo, live: { begin, segments, append, finish, restore } }
 *   ctx.fields  { state(), get(target), set(target, text), focus(target), blur(target),
 *                 title(target), live: { begin(target), write(target, text) },
 *                 snapshot(), restore(snapshot) }
 *
 * Returns { busy(), persist() }: whether a run is going on, and "store the
 * chat now" (called after a successful save, so a stored chat always
 * describes what is saved).
 *
 * @author Marc Becker
 */

import { createJsonStringReader } from './json-stream.js';

const AIT = window.AIT;
const $ = (id) => document.getElementById(id);

const PROVIDERS = ['openai', 'anthropic', 'custom'];
const PROVIDER_LABELS = { openai: 'OpenAI', anthropic: 'Anthropic', custom: 'Custom gateway' };

// Thinking effort of the assistant (remembered per study in this browser).
// "Minimal" is sent as the lowest value the model accepts.
const EFFORT_OPTIONS = {
  openai: ['minimal', 'low', 'medium', 'high', 'default'],
  anthropic: ['off', 'low', 'medium', 'high'],
};
EFFORT_OPTIONS.custom = EFFORT_OPTIONS.openai;
const EFFORT_LABELS = { off: 'thinking off', default: 'default effort' };

const CONTEXT_LIMITS = [['gpt-4.1', 1000000], ['gpt-6', 1050000], ['gpt-5', 400000], ['claude-haiku', 200000], ['claude', 1000000]];
function contextLimitFor(model) {
  const m = (model || '').toLowerCase();
  for (const [prefix, limit] of CONTEXT_LIMITS) {
    if (m.startsWith(prefix) || m.includes(prefix)) return limit;
  }
  return 128000;
}
function fmtTokens(n) {
  return n >= 1000 ? (n / 1000).toFixed(n >= 10000 ? 0 : 1) + 'k' : String(n);
}

const CHIPS = [
  { label: 'Distinct voices', prompt: 'Give each AI actor a more distinct personality and way of writing' },
  { label: 'More human', prompt: 'Make the AI actors write more like real people texting, with small imperfections and occasional typos' },
  { label: 'Faster replies', prompt: 'Make the replies in the sample conversation come faster' },
  { label: 'Review for gaps', prompt: 'Review all cards for contradictions or gaps and tell me what to improve. Do not change anything yet.' },
  { label: 'Fill the empty cards', prompt: 'Fill the empty cards so that the cards fit the situation, and write a sample conversation that matches the cards' },
  { label: 'Add a segment', prompt: 'Start a new segment at 5:00 in which ' },
];
const WELCOME = 'I can change every card of Step 3: the situation, the AI actors, the dynamics, the conductor and the sample conversation, and I can add new AI actors. ' +
  'For each request I first make a plan, then edit the boxes one by one, and check the result at the end. You can pick a suggestion below or tell me what to change.';

export function createAssistants(ctx) {
  const PROJECT_ID = ctx.projectId;
  const PROJECT = ctx.project;
  const SERVER = ctx.server;
  const catalogIds = (p) => (ctx.catalog[p] || []).flatMap((g) => g.models.map((m) => m.id));
  // Where the key of each provider comes from: { openai: { source, tail, test, study_key }, … }
  let KEYS = PROJECT.assistant_keys || {};

  function savedProvider() {
    return PROVIDERS.includes(PROJECT.api_provider) ? PROJECT.api_provider : 'openai';
  }
  function providerHasKey(p) {
    return !!(KEYS[p] && KEYS[p].source && KEYS[p].source !== 'none');
  }
  /** The team as it is sent to the server (possibly unsaved edits). */
  function teamPayload() {
    return ctx.team().map((m) => ({ id: m.id, name: m.name.trim(), color: m.color }));
  }

  /**
   * One call: streams reasoning (progress.reason), text (live.feed) and returns
   * the final envelope. Throws with the server's message, or an AbortError.
   */
  async function request(payload, progress, live, signal) {
    let finalData = null;
    try {
      for await (const obj of AIT.modelStream(payload, { signal })) {
        if (obj.done) break;
        if (obj.r && progress) progress.reason(obj.r);
        if (obj.d && live) {
          live.feed(obj.d);
          if (progress) progress.writing(live.count());
        }
        if (obj.final) finalData = obj.final;
      }
    } catch (err) {
      if (err && (err.name === 'AbortError' || (signal && signal.aborted))) throw err;
      if (err && err.status) throw err; // the server refused the request: its message says why
      throw new Error('The connection broke before a result arrived (' + ((err && err.message) || 'network error') + '). Please try again.');
    }
    if (!finalData) {
      if (signal && signal.aborted) throw new DOMException('Aborted', 'AbortError');
      throw new Error('The connection ended before a result arrived. Please try again.');
    }
    if (finalData.ok === false) throw new Error(finalData.error || 'The assistant request failed.');
    return finalData;
  }

  /** The "segments" array in a (partial) JSON text, or null when the text has none (yet). */
  function segmentsIn(text) {
    const key = text.indexOf('"segments"');
    const open = key === -1 ? -1 : text.indexOf('[', key);
    if (open === -1) return null;
    let depth = 0;
    let inStr = false;
    let esc = false;
    for (let i = open; i < text.length; i++) {
      const ch = text[i];
      if (inStr) {
        if (esc) esc = false;
        else if (ch === '\\') esc = true;
        else if (ch === '"') inStr = false;
      } else if (ch === '"') inStr = true;
      else if (ch === '[') depth++;
      else if (ch === ']' && --depth === 0) {
        try { const list = JSON.parse(text.slice(open, i + 1)); return Array.isArray(list) ? list : null; } catch (_) { return null; }
      }
    }
    return null;
  }

  // Live renderer for the sample conversation: consumes streamed text, waits
  // for each complete JSON message, and hands it to the editor right away.
  // The model writes the segments before the messages (includes/assist.php),
  // so their headers show before each segment's first message arrives. When
  // it does not restate them, the current ones stand in: the server keeps
  // them in that case.
  function createLiveScriptRenderer(restoreJson) {
    let buf = '';
    let head = ''; // what came before the messages array: where the segments are
    let inMessages = false;
    let done = false;
    let capturing = false;
    let depth = 0;
    let inString = false;
    let escaped = false;
    let objStr = '';
    let prevEnd = 0;
    const live = [];
    let started = false;

    function start() {
      if (!started) { started = true; ctx.script.live.begin(); }
    }
    function appendMessage(m) {
      // Accept "bot2" or the director-style "b2", but only for teammates that exist.
      const code = String(m.sender || '').trim().toLowerCase().match(/^(?:bot|b)(10|[1-9])$/);
      const sender = code && ctx.isTeammate('bot' + code[1]) ? 'bot' + code[1] : null;
      if (!sender) return;
      m.sender = sender;
      start();
      live.push(m);
      prevEnd = ctx.script.live.append(m, prevEnd, live);
    }
    function feed(delta) {
      if (done) return;
      buf += delta;
      if (!inMessages) {
        if (head.length < 40000) head += delta;
        const idx = buf.indexOf('"messages"');
        let arrStart = -1;
        if (idx !== -1) arrStart = buf.indexOf('[', idx);
        else if (buf.trimStart().startsWith('[')) arrStart = buf.indexOf('['); // bare message array
        if (arrStart === -1) {
          if (buf.length > 4000) buf = buf.slice(-300); // keep a tail for split tokens
          return;
        }
        const stated = idx !== -1 ? segmentsIn(head.slice(0, head.lastIndexOf('"messages"'))) : null;
        start();
        ctx.script.live.segments(stated !== null ? stated : (segmentsIn(restoreJson || '') || []));
        inMessages = true;
        buf = buf.slice(arrStart + 1);
      }
      let i = 0;
      while (i < buf.length) {
        const ch = buf[i];
        if (!capturing) {
          if (ch === '{') { capturing = true; depth = 1; inString = false; escaped = false; objStr = '{'; }
          else if (ch === ']') { done = true; break; }
        } else {
          objStr += ch;
          if (inString) {
            if (escaped) escaped = false;
            else if (ch === '\\') escaped = true;
            else if (ch === '"') inString = false;
          } else if (ch === '"') inString = true;
          else if (ch === '{') depth++;
          else if (ch === '}') {
            depth--;
            if (depth === 0) {
              try { appendMessage(JSON.parse(objStr)); } catch (_) { /* skip a malformed element */ }
              capturing = false;
              objStr = '';
            }
          }
        }
        i++;
      }
      buf = buf.slice(i);
    }
    return {
      feed,
      finalize(json) { ctx.script.live.finish(json); },
      restore() { ctx.script.live.restore(restoreJson, started); },
      count: () => live.length,
    };
  }


  // Live renderer for one text box: the "text" of {"text":…,"note":…} streams
  // into the box as it arrives.
  function createLiveTextRenderer(target, restoreText) {
    const reader = createJsonStringReader('text');
    let out = '';
    let started = false;
    return {
      feed(delta) {
        const emit = reader.feed(delta);
        if (!emit) return;
        if (!started) { started = true; ctx.fields.live.begin(target); }
        out += emit;
        ctx.fields.live.write(target, out);
      },
      finalize(text) { ctx.fields.set(target, text); },
      restore() { if (started) ctx.fields.set(target, restoreText); },
      count: () => (out ? out.trim().split(/\s+/).length : 0),
    };
  }

  // ===========================================================================
  // The checklist of a run: one row per call, with a circle that fills in
  // ===========================================================================

  /** Latest bold header (**…**) of the reasoning, otherwise its last non-empty line. */
  function reasoningHeader(text) {
    let header = null;
    const re = /\*\*([^*\n]+)\*\*/g;
    let m;
    while ((m = re.exec(text))) header = m[1];
    if (!header) {
      const lines = text.split('\n').map((s) => s.trim()).filter(Boolean);
      header = lines.length ? lines[lines.length - 1] : '';
    }
    return header.slice(0, 90);
  }

  function createRunCard(host) {
    const card = AIT.el('div', 'a-run');
    const list = AIT.el('ol', 'a-steps');
    card.appendChild(list);
    host.appendChild(card);

    /**
     * One row. opts: { title, meta (the box), target (click scrolls to the box), unit }.
     * State: pending → running → done | skip | fail | stopped.
     */
    function row(opts, before) {
      const li = AIT.el('li', 'a-step is-pending');
      const dot = AIT.el('span', 'a-dot');
      dot.setAttribute('aria-hidden', 'true');
      const body = AIT.el('div', 'a-step-body');
      const head = AIT.el('div', 'a-step-head');
      const title = AIT.el('span', 'a-step-title', opts.title);
      head.appendChild(title);
      if (opts.meta) head.appendChild(AIT.el('span', 'a-step-meta', opts.meta));
      const sub = AIT.el('div', 'a-step-sub');
      body.append(head, sub);
      li.append(dot, body);
      if (opts.target) {
        li.classList.add('is-link');
        li.title = 'Show the box';
        li.addEventListener('click', () => ctx.fields.focus(opts.target, true));
      }
      list.insertBefore(li, before || null);
      li._rec = { title: opts.title, meta: opts.meta || '', target: opts.target || '', state: 'pending', sub: '' };
      let reasoning = '';
      const set = (state, text) => {
        li.className = li.className.replace(/\bis-(pending|running|done|skip|fail|stopped)\b/, 'is-' + state);
        sub.textContent = text || '';
        li._rec.state = state;
        li._rec.sub = text || '';
        host.scrollTop = host.scrollHeight;
      };
      return {
        el: li,
        start(text) { set('running', text || 'Thinking…'); },
        reason(t) {
          reasoning += t;
          const h = reasoningHeader(reasoning);
          if (h) sub.textContent = 'Thinking · ' + h;
        },
        writing(n) { sub.textContent = 'Writing' + (n ? ' · ' + n + ' ' + (opts.unit || 'words') : '…'); },
        done(text) { set('done', text); },
        skip(text) { set('skip', text); },
        fail(text) { set('fail', text); },
        stop(text) { set('stopped', text || 'Not run'); },
        title(t) { title.textContent = t; li._rec.title = t; },
      };
    }
    function divider(text, before) {
      const li = AIT.el('li', 'a-steps-div', text);
      li._rec = { divider: text };
      list.insertBefore(li, before || null);
      return li;
    }
    /** The rows as they stand, for the stored chat (a row that was still running counts as stopped). */
    function record() {
      return [...list.children].map((li) => Object.assign({}, li._rec, li._rec.state === 'running' ? { state: 'stopped' } : {}));
    }
    /** Rebuild stored rows (after a reload). */
    function restore(records) {
      (records || []).forEach((rec) => {
        if (!rec || typeof rec !== 'object') return;
        if (rec.divider) { divider(String(rec.divider)); return; }
        const r = row({ title: String(rec.title || ''), meta: String(rec.meta || ''), target: String(rec.target || '') });
        const fn = { done: 'done', skip: 'skip', fail: 'fail', stopped: 'stop' }[rec.state];
        if (fn) r[fn](String(rec.sub || ''));
        else r.stop(rec.sub || 'Not run');
      });
    }
    return { el: card, row, divider, record, restore };
  }

  /** The "What changed" box under the reply of a run. */
  function changesBox(changes) {
    const box = AIT.el('div', 'a-msg a-changes');
    box.appendChild(AIT.el('div', 'a-changes-title', 'What changed'));
    const ul = document.createElement('ul');
    changes.forEach((c) => ul.appendChild(AIT.el('li', null, c)));
    box.appendChild(ul);
    $('assistantChat').appendChild(box);
    return box;
  }

  /**
   * One stored answer of the assistant, as the chat showed it: the checklist of
   * the run, the reply and the "What changed" box. view: { run: [rows] | null,
   * reply, cls, changes }. Chats stored before the view existed keep only the
   * text ("reply\nPlan: a; b\nChanges made:\n- …"); that text is split up again.
   */
  function showStoredAnswer(m) {
    let v = m.view && typeof m.view === 'object' ? m.view : null;
    if (!v) {
      const [head, changesPart] = String(m.content).split('\nChanges made:\n');
      const planAt = head.indexOf('\nPlan: ');
      const plan = planAt === -1 ? [] : head.slice(planAt + 7).split('; ').filter(Boolean);
      v = {
        run: plan.length ? [{ title: 'Plan the changes', state: 'done', sub: '' }].concat(plan.map((t) => ({ title: t, state: 'done', sub: '' }))) : null,
        reply: planAt === -1 ? head : head.slice(0, planAt),
        cls: 'a-bot',
        changes: changesPart ? changesPart.split('\n').map((l) => l.replace(/^- /, '').trim()).filter(Boolean) : [],
      };
    }
    if (Array.isArray(v.run) && v.run.length) createRunCard($('assistantChat')).restore(v.run);
    if (v.reply) bubble(['a-bot', 'a-warn', 'a-note'].includes(v.cls) ? v.cls : 'a-bot', String(v.reply));
    if (Array.isArray(v.changes) && v.changes.length) changesBox(v.changes.map(String));
  }

  // ===========================================================================
  // The panel
  // ===========================================================================
  const prefKey = 'aiteamstudio.assistant.' + PROJECT_ID;
  const chatKey = 'aiteamstudio.assistantChat.' + PROJECT_ID;
  let chatLog = [];
  let busy = false;
  let runAbort = null;
  let usage = null;
  const exchanges = []; // {els, prev (snapshot before the run, or null), userText, userWrap, logCount, issue}
  let undoRow = null;
  let clearArmTimer = null;

  function loadPrefs() {
    try { return JSON.parse(localStorage.getItem(prefKey)) || {}; } catch (_) { return {}; }
  }
  function savePrefs() {
    try { localStorage.setItem(prefKey, JSON.stringify({ model: $('assistantModel').value, effort: $('assistantEffort').value })); } catch (_) {}
  }
  function persistChat() {
    try {
      const trimmed = chatLog.slice(-40).map((m) => Object.assign({ role: m.role, content: String(m.content).slice(0, 4000) }, m.view ? { view: m.view } : {}));
      if (trimmed.length) localStorage.setItem(chatKey, JSON.stringify(trimmed));
      else localStorage.removeItem(chatKey);
    } catch (_) { /* storage full or blocked: the chat just won't persist */ }
  }
  function readChat(key) {
    try {
      const v = JSON.parse(localStorage.getItem(key));
      return Array.isArray(v) ? v.filter((m) => m && (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string') : [];
    } catch (_) { return []; }
  }
  function loadChat() {
    // Until 2026-10 the conductor instructions had an assistant of their own:
    // its chat joins this one once, and its storage is removed.
    const oldKey = 'aiteamstudio.dirAssistantChat.' + PROJECT_ID;
    const old = readChat(oldKey);
    const chat = readChat(chatKey).concat(old);
    if (old.length) {
      try {
        localStorage.removeItem(oldKey);
        localStorage.removeItem('aiteamstudio.dirAssistant.' + PROJECT_ID);
        localStorage.setItem(chatKey, JSON.stringify(chat.slice(-40)));
      } catch (_) { /* fine: the old chat stays where it was */ }
    }
    return chat;
  }

  /** Current model selection: '' = the assistant's default model (server setting), otherwise "provider::model". */
  function selection() {
    const v = $('assistantModel').value;
    if (!v) {
      const d = SERVER.assistant_model;
      return { provider: d.provider, model: d.model, isDefault: true };
    }
    const [provider, model] = v.split('::');
    return { provider, model, isDefault: false };
  }

  function bubble(cls, text) {
    const el = AIT.el('div', 'a-msg ' + cls, text);
    $('assistantChat').appendChild(el);
    $('assistantChat').scrollTop = $('assistantChat').scrollHeight;
    return el;
  }
  function addUserMessage(text) {
    const wrap = AIT.el('div', 'a-user-wrap');
    wrap.appendChild(AIT.el('div', 'a-msg a-user', text));
    const row = AIT.el('div', 'a-edit-row');
    const btn = AIT.el('button', 'a-edit-btn');
    btn.innerHTML = '<i class="fas fa-pen"></i>';
    btn.type = 'button';
    btn.title = 'Edit this message and try again';
    btn.addEventListener('click', editLast);
    row.appendChild(btn);
    wrap.appendChild(row);
    $('assistantChat').appendChild(wrap);
    $('assistantChat').scrollTop = $('assistantChat').scrollHeight;
    return wrap;
  }

  // Model select across all providers (grouped). A provider without a key
  // stays selectable; the API key button then turns yellow.
  function buildSelects() {
    const prefs = loadPrefs();
    const modelSel = $('assistantModel');
    const keep = modelSel.value || prefs.model || '';
    modelSel.innerHTML = '';
    const saved = savedProvider();
    const def = AIT.el('option', null, SERVER.assistant_model.model + ' · default');
    def.value = '';
    modelSel.appendChild(def);
    PROVIDERS.forEach((p) => {
      if (p === 'custom' && saved !== 'custom') return; // a gateway needs the endpoint of Step 2
      const group = document.createElement('optgroup');
      group.label = PROVIDER_LABELS[p] + (providerHasKey(p) ? '' : ' (no key)');
      catalogIds(p).forEach((m) => {
        const o = AIT.el('option', null, m);
        o.value = p + '::' + m;
        group.appendChild(o);
      });
      modelSel.appendChild(group);
    });
    if (keep && [...modelSel.options].some((o) => o.value === keep)) modelSel.value = keep;
    buildEffort($('assistantEffort').value || prefs.effort || '');
    refreshStatus();
  }
  function buildEffort(keep) {
    const sel = $('assistantEffort');
    const options = EFFORT_OPTIONS[selection().provider] || EFFORT_OPTIONS.openai;
    sel.innerHTML = '';
    options.forEach((value) => {
      const o = AIT.el('option', null, EFFORT_LABELS[value] || (value + ' effort'));
      o.value = value;
      sel.appendChild(o);
    });
    sel.value = options.includes(keep) ? keep : 'medium';
  }
  function refreshStatus() {
    const sel = selection();
    $('assistantModel').title = 'Model: ' + sel.model + ' (' + PROVIDER_LABELS[sel.provider] + '). Click to change.';
    if (usage && usage.total) {
      const limit = contextLimitFor(usage.model || sel.model);
      const pct = Math.min(100, Math.round((usage.total / limit) * 1000) / 10);
      $('assistantDonut').style.background = 'conic-gradient(var(--accent) ' + Math.max(pct, 2) + '%, var(--line) 0)';
      $('assistantCtxText').textContent = pct + '% context';
      $('assistantCtxSide').title = 'The largest call of the last request used ' + fmtTokens(usage.total) + ' of the ' + fmtTokens(limit) + ' tokens (units of text) that the model can read at once (' + pct + '%).';
    } else {
      $('assistantDonut').style.background = 'conic-gradient(var(--accent) 0%, var(--line) 0)';
      $('assistantCtxText').textContent = 'context –';
      $('assistantCtxSide').title = 'No request has been sent yet. After the first request, this indicator shows how much of the model’s context window (the text the model can read at once) the largest call used.';
    }
    refreshKeyButton();
  }
  $('assistantModel').addEventListener('change', () => { buildEffort($('assistantEffort').value); refreshStatus(); savePrefs(); });
  $('assistantEffort').addEventListener('change', () => { refreshStatus(); savePrefs(); });

  // Chips copy their prompt into the input (editable); they never send by themselves.
  function renderChips() {
    const host = $('assistantChips');
    host.innerHTML = '';
    CHIPS.forEach((c) => {
      const chip = AIT.el('button', 'a-chip', c.label);
      chip.type = 'button';
      chip.title = c.prompt;
      chip.addEventListener('click', () => {
        const input = $('assistantInput');
        input.value = c.prompt;
        growInput();
        input.focus({ preventScroll: true });
        input.setSelectionRange(input.value.length, input.value.length);
      });
      host.appendChild(chip);
    });
  }

  function showWelcome() {
    const chat = $('assistantChat');
    chat.innerHTML = '';
    exchanges.length = 0;
    undoRow = null;
    const saved = loadChat();
    if (saved.length) {
      chatLog = saved.slice();
      saved.forEach((m) => {
        if (m.role === 'user') {
          const wrap = AIT.el('div', 'a-user-wrap');
          wrap.appendChild(AIT.el('div', 'a-msg a-user', m.content));
          chat.appendChild(wrap);
        } else {
          showStoredAnswer(m);
        }
      });
      chat.scrollTop = chat.scrollHeight;
    } else {
      bubble('a-bot', WELCOME);
    }
  }

  // Clear button: two-step confirmation (armed for 3.5 s), wipes the chat and its storage.
  $('assistantClearBtn').addEventListener('click', () => {
    if (busy) { AIT.toast('Please wait until the current request has finished.', { error: true }); return; }
    const btn = $('assistantClearBtn');
    if (!btn.classList.contains('armed')) {
      btn.classList.add('armed');
      btn.textContent = 'Clear?';
      btn.style.color = 'var(--danger)';
      clearArmTimer = setTimeout(() => {
        btn.classList.remove('armed');
        btn.innerHTML = '<i class="far fa-trash-alt"></i>';
        btn.style.color = '';
      }, 3500);
      return;
    }
    clearTimeout(clearArmTimer);
    btn.classList.remove('armed');
    btn.innerHTML = '<i class="far fa-trash-alt"></i>';
    btn.style.color = '';
    chatLog = [];
    try { localStorage.removeItem(chatKey); } catch (_) {}
    showWelcome();
    AIT.toast('The assistant chat was cleared');
  });

  // Open / close: the panel docks beside every card of Step 3.
  function setOpen(open) {
    $('assistantPanel').classList.toggle('open', open);
    document.body.classList.toggle('assistant-docked', open);
    $('dirAssistantToggleBtn').innerHTML = '<i class="fas fa-magic"></i> AI Assistant' + (open ? ' <i class="fas fa-times"></i>' : '');
    $('dirAssistantToggleBtn').setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      buildSelects();
      renderChips();
      if (!$('assistantChat').children.length) showWelcome();
      setTimeout(() => $('assistantInput').focus({ preventScroll: true }), 60);
    }
  }
  $('dirAssistantToggleBtn').addEventListener('click', () => setOpen(!$('assistantPanel').classList.contains('open')));
  $('assistantCloseBtn').addEventListener('click', () => { setOpen(false); refreshFab(); });

  // The floating button: on Step 3, while the panel is closed and the header
  // button has scrolled away under the top bar, a fixed button keeps the
  // assistant one click away.
  function refreshFab() {
    const head = $('dirAssistantToggleBtn');
    const onStep = !!head.closest('.step.active');
    const scrolledAway = onStep && head.getBoundingClientRect().bottom < 66; // under the top bar (58px)
    $('assistantFab').hidden = !(scrolledAway && !$('assistantPanel').classList.contains('open'));
  }
  window.addEventListener('scroll', refreshFab, { passive: true }); // one measurement: cheap enough for every scroll event
  window.addEventListener('resize', refreshFab);
  new MutationObserver(refreshFab).observe($('dirAssistantToggleBtn').closest('.step'), { attributes: true, attributeFilter: ['class'] });
  $('assistantFab').addEventListener('click', () => { setOpen(true); refreshFab(); });
  $('dirAssistantToggleBtn').addEventListener('click', () => refreshFab());

  // ---- Undo / edit act on the LAST exchange only ------------------------------------
  function renderUndo() {
    if (undoRow) { undoRow.remove(); undoRow = null; }
    $('assistantChat').querySelectorAll('.a-user-wrap').forEach((w) => w.classList.remove('editable', 'obvious'));
    if (!exchanges.length || busy) return;
    const last = exchanges[exchanges.length - 1];
    if (last.userWrap) {
      last.userWrap.classList.add('editable');
      const btn = last.userWrap.querySelector('.a-edit-btn');
      if (last.issue) {
        last.userWrap.classList.add('obvious');
        if (btn) btn.innerHTML = '<i class="fas fa-pen"></i> Edit and retry';
      } else if (btn) {
        btn.innerHTML = '<i class="fas fa-pen"></i>';
      }
    }
    const row = AIT.el('div', 'a-undo-row');
    const undoBtn = AIT.el('button', 'a-undo-btn');
    undoBtn.innerHTML = '<i class="fas fa-undo"></i> Undo';
    undoBtn.type = 'button';
    undoBtn.title = last.prev ? 'Restore every box as the box was before this request, and remove the request from the chat' : 'Remove this request from the chat';
    undoBtn.addEventListener('click', undoLast);
    row.append(undoBtn);
    $('assistantChat').appendChild(row);
    undoRow = row;
    $('assistantChat').scrollTop = $('assistantChat').scrollHeight;
  }
  function undoLast() {
    const ex = exchanges.pop();
    if (!ex) return null;
    if (ex.prev) {
      ctx.fields.restore(ex.prev, ex.after);
      ctx.setDirty(true);
    }
    ex.els.forEach((el) => { try { el.remove(); } catch (_) {} });
    chatLog.splice(-ex.logCount, ex.logCount);
    renderUndo();
    AIT.toast(ex.prev ? 'The changes of this request were undone' : 'The message was removed');
    return ex;
  }
  function editLast() {
    const ex = undoLast();
    if (!ex) return;
    const input = $('assistantInput');
    input.value = ex.userText || '';
    growInput();
    input.focus({ preventScroll: true });
    input.setSelectionRange(input.value.length, input.value.length);
  }

  // ---- Send button: Stop while a run goes on ------------------------------------------
  function setRunning(on) {
    busy = on;
    const btn = $('assistantSendBtn');
    btn.classList.toggle('is-stop', on);
    btn.innerHTML = on ? '<i class="fas fa-stop"></i>' : '<i class="fas fa-paper-plane"></i>';
    btn.title = on ? 'Stop now (the box being written keeps its previous text)' : 'Send';
    $('assistantInput').disabled = on;
  }
  $('assistantSendBtn').addEventListener('click', () => {
    if (busy) {
      if (runAbort) runAbort.abort();
      return;
    }
    send($('assistantInput').value);
  });
  // The input starts as one line (as high as the send button) and grows with its text up to six lines, then scrolls.
  // Enter sends; Shift+Enter starts a new line.
  function growInput() {
    const ta = $('assistantInput');
    if (!ta.value) { ta.style.height = ''; ta.style.overflowY = 'hidden'; return; } // one line (a long placeholder must not stretch the field)
    ta.style.height = 'auto';
    const max = parseFloat(getComputedStyle(ta).maxHeight) || 138;
    const want = ta.scrollHeight + 2; // + the borders
    ta.style.height = Math.min(want, max) + 'px';
    ta.style.overflowY = want > max ? 'auto' : 'hidden';
  }
  $('assistantInput').addEventListener('input', growInput);
  $('assistantInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !busy) { e.preventDefault(); send($('assistantInput').value); }
  });

  // ===========================================================================
  // A run: plan → one call per box → review (→ fixes → final review)
  // ===========================================================================
  const isAbort = (e) => !!(e && (e.name === 'AbortError' || (runAbort && runAbort.signal.aborted)));

  async function send(text) {
    text = (text || '').trim();
    if (!text || busy) return;
    if (ctx.isDraft && ctx.isDraft()) {
      bubble('a-warn', 'Save your copy first. The first save creates the study, so that the AI Assistant can work on the study.');
      return;
    }
    const sel = selection();
    if (!providerHasKey(sel.provider)) {
      bubble('a-warn', 'There is no API key for ' + PROVIDER_LABELS[sel.provider] + ' yet. You can add a key with the "API key" button below, or pick a model of another provider.');
      return;
    }
    if (ctx.script.flush().trim() !== '') {
      try { ctx.script.validate(); } catch (err) {
        bubble('a-warn', 'The sample conversation is not valid at the moment (' + err.message + '). Please fix the sample conversation first, or switch to the visual editor.');
        return;
      }
    }

    runAbort = new AbortController();
    const signal = runAbort.signal;
    setRunning(true);
    renderUndo(); // hides undo and the pencil while a run goes on
    $('assistantInput').value = '';
    growInput();
    const userWrap = addUserMessage(text);
    chatLog.push({ role: 'user', content: text });
    const before = ctx.fields.snapshot();
    const run = createRunCard($('assistantChat'));
    const els = [userWrap, run.el];
    let maxUsage = null;
    const base = () => ({
      project_id: PROJECT_ID,
      team: teamPayload(),
      study_context: ctx.studyContext(),
      participant_info: ctx.participantInfo(),
      state: ctx.fields.state(),
      provider_override: sel.isDefault ? '' : sel.provider,
      model_override: sel.isDefault ? '' : sel.model,
      effort_override: $('assistantEffort').value,
    });
    const call = async (payload, row, live) => {
      const res = await request(Object.assign(base(), payload), row, live, signal);
      if (res.usage && (!maxUsage || res.usage.total > maxUsage.total)) maxUsage = Object.assign({}, res.usage, { model: res.model });
      return res;
    };
    const finish = (logText, issue, changed, view) => {
      chatLog.push({ role: 'assistant', content: logText, view });
      // prev and after: Undo puts back only what changed in between.
      exchanges.push({ els, prev: changed ? before : null, after: changed ? ctx.fields.snapshot() : null, userText: text, userWrap, logCount: 2, issue });
      if (maxUsage) { usage = maxUsage; refreshStatus(); }
      runAbort = null;
      setRunning(false);
      renderUndo();
      $('assistantInput').focus({ preventScroll: true });
    };

    // ---- 1. Plan -------------------------------------------------------------------
    const planRow = run.row({ title: 'Plan the changes' });
    const reviewRow = run.row({ title: 'Check the result' });
    planRow.start();
    let plan;
    try {
      plan = await call({ mode: 'assist_plan', chat: chatLog.slice(-30).map((m) => ({ role: m.role, content: m.content })) }, planRow, null);
    } catch (e) {
      run.el.remove();
      els.splice(1, 1);
      const msg = isAbort(e) ? 'Stopped before anything was changed.' : e.message;
      els.push(bubble(isAbort(e) ? 'a-note' : 'a-warn', msg));
      chatLog.pop();
      exchanges.push({ els, prev: null, userText: text, userWrap, logCount: 0, issue: !isAbort(e) });
      runAbort = null;
      setRunning(false);
      renderUndo();
      return;
    }
    if (plan.type === 'answer') {
      run.el.remove();
      els.splice(1, 1);
      els.push(bubble('a-bot', plan.answer));
      finish(plan.answer, false, false, { run: null, reply: plan.answer, cls: 'a-bot', changes: [] });
      return;
    }
    planRow.done(plan.reply || (plan.steps.length === 1 ? 'One box to change' : plan.steps.length + ' boxes to change'));
    const results = [];

    // ---- New AI actors join the team first, so the steps can write their boxes ---------
    let steps = plan.steps.slice();
    (plan.new_actors || []).forEach((a) => {
      const row = run.row({ title: 'Add ' + a.name + ' as an AI actor', meta: 'AI actors', target: 'actor:' + a.id }, reviewRow.el);
      if (ctx.addActor(a)) {
        row.done('New AI actor');
        results.push(resultOf({ target: 'actor:' + a.id, title: 'Add ' + a.name }, 'done', 'New AI actor ' + a.name, ['+ new AI actor ' + a.name + ' (' + a.id + ')']));
      } else {
        row.fail('The team is full, or the name or the id is already taken');
        steps = steps.filter((st) => st.target !== 'actor:' + a.id);
      }
    });

    // ---- 2. One call per box ---------------------------------------------------------
    const rows = steps.map((s) => run.row({ title: s.title, meta: ctx.fields.title(s.target), target: s.target, unit: s.target === 'script' ? 'messages' : 'words' }, reviewRow.el));
    const stepPayload = () => ({ request: text, plan_reply: plan.reply || '', steps });
    const runSteps = async (list, listRows) => {
      for (let i = 0; i < list.length; i++) {
        if (signal.aborted) { listRows[i].stop(); results.push(resultOf(list[i], 'stopped', '', [])); continue; }
        results.push(await runStep(list[i], listRows[i], stepPayload, call));
      }
    };
    await runSteps(steps, rows);
    const changed = () => results.some((r) => r.status === 'done');

    // ---- 3. Review (fixes once, then a final review) ----------------------------------
    let review = null;
    let activeReviewRow = reviewRow; // the check that runs: the review, later the final check
    if (signal.aborted) {
      reviewRow.stop();
    } else if (!steps.length) {
      reviewRow.skip('No box was changed'); // a plan that only added AI actors: nothing to review
    } else {
      reviewRow.start();
      try {
        review = await call(Object.assign(stepPayload(), { mode: 'assist_review', results }), reviewRow, null);
        if (!review.review_ok && review.fixes && review.fixes.length) {
          reviewRow.done(review.reply || 'Found something to fix');
          run.divider('Fixes from the review');
          const fixes = review.fixes;
          steps.push(...fixes);
          const fixRows = fixes.map((s) => run.row({ title: s.title, meta: ctx.fields.title(s.target), target: s.target, unit: s.target === 'script' ? 'messages' : 'words' }));
          await runSteps(fixes, fixRows);
          const finalRow = run.row({ title: 'Final check' });
          activeReviewRow = finalRow;
          if (signal.aborted) {
            finalRow.stop();
            review = null;
          } else {
            finalRow.start();
            review = await call(Object.assign(stepPayload(), { mode: 'assist_review', results, final: 1 }), finalRow, null);
            finalRow.done('Done');
          }
        } else {
          reviewRow.done(review.issue ? 'The check could not be read' : 'Everything fits');
        }
      } catch (e) {
        if (isAbort(e)) activeReviewRow.stop();
        else activeReviewRow.fail(e.message);
        review = null;
      }
    }

    // ---- The answer of the run ----------------------------------------------------------
    const doneSteps = results.filter((r) => r.status === 'done');
    let reply;
    if (review && review.reply) reply = review.reply;
    else if (signal.aborted) reply = 'Stopped. ' + (doneSteps.length ? doneSteps.length + (doneSteps.length === 1 ? ' box was' : ' boxes were') + ' changed; Undo restores every box.' : 'Nothing was changed.');
    else reply = doneSteps.length ? 'The changes were applied, but the result was not checked. Please look over the boxes; Undo restores every box.' : 'Nothing was changed.';
    const replyCls = review && review.issue ? 'a-warn' : 'a-bot';
    els.push(bubble(replyCls, reply));
    const changes = review && review.changes && review.changes.length ? review.changes : doneSteps.map((r) => r.title + (r.note ? ': ' + r.note : ''));
    const shownChanges = changed() && changes.length ? changes : [];
    if (shownChanges.length) {
      els.push(changesBox(shownChanges));
      ctx.setDirty(true);
    }
    // The chat keeps what was done, never the edited text.
    let logged = reply + '\nPlan: ' + steps.map((s) => s.title).join('; ');
    if (changed() && changes.length) logged += '\nChanges made:\n- ' + changes.join('\n- ');
    finish(logged, results.some((r) => r.status === 'error'), changed(), { run: run.record(), reply, cls: replyCls, changes: shownChanges });
  }

  function resultOf(step, status, note, diff) {
    return { target: step.target, title: step.title, status, note: note || '', diff: diff || [] };
  }

  /** One box: stream the new content into the box, then apply or restore it. */
  async function runStep(step, row, stepPayload, call) {
    const isScript = step.target === 'script';
    row.start();
    ctx.fields.focus(step.target);
    const sent = isScript ? ctx.script.flush() : ctx.fields.get(step.target);
    const live = isScript ? createLiveScriptRenderer(sent) : createLiveTextRenderer(step.target, sent);
    try {
      const res = await call(Object.assign(stepPayload(), { mode: 'assist_field', step }), row, live);
      if (res.status === 'done') {
        if (isScript) {
          const next = JSON.stringify(res.script, null, 2);
          ctx.script.undo.push(sent);
          ctx.script.undo.push(next);
          live.finalize(next); // swap the live preview for the server-normalized conversation
        } else {
          live.finalize(res.text);
        }
        row.done(res.note || (isScript ? res.message_count + ' messages' : 'Updated'));
        return resultOf(step, 'done', res.note, res.diff);
      }
      live.restore();
      if (res.status === 'skip') { row.skip(res.note || 'No change needed'); return resultOf(step, 'skip', res.note, []); }
      row.fail(res.note || 'The model did not return a usable result');
      return resultOf(step, 'error', res.note, []);
    } catch (e) {
      live.restore();
      if (isAbort(e)) { row.stop('Stopped'); return resultOf(step, 'stopped', '', []); }
      row.fail(e.message);
      return resultOf(step, 'error', e.message, []);
    } finally {
      ctx.fields.blur(step.target);
    }
  }

  // ===========================================================================
  // API keys: the button below the input and the dialog
  // ===========================================================================
  const KEY_SOURCE_TEXT = {
    own: 'Uses the assistant’s own key',
    study: 'Uses the key from Step 2',
    server: 'Uses the default key of the server',
    none: 'No key yet',
  };
  function refreshKeyButton() {
    const p = selection().provider;
    const failed = providerHasKey(p) && KEYS[p].source === 'own' && KEYS[p].test && !KEYS[p].test.ok;
    const ok = providerHasKey(p) && !failed;
    const btn = $('assistantKeyBtn');
    btn.classList.toggle('ok', ok);
    btn.classList.toggle('warn', !ok);
    btn.innerHTML = '<i class="fas ' + (ok ? 'fa-check' : 'fa-exclamation') + '"></i> API key';
    btn.title = ok
      ? PROVIDER_LABELS[p] + ': ' + KEY_SOURCE_TEXT[KEYS[p].source] + '. Click to manage the API keys.'
      : failed ? 'The ' + PROVIDER_LABELS[p] + ' key of the assistant did not work in the last test. Click to check the key.'
        : 'There is no API key for ' + PROVIDER_LABELS[p] + ' yet. Click to add a key.';
  }

  const keyRows = {};
  document.querySelectorAll('#akeyModal .akey-row').forEach((rowEl) => {
    const p = rowEl.dataset.provider;
    const r = keyRows[p] = {
      row: rowEl,
      state: rowEl.querySelector('.akey-state'),
      form: rowEl.querySelector('.akey-form'),
      input: rowEl.querySelector('.akey-input'),
      save: rowEl.querySelector('.akey-save'),
      importBtn: rowEl.querySelector('.akey-import'),
      remove: rowEl.querySelector('.akey-remove'),
      placeholder: rowEl.querySelector('.akey-input').placeholder,
      busy: false,
      message: null, // { ok, text } of the last action in this dialog
    };
    r.form.addEventListener('submit', (e) => {
      e.preventDefault();
      const key = r.input.value.trim();
      if (!key) { r.input.focus(); return; }
      keyAction(p, 'save', key);
    });
    r.importBtn.addEventListener('click', () => keyAction(p, 'import'));
    r.remove.addEventListener('click', () => keyAction(p, 'remove'));
  });

  function renderKeyRow(p) {
    const r = keyRows[p];
    const k = KEYS[p] || { source: 'none' };
    let state = '';
    let cls = '';
    if (r.busy) { state = '<i class="fas fa-circle-notch fa-spin"></i> ' + r.busy; cls = 'is-busy'; }
    else if (k.source === 'own') {
      const t = k.test;
      state = '<i class="fas ' + (t && !t.ok ? 'fa-exclamation-triangle' : 'fa-check-circle') + '"></i> Own key <span class="mono">' + (k.tail || '') + '</span>' +
        (t ? (t.ok ? ' · works' : ' · test failed') : ' · not tested');
      cls = t && !t.ok ? 'is-warn' : 'is-ok';
    } else if (k.source === 'study' || k.source === 'server') {
      state = '<i class="fas fa-check-circle"></i> ' + KEY_SOURCE_TEXT[k.source];
      cls = 'is-ok is-inherited';
    } else {
      state = '<i class="fas fa-exclamation-circle"></i> ' + KEY_SOURCE_TEXT.none;
      cls = 'is-none';
    }
    r.state.className = 'akey-state ' + cls;
    r.state.innerHTML = state;
    r.row.classList.toggle('has-own', k.source === 'own');
    r.remove.classList.toggle('hidden', k.source !== 'own');
    r.importBtn.disabled = !k.study_key || keyBusy();
    r.importBtn.title = k.study_key
      ? 'Store the key of Step 2 as the assistant’s own key, so the assistant keeps the key if Step 2 changes'
      : 'Step 2 has no saved ' + PROVIDER_LABELS[p] + ' key';
    r.save.disabled = keyBusy();
    r.remove.disabled = keyBusy();
    r.input.disabled = !!r.busy;
    r.input.placeholder = k.source === 'own' ? 'Paste a new key to replace the key ' + (k.tail || '') : r.placeholder;
    let note = r.row.querySelector('.akey-note');
    if (!note) { note = AIT.el('p', 'akey-note'); r.row.querySelector('.akey-main').appendChild(note); }
    const msg = r.message || (k.source === 'own' && k.test && !k.test.ok ? { ok: false, text: k.test.hint } : null);
    note.className = 'akey-note' + (msg ? (msg.ok ? ' is-ok' : ' is-error') : ' hidden');
    note.textContent = msg ? msg.text : '';
  }
  function renderKeys() {
    Object.keys(keyRows).forEach(renderKeyRow);
    if ($('assistantPanel').classList.contains('open')) buildSelects(); // the "(no key)" labels
    else refreshKeyButton();
  }

  /** True while a key of any provider is being saved, imported, tested or removed. */
  const keyBusy = () => Object.values(keyRows).some((row) => row.busy);

  async function keyAction(p, action, key) {
    const r = keyRows[p];
    if (keyBusy()) return; // one key action at a time: each one writes all keys
    if (ctx.isDraft && ctx.isDraft()) { r.message = { ok: false, text: 'Save your copy first. Keys can be stored once the study exists.' }; renderKeyRow(p); return; }
    r.busy = action === 'remove' ? 'Removing…' : 'Testing the key…';
    r.message = null;
    Object.keys(keyRows).forEach(renderKeyRow); // the other row's buttons wait too
    const sel = selection();
    try {
      const res = await AIT.postJSON('api/assistant_keys.php', {
        project_id: PROJECT_ID, action, provider: p, key: key || '',
        model: sel.provider === p ? sel.model : '',
      });
      KEYS = res.keys || KEYS;
      r.input.value = '';
      if (action === 'remove') r.message = { ok: true, text: 'The key was removed.' + (KEYS[p].source !== 'none' ? ' ' + KEY_SOURCE_TEXT[KEYS[p].source] + ' again.' : '') };
      else if (res.test) r.message = res.test.ok ? { ok: true, text: 'The key works' + (res.test.model ? ' with ' + res.test.model : '') + '. The key is stored with this study.' } : { ok: false, text: 'The key was stored, but the test failed. ' + res.test.hint };
    } catch (e) {
      r.message = { ok: false, text: e.message };
    } finally {
      r.busy = false;
      renderKeys();
    }
  }

  $('assistantKeyBtn').addEventListener('click', () => {
    Object.values(keyRows).forEach((r) => { r.message = null; });
    renderKeys();
    const p = selection().provider;
    const first = keyRows[p] ? keyRows[p].input : keyRows.openai.input;
    ctx.openModal($('akeyModal'), $('assistantKeyBtn'), first);
  });
  $('akeyClose').addEventListener('click', () => ctx.closeModal($('akeyModal')));
  $('akeyDone').addEventListener('click', () => ctx.closeModal($('akeyModal')));
  refreshKeyButton();

  /** Ask the server again where each key comes from (a Save may have changed the key of Step 2). */
  async function refreshKeys() {
    if (keyBusy()) return;
    try {
      const res = await AIT.postJSON('api/assistant_keys.php', { project_id: PROJECT_ID, action: 'status' });
      KEYS = res.keys || KEYS;
      renderKeys();
    } catch (_) { /* the dialog keeps the last known state */ }
  }

  return { busy: () => busy, persist: persistChat, refreshKeys };
}
