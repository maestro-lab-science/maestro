/**
 * json-stream.js — reading JSON that a model streams in pieces.
 *
 * The AI Assistant (settings-assistants.js) shows a box text while the model
 * still writes it: the text arrives inside {"text":"…","note":"…"}, a few
 * characters at a time. Plain module without DOM access, so the unit tests
 * (tests/json-stream.test.mjs) can import it.
 *
 * @author Marc Becker
 */

/**
 * Reads the value of one string key ("text") from JSON that arrives in pieces,
 * un-escaping as it goes. feed(delta) returns the new characters of the value
 * (or '' while the key has not started); done() says whether the value ended.
 */
export function createJsonStringReader(key) {
  let buf = '';
  let inValue = false;
  let ended = false;
  let esc = false;
  let uni = null;
  const needle = '"' + key + '"';
  function feed(delta) {
    if (ended) return '';
    buf += delta;
    if (!inValue) {
      const k = buf.indexOf(needle);
      if (k === -1) {
        if (buf.length > 4000) buf = buf.slice(-needle.length - 4); // keep a tail for a split key
        return '';
      }
      const colon = buf.indexOf(':', k + needle.length);
      if (colon === -1) return '';
      const q = buf.indexOf('"', colon + 1);
      if (q === -1) return '';
      inValue = true;
      buf = buf.slice(q + 1);
    }
    let emit = '';
    let i = 0;
    while (i < buf.length) {
      const ch = buf[i];
      if (uni !== null) {
        uni += ch;
        if (uni.length === 4) {
          const code = parseInt(uni, 16);
          emit += Number.isNaN(code) ? '?' : String.fromCharCode(code);
          uni = null;
        }
        i++;
        continue;
      }
      if (esc) {
        esc = false;
        if (ch === 'n') emit += '\n';
        else if (ch === 't') emit += '\t';
        else if (ch === 'r') { /* dropped */ }
        else if (ch === 'u') uni = '';
        else emit += ch;
        i++;
        continue;
      }
      if (ch === '\\') { esc = true; i++; continue; }
      if (ch === '"') { ended = true; i++; break; }
      emit += ch;
      i++;
    }
    buf = buf.slice(i);
    return emit;
  }
  return { feed, done: () => ended };
}
