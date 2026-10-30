/**
 * main.js — entry point of the participant chat.
 *
 * Wires the modules together. Read them in this order to understand the
 * runtime: config → script → scheduler → director → session.
 *
 * @author Marc Becker
 */

import { loadConfig } from './config.js';
import { createLog } from './log.js';
import { createUI } from './ui.js';
import { createClock } from './clock.js';
import { createScheduler } from './scheduler.js';
import { createDirectorClient } from './director.js';
import { createSaveQueue } from './persistence.js';
import { createSession } from './session.js';

const config = loadConfig();
const ui = createUI({ team: config.team });
const log = createLog({ panel: ui.debugPanel, enabled: config.debug });
const clock = createClock();
const saves = createSaveQueue({ projectId: config.projectId, conversationId: config.conversationId, log });
const director = createDirectorClient({ projectId: config.projectId, conversationId: config.conversationId, log });

let session = null;
const scheduler = createScheduler({ clock, ui, log, onDeliver: (line, atMs) => session.deliverLine(line, atMs) });
session = createSession({ config, ui, clock, log, scheduler, director, saves });
session.start();
