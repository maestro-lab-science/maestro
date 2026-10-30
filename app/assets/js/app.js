/**
 * app.js — shared helpers for every page of MAESTRO (window.AIT).
 *
 * The role colors and the row builders (message rows, typing rows, avatars)
 * that the chat and the data page draw alike, plus toasts, the clipboard,
 * JSON fetch helpers and random ids for the projects page. Loaded as a plain
 * script before the page's own module; no build step.
 *
 * @author Marc Becker
 */
(function () {
  'use strict';

  // ---- Roles ------------------------------------------------------------------
  // Everyone in the chat comes from the project (AIT.setTeam): the teammates
  // and the participant ("human", by default Yellow, shown to themselves as
  // "You"). Each role carries its chosen color plus two derived variants — a
  // tint for bubbles (soft) and a shade for names/text (deep) — computed in HSL
  // with the same formula as includes/team.php, so any color a researcher picks
  // stays readable. Rows receive them as CSS custom properties (see
  // applyRole), which app.css turns into the actual styling. A role may also
  // have a picture (avatar, a data URL) that replaces its initial.
  const HUMAN = { name: 'You', color: '#eab308', soft: '#fdf1bd', deep: '#854d0e', text: '#ffffff', initial: 'Y' };
  const DEFAULT_TEAM = {
    bot1: { name: 'Blue',  color: '#3b82f6' },
    bot2: { name: 'Green', color: '#22c55e' },
    bot3: { name: 'Red',   color: '#ef4444' },
  };
  let ROLES = {};

  function hexToRgb(hex) {
    const h = String(hex || '').replace('#', '');
    return [0, 2, 4].map((i) => parseInt(h.substr(i, 2), 16) / 255);
  }
  function rgbToHex(rgb) {
    return '#' + rgb.map((v) => Math.round(Math.max(0, Math.min(1, v)) * 255).toString(16).padStart(2, '0')).join('');
  }
  function rgbToHsl([r, g, b]) {
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;
    if (max === min) return [0, 0, l];
    const d = max - min;
    const s = d / (1 - Math.abs(2 * l - 1));
    let h;
    if (max === r) h = ((g - b) / d) % 6;
    else if (max === g) h = (b - r) / d + 2;
    else h = (r - g) / d + 4;
    h *= 60;
    if (h < 0) h += 360;
    return [h, s, l];
  }
  function hslToRgb([h, s, l]) {
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = l - c / 2;
    const t = [[c, x, 0], [x, c, 0], [0, c, x], [0, x, c], [x, 0, c], [c, 0, x]][Math.floor(h / 60) % 6];
    return [t[0] + m, t[1] + m, t[2] + m];
  }
  /** The color set derived from one chosen color: { color, soft, deep, text }. */
  function colorVariants(hex) {
    if (!/^#[0-9a-fA-F]{6}$/.test(String(hex || ''))) hex = '#94a3b8';
    if (hex.toLowerCase() === HUMAN.color) return { color: HUMAN.color, soft: HUMAN.soft, deep: HUMAN.deep, text: HUMAN.text }; // the original yellow
    const rgb = hexToRgb(hex);
    const [h, s, l] = rgbToHsl(rgb);
    const lum = 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
    return {
      color: hex.toLowerCase(),
      soft: rgbToHex(hslToRgb([h, s, 0.92])),
      deep: rgbToHex(hslToRgb([h, s * 0.85, Math.min(0.40, Math.max(0.22, l * 0.62))])),
      text: lum > 0.7 ? '#1f2937' : '#ffffff',
    };
  }
  function initialOf(name) {
    const c = String(name || '').trim().charAt(0);
    return c ? c.toUpperCase() : '?';
  }

  /**
   * Install the project's team: { bot1: { name, color, [soft, deep, text, initial, avatar] }, …,
   * human: { … } }. Pages call this once with the map the server rendered; the
   * chat and the data page then draw every row with the right names, colors
   * and pictures. Without a "human" entry the participant is the default Yellow.
   */
  function setTeam(map) {
    ROLES = { human: Object.assign({}, HUMAN) };
    Object.keys(map || {}).forEach((id) => {
      const m = map[id] || {};
      const colors = (m.soft && m.deep) ? { color: m.color, soft: m.soft, deep: m.deep, text: m.text || '#ffffff' } : colorVariants(m.color);
      ROLES[id] = Object.assign({ name: m.name || id, initial: m.initial || initialOf(m.name || id), avatar: m.avatar || '' }, colors);
    });
  }
  setTeam(DEFAULT_TEAM);

  /** Metadata for a role; unknown roles get a neutral gray so old data still renders. */
  function roleMeta(role) {
    return ROLES[role] || Object.assign({ name: role, initial: initialOf(role) }, colorVariants('#94a3b8'));
  }
  /** Put a role's colors on an element as CSS custom properties. */
  function applyRole(node, role) {
    const r = roleMeta(role);
    node.style.setProperty('--role-color', r.color);
    node.style.setProperty('--role-soft', r.soft);
    node.style.setProperty('--role-deep', r.deep);
    node.style.setProperty('--role-text', r.text);
    return node;
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function avatar(role) {
    const meta = roleMeta(role);
    const node = applyRole(el('span', 'avatar avatar-' + role, meta.avatar ? '' : meta.initial), role);
    if (meta.avatar) {
      node.classList.add('has-img');
      node.style.backgroundImage = 'url("' + meta.avatar + '")';
    }
    node.title = meta.name;
    return node;
  }

  /**
   * Build one chat message row (used by the live chat and the data page).
   * opts: { role, text, time (Date|string|null), grouped (bool) }
   */
  function messageRow(opts) {
    const role = opts.role;
    const row = applyRole(el('div', 'msg msg-' + role + (opts.grouped ? ' grouped' : '')), role);

    const slot = el('div', 'avatar-slot');
    if (!opts.grouped) slot.appendChild(avatar(role));
    row.appendChild(slot);

    const body = el('div', 'msg-body');
    if (!opts.grouped) {
      const meta = el('div', 'msg-meta');
      meta.appendChild(el('span', 'msg-name n-' + role, roleMeta(role).name));
      if (opts.time) meta.appendChild(el('span', 'msg-time', formatTime(opts.time)));
      body.appendChild(meta);
    }
    body.appendChild(el('div', 'msg-bubble', opts.text));
    row.appendChild(body);
    return row;
  }

  /** Build a typing-indicator row for a role. */
  function typingRow(role) {
    const row = applyRole(el('div', 'msg msg-' + role + ' typing-row'), role);
    row.dataset.typingRole = role;
    const slot = el('div', 'avatar-slot');
    slot.appendChild(avatar(role));
    row.appendChild(slot);

    const body = el('div', 'msg-body');
    const meta = el('div', 'msg-meta');
    meta.appendChild(el('span', 'msg-name n-' + role, roleMeta(role).name));
    body.appendChild(meta);
    const bubble = el('div', 'msg-bubble typing-bubble');
    for (let i = 0; i < 3; i++) bubble.appendChild(el('span', 't-dot'));
    body.appendChild(bubble);
    row.appendChild(body);
    return row;
  }

  function formatTime(time) {
    let d;
    if (time instanceof Date) {
      d = time;
    } else if (typeof time === 'string') {
      // MySQL "YYYY-MM-DD HH:MM:SS(.mmm)" → keep it simple and local
      const m = time.match(/(\d{2}):(\d{2}):(\d{2})/);
      if (m) return m[1] + ':' + m[2];
      d = new Date(time);
    } else {
      return '';
    }
    if (isNaN(d)) return '';
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }

  // ---- Toasts ---------------------------------------------------------------
  function toast(message, opts) {
    let host = document.getElementById('toastHost');
    if (!host) {
      host = el('div');
      host.id = 'toastHost';
      document.body.appendChild(host);
    }
    const t = el('div', 'toast' + (opts && opts.error ? ' error' : ''), message);
    host.appendChild(t);
    setTimeout(() => {
      t.style.transition = 'opacity .25s';
      t.style.opacity = '0';
      setTimeout(() => t.remove(), 260);
    }, (opts && opts.duration) || 2400);
  }

  // ---- Clipboard --------------------------------------------------------------
  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
    } catch (_) {
      const tmp = el('textarea');
      tmp.value = text;
      document.body.appendChild(tmp);
      tmp.select();
      document.execCommand('copy');
      tmp.remove();
    }
    toast('Copied to the clipboard.');
  }

  // ---- Fetch helpers -------------------------------------------------------------
  async function postJSON(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {}),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.ok === false) {
      throw new Error((data && data.error) || ('The request failed (status code ' + res.status + ').'));
    }
    return data;
  }

  async function getJSON(url) {
    const res = await fetch(url);
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.ok === false) {
      throw new Error((data && data.error) || ('The request failed (status code ' + res.status + ').'));
    }
    return data;
  }

  // ---- Random ids (projects page) --------------------------------------------------
  const ID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  const PW_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  function randomString(length, alphabet) {
    const chars = alphabet || ID_CHARS;
    let out = '';
    const rand = new Uint32Array(length);
    crypto.getRandomValues(rand);
    for (let i = 0; i < length; i++) out += chars.charAt(rand[i] % chars.length);
    return out;
  }

  window.AIT = {
    setTeam, applyRole,
    el, avatar, messageRow, typingRow, toast, copyText, postJSON, getJSON, randomString, ID_CHARS, PW_CHARS,
  };
})();
