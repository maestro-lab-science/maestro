/**
 * config.js — runtime configuration for the participant chat.
 *
 * Two sources: the settings the server injected into the page
 * (window.CHAT_CONFIG, see aiTeam.php) and the URL (conversation id, debug).
 * The few timing constants the runtime itself needs live here too; the
 * timing of the conversation is decided by the stored script and the
 * director, never by the runtime.
 *
 * @author Marc Becker
 */

/** Timing constants (milliseconds). */
export const TIMING = Object.freeze({
  /**
   * When the participant writes while a teammate is typing, that teammate
   * pauses: the dots disappear after a random delay in this range (as if they
   * stopped to read) …
   */
  INTERRUPT_HIDE_MIN_MS: 1000,
  INTERRUPT_HIDE_MAX_MS: 3000,
  /** … and they type again no sooner than this long after the dots went, if the new plan says so. */
  INTERRUPT_RESUME_MIN_MS: 700,
  INTERRUPT_RESUME_MAX_MS: 1500,
  /** Give up on the director's first line after this long (one retry follows; then nothing is shown). */
  FIRST_TOKEN_TIMEOUT_MS: 25000,
  /** Hard cap for one rewrite stream. */
  STREAM_TIMEOUT_MS: 160000,
  /** Messages from the same sender within this span are visually grouped. */
  GROUP_WITHIN_MS: 90000,
});

/**
 * The team, from the project's settings (window.CHAT_CONFIG.team). Filled by
 * loadConfig(); the other modules read it from here.
 *   roles        teammate ids in order, e.g. ['bot1', 'bot2', 'bot3']
 *   promptNames  id → the name the director sees (upper case; the human is YELLOW unless renamed)
 *   codes        every accepted sender spelling (bot2, b2) → id
 *   map          id → { name, color, soft, deep, text, initial } for the UI (incl. human)
 */
export const TEAM = { roles: [], promptNames: { human: 'YELLOW' }, codes: {}, map: {} };

const DEFAULT_TEAM = {
  bot1: { name: 'Blue',  color: '#3b82f6' },
  bot2: { name: 'Green', color: '#22c55e' },
  bot3: { name: 'Red',   color: '#ef4444' },
};

/** Teammate id for a sender code from a script or the director (null if not in the team). */
export function senderFromCode(raw) {
  return TEAM.codes[String(raw || '').trim().toLowerCase()] || null;
}

/**
 * Install a role map (id → { name, promptName?, … }, see includes/team.php
 * team_roles_map). loadConfig() does this for the chat; the settings page uses
 * it too, to describe a draft script for its token estimate.
 */
export function configureTeam(map) {
  const source = map && Object.keys(map).some((id) => /^bot\d+$/.test(id)) ? map : DEFAULT_TEAM;
  const ids = Object.keys(source).filter((id) => /^bot(10|[1-9])$/.test(id))
    .sort((a, b) => Number(a.slice(3)) - Number(b.slice(3)));
  TEAM.roles = ids;
  TEAM.map = Object.assign({}, source);
  // The participant's name in the prompts (the chat itself shows "You").
  const human = source.human && (source.human.promptName || '');
  TEAM.promptNames = { human: String(human || 'YELLOW') };
  TEAM.codes = {};
  ids.forEach((id) => {
    TEAM.promptNames[id] = String(source[id].promptName || source[id].name || id).toUpperCase();
    TEAM.codes[id] = id;
    TEAM.codes['b' + id.slice(3)] = id;
  });
}

/**
 * Read the configuration. The conversation id comes from the URL:
 *   ?conversationID=<id>  → used as is (e.g. a Qualtrics response id)
 *   ?conversationID=TEST  → TEST_<random>, easy to filter out on the data page
 *   (none)                → conv_<random>
 *   an unfilled placeholder (the link opened outside the survey tool, so
 *   ${e://Field/…}, {{%PROLIFIC_PID%}} or {SAVEDID} is still there as text)
 *                         → conv_<random> as well, so such conversations do
 *                           not all share one ID. Real IDs never contain braces.
 * The final id is written back into the URL so a reload keeps the same session.
 */
export function loadConfig() {
  const injected = window.CHAT_CONFIG || {};
  const params = new URLSearchParams(window.location.search);

  // The server's rules (sanitize_conversation_id): no control characters, at
  // most 64 characters. Otherwise every save is refused, or stored under another id.
  let conversationId = Array.from((params.get('conversationID') || '').replace(/[\u0000-\u001F\u007F]/g, '').trim()).slice(0, 64).join('');
  if (!conversationId || /[{}]/.test(conversationId)) {
    conversationId = 'conv_' + randomSuffix(13);
  } else if (conversationId.toUpperCase() === 'TEST') {
    conversationId = 'TEST_' + randomSuffix(8);
  }
  if (conversationId !== params.get('conversationID')) {
    params.set('conversationID', conversationId);
    const url = window.location.pathname + '?' + params.toString() + window.location.hash;
    window.history.replaceState({ path: url }, '', url);
  }

  configureTeam(injected.team);

  return Object.freeze({
    projectId: String(injected.projectId || ''),
    conversationId,
    /** The stored script, already parsed by the server ({messages:[…], segments?:[{start}]} or an array). */
    script: injected.script || null,
    /** false → no director instructions: the script plays, participant messages are only recorded. */
    hasDirector: !!injected.hasDirector,
    readyModal: !!injected.readyModal,
    readyWaitMs: Math.max(0, Number(injected.readyWaitMs) || 0),
    timerEnabled: !!injected.timerEnabled,
    timerDurationMs: Math.max(0, Number(injected.timerDurationMs) || 0),
    /** Whether the composer locks when the timer runs out (a study-design choice; off by default). */
    lockInputAtTimerEnd: !!injected.lockInputAtTimerEnd,
    /** Safety cap on bubbles per session (participant + team). */
    maxMessages: Math.max(20, Number(injected.maxMessages) || 200),
    /** The role map for the UI (same object as TEAM.map). */
    team: TEAM.map,
    debug: params.get('debug') === '1',
  });
}

function randomSuffix(length) {
  const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
  const bytes = new Uint8Array(length);
  crypto.getRandomValues(bytes);
  let out = '';
  for (const b of bytes) out += alphabet[b % alphabet.length];
  return out;
}
