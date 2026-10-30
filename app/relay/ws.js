/**
 * ws.js — the small part of the WebSocket protocol (RFC 6455) the relay needs.
 *
 * No dependencies, so the relay runs on any Node ≥ 20 without npm install:
 * the handshake key, frame encoding, and an incremental frame parser that
 * handles masking, 16/64-bit lengths, fragmentation and control frames.
 * (Exported for the unit tests in app/tests.)
 *
 * @author Marc Becker
 */

import { createHash, randomBytes } from 'node:crypto';

export const OP = Object.freeze({ CONT: 0x0, TEXT: 0x1, BINARY: 0x2, CLOSE: 0x8, PING: 0x9, PONG: 0xa });

/** Sec-WebSocket-Accept for a client's Sec-WebSocket-Key. */
export function acceptKey(key) {
  return createHash('sha1').update(String(key) + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest('base64');
}

/** One complete (FIN) frame. Servers send unmasked frames; clients (tests) mask. */
export function encodeFrame(opcode, payload = Buffer.alloc(0), mask = false) {
  const data = Buffer.isBuffer(payload) ? payload : Buffer.from(String(payload), 'utf8');
  const len = data.length;
  let header;
  if (len < 126) {
    header = Buffer.alloc(2);
    header[1] = len;
  } else if (len < 65536) {
    header = Buffer.alloc(4);
    header[1] = 126;
    header.writeUInt16BE(len, 2);
  } else {
    header = Buffer.alloc(10);
    header[1] = 127;
    header.writeBigUInt64BE(BigInt(len), 2);
  }
  header[0] = 0x80 | opcode;
  if (!mask) return Buffer.concat([header, data]);
  header[1] |= 0x80;
  const key = randomBytes(4);
  const masked = Buffer.alloc(len);
  for (let i = 0; i < len; i++) masked[i] = data[i] ^ key[i & 3];
  return Buffer.concat([header, key, masked]);
}

/** A close frame with a status code and a short reason. */
export function encodeClose(code = 1000, reason = '') {
  const body = Buffer.alloc(2 + Buffer.byteLength(reason));
  body.writeUInt16BE(code, 0);
  body.write(reason, 2);
  return encodeFrame(OP.CLOSE, body);
}

/**
 * Incremental parser. push(chunk) as bytes arrive; complete messages reach
 * onMessage(opcode, payload) — data messages reassembled from fragments,
 * control frames (close/ping/pong) as they come. Protocol violations call
 * onError(reason) once and stop parsing.
 *
 * options: maxMessageBytes (default 64 KB), requireMask (true for a server:
 * RFC 6455 requires every client frame to be masked).
 */
export function createFrameParser({ onMessage, onError, maxMessageBytes = 64 * 1024, requireMask = true }) {
  let buffer = Buffer.alloc(0);
  let fragments = [];
  let fragmentOpcode = 0;
  let fragmentBytes = 0;
  let failed = false;

  const fail = (reason) => {
    if (failed) return;
    failed = true;
    buffer = Buffer.alloc(0);
    onError(reason);
  };

  return {
    push(chunk) {
      if (failed) return;
      buffer = buffer.length ? Buffer.concat([buffer, chunk]) : chunk;
      while (!failed && buffer.length >= 2) {
        const fin = (buffer[0] & 0x80) !== 0;
        const opcode = buffer[0] & 0x0f;
        const masked = (buffer[1] & 0x80) !== 0;
        let len = buffer[1] & 0x7f;
        let offset = 2;
        if ((buffer[0] & 0x70) !== 0) return fail('reserved bits set');
        if (requireMask && !masked) return fail('unmasked client frame');
        if (len === 126) {
          if (buffer.length < 4) return;
          len = buffer.readUInt16BE(2);
          offset = 4;
        } else if (len === 127) {
          if (buffer.length < 10) return;
          const big = buffer.readBigUInt64BE(2);
          if (big > BigInt(maxMessageBytes)) return fail('frame too large');
          len = Number(big);
          offset = 10;
        }
        if (len > maxMessageBytes) return fail('frame too large');
        const keyLen = masked ? 4 : 0;
        if (buffer.length < offset + keyLen + len) return; // wait for the rest

        let payload = buffer.subarray(offset + keyLen, offset + keyLen + len);
        if (masked) {
          const key = buffer.subarray(offset, offset + 4);
          const unmasked = Buffer.alloc(len);
          for (let i = 0; i < len; i++) unmasked[i] = payload[i] ^ key[i & 3];
          payload = unmasked;
        } else {
          payload = Buffer.from(payload); // detach from the shared buffer
        }
        buffer = buffer.subarray(offset + keyLen + len);

        if (opcode >= 0x8) {
          if (!fin || len > 125) return fail('invalid control frame');
          if (opcode !== OP.CLOSE && opcode !== OP.PING && opcode !== OP.PONG) return fail('unknown control opcode');
          onMessage(opcode, payload);
          continue;
        }
        if (opcode === OP.CONT) {
          if (!fragmentOpcode) return fail('unexpected continuation frame');
        } else if (opcode === OP.TEXT || opcode === OP.BINARY) {
          if (fragmentOpcode) return fail('new message inside a fragmented one');
          fragmentOpcode = opcode;
        } else {
          return fail('unknown data opcode');
        }
        fragmentBytes += len;
        if (fragmentBytes > maxMessageBytes) return fail('message too large');
        fragments.push(payload);
        if (fin) {
          const whole = fragments.length === 1 ? fragments[0] : Buffer.concat(fragments);
          const op = fragmentOpcode;
          fragments = [];
          fragmentOpcode = 0;
          fragmentBytes = 0;
          onMessage(op, whole);
        }
      }
    },
  };
}
