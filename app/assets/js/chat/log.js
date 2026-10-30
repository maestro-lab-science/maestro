/**
 * log.js — the decision log.
 *
 * Every notable runtime decision (a line scheduled, a rewrite started, a plan
 * swapped, a save retried) goes through here. It always prints to the browser
 * console; with ?debug=1 it also fills the on-screen panel so a researcher can
 * watch a session unfold without dev tools.
 *
 * @author Marc Becker
 */

const STYLE = {
  event: 'color:#607D8B',
  warn:  'color:#E65100;font-weight:bold',
  script: 'color:#2196F3',
  director: 'color:#9C27B0;font-weight:bold',
};

export function createLog({ panel = null, enabled = false } = {}) {
  if (panel && enabled) panel.style.display = 'block';

  function write(kind, name, data) {
    const suffix = data === undefined ? '' : ' ' + safeJson(data);
    console.log('%c[' + name + ']' + suffix, STYLE[kind] || STYLE.event);
    if (panel && enabled) {
      const div = document.createElement('div');
      div.textContent = '[' + new Date().toLocaleTimeString() + '] ' + name + suffix;
      panel.appendChild(div);
      while (panel.children.length > 300) panel.firstChild.remove();
      panel.scrollTop = panel.scrollHeight;
    }
  }

  return {
    /** A normal runtime event. */
    event: (name, data) => write('event', name, data),
    /** Something unexpected that the runtime handled. */
    warn: (name, data) => write('warn', name, data),
    /** Script playback events. */
    script: (name, data) => write('script', name, data),
    /** Director (rewrite) events. */
    director: (name, data) => write('director', name, data),
  };
}

function safeJson(data) {
  try {
    return typeof data === 'string' ? data : JSON.stringify(data);
  } catch (_) {
    return String(data);
  }
}
