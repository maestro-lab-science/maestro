<?php
/**
 * config.php — central configuration of MAESTRO: the defaults.
 *
 * Local overrides: copy includes/config.local.example.php to
 * includes/config.local.php and define() any of the constants below there (it
 * is loaded first, so its values win). Keep secrets (API keys, the production
 * database password, the data sharing key) in config.local.php only; that
 * file is excluded from version control and from deployments.
 *
 * @author Marc Becker
 */

$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) {
    require $__local;
}

// ---- Database -------------------------------------------------------------
// The application database. Created by install/install.php (see install/schema.sql).
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', 3306);
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'aiteam_studio');

// ---- LLM providers (used by api/director.php — keys never reach the browser) --
// Server-wide fallback keys. Projects can override provider/model/key/endpoint
// per project in the settings page; these are used when a project has no key.
if (!defined('OPENAI_API_KEY')) define('OPENAI_API_KEY', '');
if (!defined('OPENAI_MODEL'))   define('OPENAI_MODEL', 'gpt-5-mini');

if (!defined('ANTHROPIC_API_KEY')) define('ANTHROPIC_API_KEY', '');
if (!defined('ANTHROPIC_MODEL'))   define('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');

// The AI Assistant of the settings page (Step 3) uses its own default model,
// independent of the model the study uses for the conductor: writing and
// checking the texts benefits from a capable model, where speed matters little.
// The researcher can pick another model in the assistant panel.
if (!defined('ASSISTANT_PROVIDER')) define('ASSISTANT_PROVIDER', 'anthropic');
if (!defined('ASSISTANT_MODEL'))    define('ASSISTANT_MODEL', 'claude-opus-5-5');

// "Custom" provider: an OpenAI-compatible gateway (e.g. Azure API Management).
// Endpoint may be a base URL (the Azure deployments path is derived) or a full
// chat-completions URL, optionally containing a {model} placeholder.
if (!defined('CUSTOM_LLM_ENDPOINT')) define('CUSTOM_LLM_ENDPOINT', '');
if (!defined('CUSTOM_LLM_KEY'))      define('CUSTOM_LLM_KEY', '');
if (!defined('CUSTOM_LLM_MODEL'))    define('CUSTOM_LLM_MODEL', 'gpt-5-mini');
if (!defined('CUSTOM_LLM_API_VERSION')) define('CUSTOM_LLM_API_VERSION', '2025-04-01-preview');

// The providers whose server-wide key every study may use: prefilled in Step 2
// of the settings (readable there) and used when a study stores no key of its
// own. The gateway is left out by default, so its key and endpoint are used
// only by studies that store the gateway key themselves, and copies of example
// studies on the gateway switch to OpenAI (includes/examples.php).
if (!defined('SERVER_KEY_PROVIDERS')) define('SERVER_KEY_PROVIDERS', ['openai', 'anthropic']);

// ---- WebSocket relay (app/relay/, see includes/relay.php) --------------------
// URL the browser opens for model streams: absolute ("ws://localhost:8021/relay")
// or a path on this host ("/relay" → wss://<host>/relay). Empty = relay off:
// every model call streams directly from api/director.php (limited by the
// host's HTTP response timeout and PHP connection pool).
if (!defined('RELAY_WS_URL'))  define('RELAY_WS_URL', '');
// Job files handed from api/director.php to the relay's worker. Outside the
// web root; PHP-FPM and the relay service must both see the same directory.
if (!defined('RELAY_JOB_DIR')) define('RELAY_JOB_DIR', rtrim(sys_get_temp_dir(), '/') . '/aiteam_relay_jobs');

// ---- Data sharing key -------------------------------------------------------
// The server secret behind the share links of the data page
// (data.php?project=…&key=…), which open one study's data without its
// password. Each study gets its own key, derived from this secret
// (data_share_key() in includes/db.php), so a link never opens another study.
// Empty = no share links. (Servers configured before the page was renamed
// define REVIEW_ACCESS_KEY; it is still honored.)
if (!defined('DATA_ACCESS_KEY')) define('DATA_ACCESS_KEY', defined('REVIEW_ACCESS_KEY') ? REVIEW_ACCESS_KEY : '');

// ---- Limits against misuse (includes/limits.php) -----------------------------
// The participant link is public, so the conductor calls it can start are
// counted: per conversation, and per network address. A lab session in which
// many participants share one network needs a higher per-address limit.
// 0 switches a limit off.
if (!defined('RATE_DIRECTOR_PER_CONVERSATION_MIN')) define('RATE_DIRECTOR_PER_CONVERSATION_MIN', 12);
if (!defined('RATE_DIRECTOR_PER_CONVERSATION'))     define('RATE_DIRECTOR_PER_CONVERSATION', 300);
if (!defined('RATE_DIRECTOR_PER_IP_MIN'))           define('RATE_DIRECTOR_PER_IP_MIN', 120);
// Wrong passwords (studies and accounts) from one network address: after this
// many within PASSWORD_LOCK_MINUTES, further tries wait until that time is over.
if (!defined('PASSWORD_MAX_FAILURES')) define('PASSWORD_MAX_FAILURES', 10);
if (!defined('PASSWORD_LOCK_MINUTES')) define('PASSWORD_LOCK_MINUTES', 15);

// ---- Example studies (projects page, includes/examples.php) ------------------
// Which project on this server holds which example condition: condition slug
// (see example_studies()) → project ID. Example projects can be read by
// everyone without a password; set this in config.local.php per server.
if (!defined('EXAMPLE_PROJECTS')) define('EXAMPLE_PROJECTS', []);
