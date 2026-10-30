/**
 * ui.js — everything that touches the DOM of the chat page.
 *
 * The rest of the runtime never queries the document; it calls these
 * functions. Message and typing rows are built by the shared helpers in
 * assets/js/app.js (window.AIT) so the chat and the data page look alike.
 *
 * @author Marc Becker
 */

import { TIMING } from './config.js';

/**
 * One typing row per role, whatever the timers do. A row that is fading out
 * ("someone gave up typing") can be taken back: if the same teammate starts
 * typing again during the fade, the existing row simply stays — so two
 * indicators of one color can never be on screen together.
 * (Exported for the unit tests in app/tests.)
 *
 * mount(role) → row   creates and attaches the row;   unmount(row) removes it.
 */
export function createTypingRegistry({ mount, unmount }) {
  const rows = {}; // role → { row, fadeTimer }

  function hide(role) {
    const entry = rows[role];
    if (!entry) return;
    clearTimeout(entry.fadeTimer);
    delete rows[role];
    unmount(entry.row);
  }

  return {
    /** Show the indicator; a visible or fading row is kept as it is. */
    show(role) {
      const entry = rows[role];
      if (entry) {
        clearTimeout(entry.fadeTimer);
        entry.fadeTimer = null;
        return;
      }
      rows[role] = { row: mount(role), fadeTimer: null };
    },
    hide,
    hideAll() {
      Object.keys(rows).forEach(hide);
    },
    /** The roles whose indicator is on (a fading row no longer counts). */
    roles() {
      return Object.keys(rows).filter((role) => !rows[role].fadeTimer);
    },
    /** Let the indicator linger for ms, then remove it — unless show() takes it back meanwhile. */
    fade(role, ms) {
      const entry = rows[role];
      if (!entry || entry.fadeTimer) return;
      entry.fadeTimer = setTimeout(() => {
        if (rows[role] === entry) delete rows[role];
        unmount(entry.row);
      }, ms);
    },
  };
}

/** @param team the project's role map (id → name/colors), installed into the shared row builders */
export function createUI({ team } = {}) {
  const $ = (id) => document.getElementById(id);
  if (team) window.AIT.setTeam(team);
  const chatScroll = $('chatScroll');
  const chatBox = $('chatBox');
  const input = $('userInput');
  const sendBtn = $('sendBtn');
  const note = $('composerNote');

  /** Keystrokes typed since the last send — recorded with each participant message. */
  let keystrokes = 0;

  function scrollToBottom() {
    chatScroll.scrollTop = chatScroll.scrollHeight;
  }

  function lastMessageRow() {
    const rows = chatBox.querySelectorAll('.msg:not(.typing-row)');
    return rows.length ? rows[rows.length - 1] : null;
  }

  /** Keep typing rows below the newest message. */
  function typingRowsToBottom() {
    const rows = chatBox.querySelectorAll('.msg.typing-row');
    rows.forEach((r) => chatBox.appendChild(r));
  }

  // ---- Messages -------------------------------------------------------------

  /** Append a message bubble. Consecutive messages of one sender are grouped. */
  function addMessage({ role, text, time }) {
    const prev = lastMessageRow();
    const grouped = !!(prev && prev.dataset.role === role &&
      (time.getTime() - (parseInt(prev.dataset.ts, 10) || 0)) < TIMING.GROUP_WITHIN_MS);
    const row = window.AIT.messageRow({ role, text, time, grouped });
    row.dataset.role = role;
    row.dataset.ts = String(time.getTime());
    const firstTyping = chatBox.querySelector('.msg.typing-row');
    if (firstTyping) chatBox.insertBefore(row, firstTyping);
    else chatBox.appendChild(row);
    typingRowsToBottom();
    scrollToBottom();
    return row;
  }

  // ---- Typing indicators ----------------------------------------------------

  const typing = createTypingRegistry({
    mount(role) {
      const row = window.AIT.typingRow(role);
      chatBox.appendChild(row);
      scrollToBottom();
      return row;
    },
    unmount(row) {
      row.remove();
    },
  });
  const showTyping = (role) => typing.show(role);
  const hideTyping = (role) => typing.hide(role);
  const hideAllTyping = () => typing.hideAll();
  const typingRoles = () => typing.roles();
  /** Let an indicator linger a moment before it disappears (someone gave up typing). */
  const fadeTyping = (role, ms = 1000 + Math.random() * 2000) => typing.fade(role, ms);

  // ---- Composer ---------------------------------------------------------------

  function onSend(callback) {
    const submit = () => {
      const text = input.value.trim();
      if (!text || input.disabled) return;
      input.value = '';
      const count = keystrokes;
      keystrokes = 0;
      callback(text, count);
    };
    sendBtn.addEventListener('click', submit);
    input.addEventListener('keydown', (e) => {
      // Enter sends, except while an input method (Japanese, Chinese, Korean…)
      // is still composing a word: that Enter only confirms the composition.
      if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.isComposing && e.keyCode !== 229) {
        e.preventDefault();
        submit();
      } else {
        keystrokes += 1;
      }
    });
  }

  function disableInput(message) {
    input.disabled = true;
    sendBtn.disabled = true;
    input.placeholder = message || 'The session has ended.';
    if (note && message) {
      note.textContent = message;
      note.classList.remove('hidden');
    }
    hideAllTyping();
  }

  // ---- Session timer --------------------------------------------------------

  const timer = (() => {
    const pill = $('timerPill');
    const track = $('timerTrack');
    const fill = $('timerFill');
    const text = $('timerText');
    let startMs = 0;
    let durationMs = 0;
    let tick = null;
    let onEnd = null;

    function fmt(ms) {
      const s = Math.max(0, Math.floor(ms / 1000));
      return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }
    function update() {
      if (!startMs) return;
      const remain = Math.max(0, durationMs - (Date.now() - startMs));
      text.textContent = fmt(remain);
      fill.style.width = Math.max(0, Math.min(100, (remain / durationMs) * 100)) + '%';
      if (remain === 0) {
        pill.classList.add('danger');
        fill.classList.add('danger');
        clearInterval(tick);
        tick = null;
        if (onEnd) { const cb = onEnd; onEnd = null; cb(); }
      }
    }
    return {
      start(ms, endCallback) {
        durationMs = ms;
        onEnd = endCallback || null;
        startMs = Date.now();
        pill.classList.remove('hidden');
        track.classList.remove('hidden');
        update();
        clearInterval(tick);
        tick = setInterval(update, 500);
        document.addEventListener('visibilitychange', update, { passive: true });
      },
      /** Milliseconds left, or Infinity when no timer runs. */
      remainingMs() {
        return startMs ? Math.max(0, durationMs - (Date.now() - startMs)) : Infinity;
      },
    };
  })();

  // ---- Ready gate -----------------------------------------------------------

  const readyGate = (() => {
    const modal = $('readyModal');
    // While the gate is up, the composer behind it is switched off: the modal
    // only blocks the mouse, and the Tab key would still reach the field. The
    // conversation starts when the gate opens, never before.
    if (modal) {
      input.disabled = true;
      sendBtn.disabled = true;
    }
    return {
      present: !!modal,
      /** Wait for the participant's click; then show "finding teammates…". */
      onReady(callback) {
        if (!modal) { callback(); return; }
        $('readyBtn').addEventListener('click', () => {
          $('readyContent').style.display = 'none';
          $('findingContent').style.display = 'block';
          callback();
        }, { once: true });
      },
      release() {
        if (modal) modal.classList.remove('open');
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();
      },
    };
  })();

  return {
    addMessage, showTyping, hideTyping, hideAllTyping, fadeTyping, typingRoles,
    onSend, disableInput, focusInput: () => input.focus(),
    timer, readyGate, scrollToBottom,
    debugPanel: $('debugPanel'),
  };
}
