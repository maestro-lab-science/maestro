<?php
/**
 * config.local.example.php — template for includes/config.local.php.
 *
 * Copy this file to config.local.php next to it and fill in what applies.
 * config.local.php is loaded before config.php, so every constant defined in
 * it wins over the default there; leave out what you do not need. The real
 * file holds secrets and is excluded from version control (.gitignore) and
 * from deployments (see README, "Production").
 *
 * @author Marc Becker
 */

// ---- Database (defaults: 127.0.0.1:3306, user root without password, aiteam_studio) ----
// define('DB_HOST', '127.0.0.1');
// define('DB_PORT', 3306);
// define('DB_USER', 'root');
// define('DB_PASS', '');
// define('DB_NAME', 'aiteam_studio');

// ---- Server-wide API keys: used when a project stores no key of its own ----
// define('OPENAI_API_KEY', 'sk-...');
// define('OPENAI_MODEL', 'gpt-5-mini');
// define('ANTHROPIC_API_KEY', 'sk-ant-...');
// define('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');

// ---- Default model of the AI Assistant (settings page, Step 3) ----
// define('ASSISTANT_PROVIDER', 'anthropic');
// define('ASSISTANT_MODEL', 'claude-opus-5-5');

// ---- "Custom" provider: an OpenAI-compatible gateway such as Azure API Management ----
// define('CUSTOM_LLM_ENDPOINT', 'https://your-gateway.example.org');
// define('CUSTOM_LLM_KEY', '...');
// define('CUSTOM_LLM_MODEL', 'gpt-5-mini');
// define('CUSTOM_LLM_API_VERSION', '2025-04-01-preview');

// ---- Which server-wide keys every study may use ----
// These keys are prefilled in Step 2 of the settings (readable there) and used when a study
// stores no key of its own. Add 'custom' to share the gateway key and endpoint as well.
// define('SERVER_KEY_PROVIDERS', ['openai', 'anthropic']);

// ---- Data sharing key: data.php?project=…&key=… opens the data page without the password ----
// define('DATA_ACCESS_KEY', 'a-long-random-string');   // secret behind the data share links; without it, no share links

// ---- WebSocket relay (optional; see app/relay/server.js and the README) ----
// define('RELAY_WS_URL', 'ws://localhost:8021/relay');  // '/relay' behind a proxy on this host; '' = off
// define('RELAY_JOB_DIR', '/a/directory/outside/the/web/root/relay_jobs');

// ---- Limits against misuse (includes/limits.php); 0 switches a limit off ----
// define('RATE_DIRECTOR_PER_IP_MIN', 300);   // e.g. for lab sessions with many participants on one network
// define('PASSWORD_MAX_FAILURES', 10);

// ---- Streaming through a web server that buffers output (see includes/llm.php) ----
// define('LLM_SSE_PAD_BYTES', 0);  // 0 on hosts that pass flushes straight through

// Example studies on the projects page: condition slug (includes/examples.php) → project ID
// on this server. Those projects can be read and copied by everyone without a password.
// define('EXAMPLE_PROJECTS', [
//     'safety-high' => 'XXXXXXXXXXXXXXXX', 'safety-low' => 'XXXXXXXXXXXXXXXX',
//     'conflict-intra' => 'XXXXXXXXXXXXXXXX', 'conflict-inter' => 'XXXXXXXXXXXXXXXX',
//     'lead-granted' => 'XXXXXXXXXXXXXXXX', 'lead-withheld' => 'XXXXXXXXXXXXXXXX',
// ]);
