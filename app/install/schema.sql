-- schema.sql — the MAESTRO database.
-- Creates the database and its seven tables: projects, users, user_projects,
-- user_tokens, messages, director_events and rate_events. Every statement is
-- idempotent (IF NOT EXISTS), and install.php adds columns to older databases.
--
-- Standalone usage (e.g. on the production server):
--   mysql -u YOUR_USER -p < schema.sql
--
-- Local usage: run `php install/install.php` instead — it executes this file
-- with the database name from includes/config.php.
--
-- Author: Marc Becker

CREATE DATABASE IF NOT EXISTS aiteam_studio
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE aiteam_studio;

-- One row per project (formerly "study").
CREATE TABLE IF NOT EXISTS projects (
  project_id               VARCHAR(16)  NOT NULL PRIMARY KEY,
  name                     VARCHAR(80)  NOT NULL DEFAULT '',
  password_hash            VARCHAR(255) NOT NULL,
  participant_instructions TEXT         NOT NULL,
  show_instructions        TINYINT(1)   NOT NULL DEFAULT 0,
  director_instructions    MEDIUMTEXT   NOT NULL,
  -- director_sections: the same instructions as the sections the settings page
  -- edits (goal, style, team, situation, manip, notes, plus a text per actor),
  -- see includes/director_sections.php. director_instructions holds the text
  -- assembled from the sections. NULL = saved before the sections existed.
  director_sections        MEDIUMTEXT   NULL,
  -- conversation_script: the sample conversation {"messages":[…]}, optionally
  -- divided into segments ("segments":[{"start":300,"prompt":"…"}, …], see
  -- includes/segments.php).
  conversation_script      MEDIUMTEXT   NOT NULL,
  -- study_context: background about the study (research idea, concepts,
  -- preferences such as the language of the chat). Sent to the settings-page
  -- assistants only; never to the director, and participants never see it.
  study_context            TEXT         NULL,
  -- team_json: everyone in the chat, in display order, as
  -- [{"id":"bot1","name":"Blue","color":"#3b82f6","avatar":"data:image/…"}, …,
  --  {"id":"human","name":"Yellow","color":"#eab308"}]
  -- 1–10 AI teammates (bot1 … bot10) plus exactly one "human" entry, the real
  -- participant (see includes/team.php). Avatars are small data URLs.
  -- NULL = the default trio Blue/Green/Red and the participant Yellow.
  team_json                MEDIUMTEXT   NULL,
  show_ready_modal         TINYINT(1)   NOT NULL DEFAULT 0,
  ready_wait_seconds       INT          NOT NULL DEFAULT 22,
  timer_enabled            TINYINT(1)   NOT NULL DEFAULT 0,
  timer_duration_seconds   INT          NOT NULL DEFAULT 600,
  -- chat_instructions: formatted instructions for participants, shown above the
  -- chat (between the timer and the messages) while show_chat_instructions = 1
  -- and the text is not empty. Stored as the small HTML subset that
  -- includes/richtext.php produces (paragraphs, lists, bold, italic,
  -- underlined, size, color).
  show_chat_instructions   TINYINT(1)   NOT NULL DEFAULT 0,
  chat_instructions        MEDIUMTEXT   NULL,
  -- is_paused: 1 = new participants see "this study is not active" instead of
  -- the chat (test links keep working); paused_at = since when.
  is_paused                TINYINT(1)   NOT NULL DEFAULT 0,
  paused_at                DATETIME     NULL,
  api_provider             VARCHAR(16)  NOT NULL DEFAULT 'openai',
  model                    VARCHAR(64)  NOT NULL DEFAULT 'gpt-5-mini',
  -- api_format: how requests are shaped for OpenAI-style providers.
  -- 'auto' = chat completions (Responses only where reasoning summaries are
  -- needed), 'chat' = always chat completions, 'responses' = always the
  -- Responses API (streams through gateways that buffer chat completions).
  api_format               VARCHAR(16)  NOT NULL DEFAULT 'auto',
  -- director_effort: thinking effort for the live director (rewrites during a
  -- participant chat) — it is the whole response delay, so it is a project setting.
  -- reasoning_effort: server-side fallback for the settings-page AI assistants;
  -- the assistant panels send their own choice with every request.
  reasoning_effort         VARCHAR(16)  NOT NULL DEFAULT 'medium',
  director_effort          VARCHAR(16)  NOT NULL DEFAULT 'minimal',
  api_endpoint             VARCHAR(255) NOT NULL DEFAULT '',
  api_key                  VARCHAR(512) NOT NULL DEFAULT '',
  -- key_test: the last API key test of the settings page as JSON (when it ran,
  -- whether it worked, what was tested; see includes/key_test.php). Written by
  -- api/test_key.php at once, without Save. NULL = never tested.
  key_test                 TEXT         NULL,
  -- assistant_keys: the AI Assistant's own API keys per provider, separate from
  -- api_key, with the last test of each key, as JSON (see includes/assistant_keys.php):
  -- {"openai":{"key":"…","test":{…}},"anthropic":{…}}. Never sent to the browser.
  assistant_keys           TEXT         NULL,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional researcher accounts. An account is purely a convenience layer that
-- stores and organizes project credentials — projects stay fully usable and
-- shareable without one.
CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(255) NOT NULL,
  display_name  VARCHAR(80)  NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Projects kept in an account. project_password is the stored credential
-- (plaintext, like a password manager entry) so any signed-in device can open
-- the project without retyping it. Deleting a row removes the project from
-- that account only — the project itself is untouched.
CREATE TABLE IF NOT EXISTS user_projects (
  user_id          BIGINT UNSIGNED NOT NULL,
  project_id       VARCHAR(16) NOT NULL,
  project_password VARCHAR(255) NOT NULL,
  added_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, project_id),
  KEY idx_project (project_id),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_up_project FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Stay signed in" tokens (selector public, validator stored hashed).
CREATE TABLE IF NOT EXISTS user_tokens (
  selector   CHAR(16) NOT NULL PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every message shown on screen during a conversation (bots and the human).
-- client_id is generated in the browser per message; the unique key makes
-- retried saves idempotent (a retry after a flaky connection never duplicates).
CREATE TABLE IF NOT EXISTS messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id      VARCHAR(16) NOT NULL,
  conversation_id VARCHAR(64) NOT NULL,
  client_id       VARCHAR(40) NULL,
  sender          VARCHAR(16) NOT NULL,             -- 'human' or a teammate id bot1 … bot10
  shown_at        DATETIME(3) NOT NULL,
  message_text    MEDIUMTEXT  NOT NULL,
  created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_project_conversation (project_id, conversation_id, shown_at),
  KEY idx_project_time (project_id, shown_at),
  UNIQUE KEY uq_client_message (project_id, conversation_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per director call (rewrites during a chat): what ran, how long it
-- took, how it ended. This is the latency and reliability telemetry — nothing
-- the participant sees, everything the researcher needs to trust a session.
-- message_client_id links the call to the participant message that triggered
-- it (messages.client_id), so the data page can flag unanswered messages.
CREATE TABLE IF NOT EXISTS director_events (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id        VARCHAR(16) NOT NULL,
  conversation_id   VARCHAR(64) NOT NULL DEFAULT '',
  message_client_id VARCHAR(40) NULL,
  mode              VARCHAR(16) NOT NULL,
  provider          VARCHAR(16) NOT NULL,
  model             VARCHAR(64) NOT NULL,
  effort            VARCHAR(16) NOT NULL,
  status          VARCHAR(16) NOT NULL,             -- ok | error | aborted (superseded) | client-error (never reached the relay)
  first_token_ms  INT NULL,                         -- time to the model's first output token
  duration_ms     INT NOT NULL DEFAULT 0,
  output_chars    INT NOT NULL DEFAULT 0,
  -- Token usage as reported by the provider (NULL = not reported, e.g. calls
  -- recorded before token counting existed). input_tokens includes the cached
  -- part; cached_tokens were read from the provider's prompt cache (cheaper),
  -- cache_write_tokens were written to it (billed extra on some models).
  input_tokens       INT NULL,
  output_tokens      INT NULL,
  cached_tokens      INT NULL,
  cache_write_tokens INT NULL,
  output_json     TEXT NULL,                        -- the plan the model returned (audit trail)
  error           VARCHAR(255) NOT NULL DEFAULT '',
  created_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_project_time (project_id, created_at),
  KEY idx_conversation (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counted requests, to stop misuse (includes/limits.php): conductor calls per
-- conversation and per network address, and wrong passwords. bucket names
-- what is counted; addresses and ids appear only as hashes, and rows older
-- than a day are deleted.
CREATE TABLE IF NOT EXISTS rate_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bucket     VARCHAR(64) NOT NULL,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bucket_time (bucket, created_at),
  KEY idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
