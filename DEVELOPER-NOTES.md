# Developer notes

How MAESTRO works inside: the pages, the folder layout, the participant chat, the settings page
and the database. To install MAESTRO, see the [self-hosting instructions](SELF-HOSTING.md).
Back to the [README](README.md).

MAESTRO is plain PHP and JavaScript (ES modules, no build step) with MySQL or MariaDB and an
optional Node.js relay: no framework and nothing to install. In the code and the database, the
conductor is still called the **director**, and the AI actors are the **teammates**
(`bot1` … `bot10`).

## Pages

| Page | Purpose |
|---|---|
| `projects.php` | The studio entry page: create a new project or open an existing one (both in a dialog), a "Getting started" video tile, the optional account (sign in / create), and three example studies from the paper (`includes/examples.php`, `includes/portal_examples.php`): the play button opens a condition's test chat, the cog opens its settings as the visitor's own draft copy (`settings.php?example=<ID>`) |
| `settings.php` | Per-project setup in four steps: 1 study setup (name, study context, session flow), 2 AI model (provider, model with its price, response speed, API key with a test button), 3 conversation (the cards Situation, AI actors, Dynamics and Conductor, and the sample conversation, with one AI Assistant docked beside the cards), 4 launch (readiness check with pause/continue, costs, participant link, or the same link as embed code for a survey question, and a dark "Collected data" card with the counts so far that opens the data page). The top bar shows the study status and has "View data", "Add collaborator" and "Duplicate". Signed out, the banner's "Create an account" and "Sign in" open a dialog on the page itself: it saves open changes, creates the account or signs in, adds the study to it and reloads on the same step. `?example=<ID>` (an example study from `EXAMPLE_PROJECTS`) shows the same page as a **draft copy**: every setting except the example's API key (Step 2 is prefilled with the server's default key, as for any new study) under a new project ID named "Copy of …" (an example on a provider outside `SERVER_KEY_PROVIDERS`, such as the gateway, is copied onto OpenAI with the same model), open to everyone; nothing is stored until the first Save, which creates the project with a fresh password (shown once in a dialog), unlocks it in the session and adds it to a signed-in account. Until then the key test, the AI Assistant and the top-bar actions are off, and the test links open the example's chat |
| `aiTeam.php` | The participant chat (`?project=<ID>`; `?studyID=` still works) |
| `data.php` | The data of a project: browse and export recorded conversations — CSV, rendered PDF transcripts, participant overview, or a complete ZIP archive incl. project configuration — plus a **Costs** dialog (built like the costs card of Step 4) for the whole study and per conversation. (Formerly `review.php`, which now forwards here so old shared links keep working.) |

Role colors are central and consistent everywhere. By default the participant is
**Yellow** and the three scripted teammates are **Blue** (`bot1`), **Green** (`bot2`) and
**Red** (`bot3`); every project can rename everyone, change colors, add pictures, and decide
which person in the list is the real participant (Step 3 of the settings).

## Folder layout

```
/                   the repository root is the web root
├── package.json    `npm test` (the unit tests) and `npm run relay`; nothing to install
└── app/            the studio itself
    ├── aiTeam.php            participant chat — a thin shell, all behavior in assets/js/chat/
    ├── settings.php / data.php / projects.php
    ├── api/
    │   ├── director.php      the relay between browsers and language models (rewrite, assist_plan / assist_field / assist_review)
    │   ├── save_message.php  idempotent, batched message persistence
    │   ├── test_key.php      one tiny model call to check an API key (settings, Step 2); stores the outcome
    │   ├── assistant_keys.php   the AI Assistant's own API keys: save, import from Step 2, remove (each tested at once)
    │   ├── study_status.php  pause / continue a study (settings, Step 4)
    │   ├── duplicate_project.php   copy a study with all settings and no data
    │   ├── save_project.php  store the settings; with draft_from it first creates a visitor's copy of an example study
    │   └── auth.php, account.php, data.php (data + exports of the data page)
    ├── assets/
    │   ├── css/app.css, css/chat.css, css/settings.css
    │   ├── js/app.js         shared helpers (message rows, avatars, toasts, fetch)
    │   ├── js/stream.js      AIT.modelStream — model events via the WebSocket relay or directly
    │   ├── js/settings.js    the settings page (ES module): steps, editors, costs, links, dialogs, save
    │   ├── js/settings-assistants.js   the AI Assistant of Step 3: the docked panel, the run with its checklist, the API key dialog
    │   ├── js/json-stream.js reads a string value out of JSON that a model streams in pieces
    │   ├── js/estimate.js    token and cost estimate of a draft setup (shared with the tests)
    │   ├── js/spending.js    what a study has cost so far: shared by Step 4 of the settings and the data page
    │   ├── js/format.js      how money, tokens and counts are written (both pages)
    │   ├── js/richtext.js    formatted text: the editor for the instructions above the chat, and its cleaner
    │   └── js/chat/          the participant runtime (ES modules, no build step)
    │       config → script → scheduler → director → session (main.js wires them)
    ├── includes/
    │   ├── llm.php           provider resolution, wire formats, streaming relay (one place for every model call)
    │   ├── director.php      live-director prompt + director_events telemetry
    │   ├── director_sections.php  the conductor instructions as sections (goal, situation, team, bots, manip, rewrite, tech) and the assembled prompt
    │   ├── segments.php      segments of a sample conversation: hard cuts at fixed times (validation)
    │   ├── assist.php        the AI Assistant of Step 3: prompts and answers of plan, one call per box, review
    │   ├── assistant_keys.php   which API key the AI Assistant uses (own key → Step 2 key → server key)
    │   ├── team.php          everyone in the chat: teammates, the participant, colors, pictures
    │   ├── richtext.php      formatted text (instructions above the chat): the cleaner that decides what is stored and shown
    │   ├── pricing.php       model list, list prices, token estimates (the one place to update prices)
    │   ├── usage.php         tokens and costs per conversation and for the whole study
    │   ├── key_test.php      the last API key test of a project (stored at once, shown in the settings)
    │   ├── relay.php         model jobs + the hand-off to the WebSocket relay
    │   ├── limits.php        counted requests against misuse: conductor calls per conversation and network address, wrong passwords
    │   ├── pdf.php, pdf_font.php   PDF transcripts with embedded Noto fonts (fonts/: Noto Sans, Noto Emoji, OFL)
    │   ├── db.php, account.php, unlock.php, portal_forms.php, portal_examples.php, config.php
    │   ├── examples.php      the example studies of the paper (titles, conditions) and the draft-copy helpers; EXAMPLE_PROJECTS in config.local.php maps condition → project ID
    │   └── config.local.php  secrets and machine settings (git-ignored; template: config.local.example.php)
    ├── relay/      the WebSocket relay: server.js (Node, no dependencies) + worker.php (CLI)
    ├── install/    schema.sql + install.php (idempotent migrations)
    └── tests/      unit tests, `node --test app/tests`
```

All links inside the studio are relative, so the studio runs at `/app/…` of any server, also
in a subdirectory.

## How the participant chat works

The runtime in `app/assets/js/chat/` is built around one idea: **the director decides, the
runtime only plays.** The stored script plays on its own schedule. When the participant
writes, the plan is outdated: every unsent line is dropped, and the director — a language
model reached through `api/director.php` — is asked for the entire remaining conversation.
It sees three lists, all in one convention, "+Ns" = seconds after the previous line: what
was said so far, the whole script (the intended course of the conversation), and the lines
that were still planned. It answers with compact JSON, one object per line with a pause and
a typing time (`{"s":"b2","c":"…","p":2,"d":1}` = GREEN, text, 2 s after the previous
message, 1 s of typing), and the list is the whole rest of the conversation. Lines are
placed as they stream in: the first at the participant's message plus its pause, each next
at the previous line plus its pause; a line whose moment has already passed (a slow model)
appears as soon as its typing time has been visible. Nothing else touches the timing — no
minimum gaps, no re-pacing — except one content guard: a line that copies a message already
shown is dropped and logged, because models do that now and then. A teammate who was typing
when the participant wrote pauses like a person who stops to read: the dots go after one to
three seconds, and that teammate types again only when the new plan says so, and not within
the first second or so after the dots went. An empty list means the team is done.

A second participant message while a reply is still streaming aborts it (the relay records
the call as `aborted`, the data page shows the message as superseded). The lines that had
arrived are the plan the next request sees — nothing is patched, nothing is flagged.

Every director call is recorded in `director_events` (provider, model, effort, first-token
latency, duration, the returned plan) so sessions can be audited afterwards. Messages are
saved in batches with a client-generated id and a unique key, so retries after a flaky
connection never duplicate rows.

Thinking effort is set in two places: **Team response speed** (`director_effort`, default
`minimal`) in Settings → AI model for the live director — every second of model thinking is
silence in the chat — and, for the AI Assistant of Step 3, the effort tag inside the
assistant panel (default `medium`, remembered per project in the browser and sent with every
request; the `reasoning_effort` column is only the server-side fallback). "Minimal" is sent
as whatever the model calls its lowest setting (`minimal` on gpt-5 / gpt-5-mini, `none` on
gpt-5.1 and newer); if a model still rejects the value, the relay reads the provider's list
of supported values and retries once.

Typing indicators are one per teammate: the dots come on at a line's typing start and go
off when its bubble appears — unless that teammate has another line already being typed. A
row that is fading is taken back when the same teammate starts again, so two indicators of
one color never show at once.

**Request format.** OpenAI-style providers are reached through two request shapes:
chat completions, the format nearly every provider and gateway implements, and OpenAI's
newer Responses API. Settings → AI model → Request format picks one per project.
"Automatic" sends chat completions, and the Responses API only where streamed reasoning
summaries are needed. Pick "Responses API" when a gateway buffers chat completions: the
university's Azure API Management gateway delivers a whole answer at once on that route,
while its `/openai/v1/responses` route streams normally. Anthropic speaks its own format
and ignores the setting. Stored in `projects.api_format`; requests on the Responses route
are sent with `store: false`.

**The team is configurable.** Settings → Conversation → Conductor instructions → Participants: one to ten AI actors, each with a name,
a color and, if wanted, instructions for that actor only (default: Blue, Green, Red). Their ids `bot1`…`bot10` are what scripts, the director
and the saved data use; names and colors are display only, so renaming never breaks a script.
Stored in `projects.team_json` (`NULL` = the default trio); see `includes/team.php`, which
also derives the bubble tint and the text shade from each chosen color.

**The conductor instructions are edited in sections** (Settings → Conversation; `includes/director_sections.php`):
four cards in a fixed order, Situation (task and background), AI actors (how the actors write and behave,
what the actors have in common, plus a text per actor), Dynamics (the manipulation in a multi-condition study)
and Conductor (purpose, rewrite rules, and the output format behind an expert link). Every box offers suggested instructions that one click appends as a paragraph. The texts per actor form a
block of their own in the prompt (one header line per actor). The order of the boxes in the prompt is separate
from the cards: the "Full prompt" card shows a map of the whole prompt, including
the parts the software adds, and an expert link lets the researcher reorder the boxes there. Sections of
earlier shapes (style, conversation, guardrails) fold into the AI actors box when such a project is loaded. The sections are stored as JSON in
`projects.director_sections`; `projects.director_instructions` keeps the prompt assembled from them
(sections in the stored order, empty sections skipped, one blank line between them), so the director,
the exports and the copies read one text as before. A project saved before the sections existed shows
its whole text in the first section.

**When the director fails**, it is retried once; after that nothing is planned any more —
the dots disappear and the team stays silent until the participant writes again. Nothing is
invented, and the failure is visible: the relay records every call in `director_events`
(linked to the message via `message_client_id`), the browser reports the requests that never
reached the relay, and the data page marks such messages with a note, counts them per
conversation, and exports them (`director_status` / `director_error` columns,
`director_events.csv` in the archive).

**A session ends by itself.** Nothing locks: when the script is played out, or when the
director answers with an empty list (`{"m":[]}` — it knows the elapsed time and the timer,
so the instructions can say "after ten minutes, return an empty list"), the team simply says
nothing more. For an end at a set time, a segment is more reliable (for example one starting at
9:30 with the prompt "The team says goodbye in one or two short lines and then stops writing.").

**Segments** (Settings → Conversation → sample conversation; `includes/segments.php`). A
sample conversation can be divided into segments: important events, hard cuts where the
conversation changes a lot (a teammate has to leave, new information arrives, the deadline
moves). Each segment has a start time and a prompt of 1 to 3 sentences saying what changes;
the director instructions still apply. Why: the director only plans when the participant
writes, and it plans in pauses, so it cannot keep a fixed moment by itself. Every reaction it
adds pushes the rest of the plan later, and a quiet participant never gives it a chance to
correct that. (Found on 2026-09-24 in a study where Blue leaves for an urgent email at 5:00:
in the recorded conversations the email line came at 6:19 and 6:21 and Blue kept talking in
the time away; replaying the director's calls gave the same in 18 of 18 runs.)

How segments behave:
- Until the participant writes for the first time, the sample conversation plays as written,
  segments included.
- Once the director has taken over, each segment start is a hard cut: whatever was planned is
  dropped, and the director is called with the new segment in place of a participant message
  (`session.js` `segmentReached`, payload field `segment`). Every director plan is cut at the
  next segment start, so nothing of a later segment (a teammate coming back, say) appears
  early. A start that passed while the tab was hidden is handled when the participant returns,
  after the lines planned before it.
- Every prompt of a divided project shows a divider in the lists where each segment starts
  (the first line after it counts from the segment's start) and lists all segments with their
  span, prompt and status ("current, started 32s ago", "starts in 180s"), and asks the director
  to plan only up to the next start.
- Stored in the sample conversation itself: `"segments": [{"start": 0, "prompt": ""},
  {"start": 300, "prompt": "…"}]` plus a `segment` number on every message, so segments are
  edited, exported and duplicated with it, and the AI Assistant keeps (and can
  write) them. It writes the segments before the messages, so while it streams, each segment's
  header appears as its first message arrives; a "✂️ Add a segment" suggestion fills in a template. In the editor the first message of a segment counts its pause from the segment's
  start, so edits in one segment never move the next; a message that would come at or after
  the next segment's start is cut (crossed out in the editor, never played). The chat page
  receives the start times only; the prompts stay on the server.
- Calls at segment starts are logged in `director_events` with mode `segment` and
  `message_client_id` `segment-<number>` (`director_events.csv` has a `mode` column), and the
  data page flags a segment start whose call failed. Projects without segments get exactly the
  prompt they had before segments existed (checked byte for byte).

The director always gets every message said so far (until 2026-09-25 only the last 60).

**Hidden tabs:** the conversation and the timer keep running on wall-clock time. When the
participant comes back, whatever came due meanwhile is shown at once with its original
timestamps — like a chat app that was in the background.

**Embedding in a survey.** The participant link is meant to be opened inside a survey, for
example as an iframe in a Qualtrics question (with the conversation ID placeholder from
Step 4), and that is explicitly allowed: `aiTeam.php` sends
`Content-Security-Policy: frame-ancestors *`, which takes precedence over any
`X-Frame-Options` a web server might add, and the chat page and its two endpoints
(`api/director.php`, `api/save_message.php`) set no cookie, open no session and use no
browser storage, so the chat also works inside a third-party frame in browsers that block
third-party cookies and storage. The WebSocket relay sees the chat's own origin even when
the page is embedded, so `RELAY_ORIGINS` needs no survey domains.
`app/tests/embed.test.mjs` checks the headers through PHP's built-in server. Keep it that
way: never start a session or touch local storage on the participant side.

**How model streams travel.** Every model call (the live director and both settings
assistants) is prepared by `api/director.php` — validation, the unlocked-session check for
the assistants, provider resolution, the prompt. With the relay configured (`RELAY_WS_URL`),
PHP then stores the prepared call as a one-time job file and returns a token; the browser
(`assets/js/stream.js`) sends the token over one WebSocket per page to the relay
(`app/relay/server.js`), which starts `php app/relay/worker.php <token>` and forwards its
events. The worker runs the very same code as the direct path (`run_model_job` in
`includes/relay.php`), so prompts, retries, effort fallback, `director_events` logging and
the assistants' script checks and summaries are identical. Cancelling (a second participant
message, closing the page) closes the worker's stdin; PHP stops the upstream call and
records it as `aborted`. Why: the production host cuts every HTTP response after 45 seconds
(which broke long assistant generations) and a streaming PHP request holds one of the web
server's few PHP connections; WebSocket connections are not cut and relay workers run outside
the web server. Without the relay, or when it cannot be reached (the browser then falls back
for a minute), the same events stream directly as Server-Sent Events from `api/director.php`.

Each call is bounded twice (`includes/llm.php`): a hard cap — `LLM_STREAM_S` 150 s for the
live director, `LLM_ASSIST_S` 600 s for the assistants, where writing a whole script can
take several minutes — and `LLM_STALL_S` (90 s), which ends a connection that has gone
completely quiet long before the hard cap. Hitting either is reported in plain words
("the model was still writing after 10 minutes …"), not as a cURL message. Every assistant
exchange is recorded in `director_events` too (modes `assist_plan`, `assist_field`,
`assist_review`; no content),
so a failed generation is visible in the data.

Every test run of the chat is easy to inspect and clean up: open the participant page with
`?conversationID=TEST&debug=1` — the id becomes `TEST_xxxxxxxx` and a decision-log panel
shows what the scheduler and director did (rewrite requested/connected/first line, plan
dropped, lines scheduled, typing bridge, errors).

## The settings page

`settings.php` walks a researcher through four steps (the current step is kept in the URL,
`#step1` … `#step4`, and in the browser). Every card starts with a short explanation;
details sit behind the small **?** buttons. Nothing is stored until **Save** (or Cmd/Ctrl+S),
except pausing and duplicating, which act at once. The sticky top bar shows the study status
(Active / Paused, a click leads to Step 4) and has **View data** (the data page), **Add
collaborator** (see below) and **Duplicate**.

1. **Study setup.** Name, the **study context**, and the session flow, sorted under two
   small headings: "Before the chat" (waiting screen, instructions on it) and "During the
   chat" (timer, instructions above the chat). The study context (`projects.study_context`) is background for the
   AI Assistant of Step 3 only: a summary of the research idea, concepts,
   literature, or preferences such as "all teammates write in Spanish", which the assistant
   takes into account when it writes the sample conversation or the conductor instructions.
   It is **never** shared with the director or with participants (`director_rewrite_messages()`
   does not read it; the info text on the page says so). Anything the teammates must follow
   during the chat therefore belongs in the director instructions or the sample conversation.
   The session flow also holds the **instructions above the chat** (see below).
2. **AI model.** Provider cards, the model (the list and the prices come from
   `includes/pricing.php`) with chips for its speed and price, the response speed (the
   director's thinking effort, a segmented control), and the API key with a **Test** button
   (`api/test_key.php`: one tiny request with the values in the form, saved or not, and a
   plain-language explanation of any error), endpoint and format. The last test is
   remembered (see "The last key test").
3. **Conversation.** The **participants** (`projects.team_json`: exactly one `human` entry
   and 1–10 AI teammates `bot1` … `bot10`; names, colors, optional 160 px pictures as data
   URLs; in their own chat the participant always sees "You"). The first row is always the human
   participant (label "Human"), the rows below are the AI teammates ("Bot 1", "Bot 2", … by
   their ids). The prompts call the participant by name in upper case (`YELLOW` by default).
   Then the **sample conversation** (the script the team starts from), the **director
   instructions**. The sample conversation can be divided into **segments** (hard cuts at fixed
   times, each with a short prompt; see "How the participant chat works").
4. **Launch.** Three cards. The **readiness check** (paused, saved, key tested, sample
   conversation, instructions, segments, participants, and, only when they are used, the
   instructions above the chat and the study context) ends with the **study status** and
   its Pause / Continue button, set off by a
   line. **Costs** shows the estimate for the whole study and what was spent so far (see
   "Token and cost estimate"). The **participant link** has two switches: **Test link**
   turns the field into the link for trying the chat yourself (`conversationID=TEST`, with
   an Open button and a warning not to send it to participants), and the Qualtrics option
   adds the conversation ID placeholder. A test link has its own ID, so the Qualtrics option
   is switched off and locked while the test link is shown, and comes back afterwards.

**Add collaborator** (top bar) opens a dialog with the link to the settings and the project
password, the switch "Include the password in the link" (the choice is kept in the browser,
`aiteamstudio.linkMode`), and a short explanation of what the other person does with them:
without an account they open the link and enter the password; signed in to an account, the
project is added to it when they enter the password (`api/auth.php?action=open`), or with
the button "Add to my account" when the link carries the password. Everyone edits the same
project, and a save writes all settings, so the dialog warns against editing at the same
time.

### The last key test

`api/test_key.php` stores the outcome of every test with the project at once, without Save
(`projects.key_test`, JSON: when it ran, whether it worked, the model, the plain-language
hint of a failed test, and a short fingerprint of the tested form values; see
`includes/key_test.php`). The settings page therefore still knows after a reload, or in
another browser, when the key was last tested. The note under the key field (Step 2) and
the readiness check (Step 4) show it: green while the test worked within the last week,
**yellow once it is more than a week old** (with "Test now"), red when it failed. A test
only counts for the values it was run with: the page computes the fingerprint of provider,
model, key, endpoint and format (`keySignature()` in `settings.js`) and compares it with
the stored one, so a changed model or key shows "not tested with the current settings".
The key itself is not part of the record, and the provider's own error text is not kept.
A test does not touch `updated_at`, and a duplicated study starts untested. On a database
without the column the test works as before and is simply not remembered.

### The AI Assistant (Step 3)

One assistant edits every box of Step 3: Situation, AI actors (the text for all actors and
one text per actor), Dynamics, the three boxes of the Conductor card (Purpose, Rewrite rules,
Output format) and the sample conversation. "AI Assistant" in the header of Step 3 docks the
panel beside the cards; it stays in view while the page scrolls (wide screens: Step 3 shifts
left so cards and panel stay centered as a pair; medium screens: side by side; phones: a
drawer above the save bar). A request is a **run** of calls to `api/director.php`, which the
browser starts one after another and shows as a checklist of circles in the chat:

1. `assist_plan` reads every box, the sample conversation, the study context and the chat,
   and returns an answer (a question: nothing changes) or a plan — only the boxes that need a
   change, each with a task, the sample conversation last.
2. `assist_field`, once per box of the plan: the box is outlined and scrolled into view, the
   new text streams in, or the step is skipped ("No change needed").
3. `assist_review` checks the result. Real problems come back as fixes, which run **once** as
   more steps, followed by a final check that only summarizes.

Undo restores every box the run changed; Stop ends the run at once: the box being written keeps
its previous text, and the finished boxes stay. The chat stores requests, replies and change lists, never the edited
text. Every call is a row in `director_events` (modes `assist_plan`, `assist_field`,
`assist_review`), so the assistant's costs appear in Step 4 and on the data page.

**API keys.** The "API key" button next to the model and effort is green with a check when
the provider of the chosen model has a key, yellow with "!" when it has none (or the own key
failed its test). It opens a dialog with one field for OpenAI and one for Anthropic and an
"Import the key from the study (Step 2)" link. A key saved there is the assistant's own key,
stored with the study apart from the Step 2 key (`projects.assistant_keys`, JSON with the
last test of each key; `api/assistant_keys.php`, `includes/assistant_keys.php`) and tested at
once. The assistant uses its own key, otherwise the Step 2 key of the same provider, otherwise
the server's default key (only for the providers in `SERVER_KEY_PROVIDERS`). The page only ever receives where a key comes from and its last
four characters. Duplicating a study copies the keys; example copies and exports never
contain them.

### Instructions above the chat

Step 1 has two kinds of instructions for participants. The older one is plain text on the
waiting screen (`projects.participant_instructions`, `show_instructions`). The second one,
"Show instructions above the chat" (`projects.chat_instructions`, `show_chat_instructions`),
is a **formatted** text in a box between the timer and the messages that stays in place for
the whole session. `aiTeam.php` prints the box only when the option is on **and** the text
has something to read; otherwise the page is byte for byte the chat without it. Long texts
scroll inside the box (at most about a third of the window). The AI teammates never see
this text.

The editor (`assets/js/richtext.js`, `createRichText`) offers bold, italic, underlined,
bulleted and numbered lists, four text sizes, a color palette with the browser's color
picker for other colors, and "remove formatting". It is hidden while the option is off; the
text is kept. Editing is the browser's own (`contenteditable` and its commands), so typing
and undo work as everywhere; the toolbar only calls those commands. The shortcuts for bold,
italic and underlined (⌘B, ⌘I, ⌘U on a Mac; Ctrl+B, Ctrl+I, Ctrl+U elsewhere) are handled by
the editor itself (`shortcutCommand`), so that they behave the same in every browser: with
text selected they format it, with only a cursor they switch the formatting on or off for
the text typed next, and the toolbar shows the state at once. When the last text is
deleted, the cursor is placed anew, so that an empty editor starts plain instead of
continuing in the formatting of the deleted text.

What is stored is a small, fixed subset of HTML: `<p>`, `<ul>`/`<ol>` with `<li>`, `<br>`,
`<strong>`, `<em>`, `<u>` and `<span style="color:#rrggbb;font-size:13|18|24px">`. The
**cleaner** produces it and never copies markup: it reads any HTML, keeps the text and those
few kinds of formatting, and writes the result from scratch, so scripts, links, pictures,
event handlers and foreign styles cannot get through. It exists twice with the same rules:
`sanitizeRichText()` in the browser (for what the editing commands leave behind, `<b>`,
`<font size>`, `<div>`, and for pasted content, including the list paragraphs of Microsoft
Word) and `richtext_sanitize()` in `includes/richtext.php`, which cleans the text again when
it is saved (`api/save_project.php`) and when it is shown, so the participant page never
prints HTML that did not pass through it. `app/tests/richtext.test.mjs` runs both on the
same cases and on 600 pieces of generated tag soup and fails when they differ. Duplicating
a study copies the text; the ZIP archive contains it as `configuration/chat_instructions.html`
and in `project_configuration.json`.

**Pausing** (`projects.is_paused`, `paused_at`; `api/study_status.php`) takes effect at once:
the participant link then shows "This study is not active right now" instead of the chat
(`aiTeam.php`). Test links (`conversationID=TEST…`) keep working, and chats that already run
can finish. **Duplicating** (`api/duplicate_project.php`) copies every setting of the saved
project (study context, participants, sample conversation with its segments, instructions,
session flow, AI model and key) into a new project with a new ID, the same password, " (copy)" in its name,
status active and no data; it is unlocked in the browser session and added to the account
when the account holds the original's password. Unsaved changes are saved first. The data
page has a **Copy share link** button for collaborators (the link with the data key).

### Token and cost estimate

The **Costs** card of Step 4 starts small: the model (with a link back to Step 2), two
number fields (messages per participant, default: the average of the project's recorded
conversations, or 8; number of participants, default 100; both are kept in the browser per
project, `aiteamstudio.estimate.<project id>`) and, in a row of their own, three results:
the estimated cost per participant, the estimated cost of the study, and what was spent so
far (every model call of the project at list prices, loaded from
`api/data.php?action=usage`; the data page and this card load it apart from the
conversation list, `action=list`, and show a spinner until it arrives, because estimating the calls
without token counts takes a few seconds in large studies). A line under them says that these are estimates and that the
real costs can differ. **Show more** opens the calculation behind them: the size of
the texts, one rewrite early and one later in the conversation, one participant, the whole
study, and the spending split into conversations with participants, test conversations and
the AI Assistant. Without the texts of Step 3 the card asks for them first; for a custom
gateway or a model without a list price it shows tokens instead of money.

`assets/js/estimate.js` simulates the director's rewrites for the draft on the page: early
(the participant's first message, a tenth into the sample conversation), later (three
quarters in) and a whole conversation with N participant messages plus one director call per
segment start. It builds the same texts the chat sends
(`chat/script.js` `describeSequence`, the fixed prompt parts from `director_prompt_scaffold()`)
and counts tokens with a piece-based rule set that exists identically in PHP
(`pricing_estimate_tokens()`, used by the data page); a unit test pins both to the same
numbers. Calibration (2026-09-24, two real director prompts of 5–6k tokens): raw estimate ×
0.93 = OpenAI (gpt-6-luna, gpt-5.6-luna), × 1.03 = Claude before 4.7 (Haiku 4.5, Sonnet 4.6),
× 1.42 = Claude 4.7 and later (Sonnet 5, Opus 5.5, Fable 5.1, via `count_tokens`); remaining
error about ±5 %. Costs follow OpenAI's automatic prompt cache as measured on real rewrites:
the first rewrite of a conversation writes the whole prompt to the cache, later rewrites read
the system prompt from it (cached price) and write the rest (GPT-5.6 and newer bill cache
writes at 1.25 × input). Anthropic calls are not cached (no `cache_control` is sent).
Thinking tokens are not estimated; the page says so when thinking is switched on.

### Prices

`includes/pricing.php` holds the model list of the settings page and the list prices per
million tokens (input, cached input, cache write, output), checked against the OpenAI and
Anthropic pricing pages on `PRICING_AS_OF`. Custom gateways get token counts only. When a
provider changes prices or releases models, edit that file; nothing else needs to change.

### Tokens and costs of a study

Since 2026-09-24 every model call stores the provider's token counts in `director_events`
(`input_tokens`, `output_tokens`, `cached_tokens`, `cache_write_tokens`; streamed chat
completions ask for them with `stream_options.include_usage`, Claude streams report them in
`message_start` / `message_delta`). The list and the transcript header of the data page show
them per conversation, and the participant overview CSV and `director_events.csv` carry the
columns. Calls without counts (recorded earlier, or cancelled because the participant wrote
again) are estimated from their texts (`includes/usage.php`, within about 1 % on a test call)
and marked with ≈ in the list and with "about" in the costs views.

The **Costs** dialog of the data page is built like the costs card of Step 4: three results
first (the cost per participant, that is the average of the conversations with participants,
or of the test conversations while there are none; the participants so far; everything spent
so far, including tests and the AI assistants), a line saying that these are estimates at
list prices, and **Show more** for the details (what the money was spent on, every
conversation with its cost, and how the costs come about in plain words). Gateways without a
price show tokens instead of money. The part both pages show the same way (the split into
conversations with participants, test conversations and AI assistants, its table and its
wording) lives in `assets/js/spending.js`, the formats of the amounts in `assets/js/format.js`;
the tiles, the note, "Show more", the tables and the card parts are styled in `app.css`.
`app/tests/spending.test.mjs` covers the shared part.

## Under the hood

- **One database `aiteam_studio`** with seven tables (`app/install/schema.sql`):
  `projects`, `messages`, `director_events` (every model call), `users`,
  `user_projects`, `user_tokens` for the optional accounts, and `rate_events`
  (counted requests, see below). One installer (`app/install/install.php`)
  creates it and upgrades older databases with idempotent migrations.
- **Limits against misuse** (`includes/limits.php`, settings in `config.php`). The
  participant link is public, so the conductor calls it starts are counted: per
  conversation (12 per minute, 300 in total) and per network address (120 per
  minute). Wrong study and account passwords are counted per network address;
  after 10 within 15 minutes, further tries wait. Addresses and ids are stored
  only as hashes and deleted after a day; without the table nothing is refused.
- **PDF transcripts** (`includes/pdf.php`) embed Noto Sans and Noto Emoji as
  subsets (`includes/pdf_font.php`), so accents, Greek, Cyrillic, Vietnamese and
  emoji print as written; characters neither font has (Chinese, Arabic, Hebrew)
  print as "?" (the CSV keeps the full text). Times in the exports are UTC.
- **CSV exports** start a cell that a spreadsheet would run as a formula (`=`,
  `+`, `-`, `@` followed by text) with an apostrophe.
- **Keys and the director prompt stay on the server.** The browser calls
  `api/director.php` (the relay), which loads the project's director
  instructions from the DB, assembles the prompt and streams the model's
  response through. Participants cannot find keys or the study manipulation
  in the dev tools.
- **Multi-provider relay.** Each project picks its provider in Settings → AI
  model: **OpenAI**, **Anthropic**, or **Custom** (an OpenAI-compatible
  gateway such as the university's Azure API Management endpoint). Keys can be
  stored per project (never echoed back to the browser — only "stored, ends in
  ····xxxx") or fall back to the server-wide keys in `config.local.php`, for the
  providers listed in `SERVER_KEY_PROVIDERS` (default OpenAI and Anthropic, not
  the gateway: its key is used only by studies that store the key themselves). The
  relay normalizes every provider's stream to one tiny SSE format
  (`data: {"d":"…"}` … `data: [DONE]`), so the chat client has a single parser.
- **Built for many concurrent participants.** The relay never buffers the full
  model response (chunks are flushed as they arrive), opens no PHP session on
  participant endpoints (nothing serializes users), releases its MySQL
  connection *before* the long upstream call (otherwise hundreds of streaming
  chats would pin hundreds of idle DB connections against MySQL's default
  `max_connections=151`), aborts the upstream request when a participant
  disconnects, bounds every request with hard timeouts, retries once on
  transient upstream errors (5xx, 429, connection loss) and once with a
  corrected reasoning-effort value when a model rejects the configured one.
  Every call is recorded in `director_events`. With the WebSocket relay, streams
  hold no web-server PHP workers: each active model call is one
  command-line PHP worker (~30 MB) started by the relay, capped by
  `RELAY_MAX_WORKERS` (further calls wait in line). Without the relay, one web
  worker is held per active stream, so PHP-FPM's `pm.max_children` (and the
  host's PHP connection limit) bounds the number of *simultaneous* rewrites.
- **Session-based admin auth.** Passwords are not carried in URLs; unlocking a
  project on the studies page opens settings + data for that browser session.
  Data pages can still be shared via `?key=…`, a key per study derived from the
  server secret `DATA_ACCESS_KEY` (`data_share_key()` in `includes/db.php`; a
  server that still defines the older `REVIEW_ACCESS_KEY` keeps working), so a
  share link opens only its own study. The settings-page assistants
  (`api/director.php`, modes `assist_plan` / `assist_field` / `assist_review`) require that unlocked
  session too — they run free-form prompts on the researcher's key; only the
  live `rewrite` mode is open to (anonymous) participants, and it is bounded by
  the project's director instructions.
- **Optional researcher accounts.** An account (email + password on the
  Projects page) stores and organizes project credentials — nothing more.
  Projects a signed-in researcher creates or opens are added to the account
  automatically (including projects other people created), and every project in
  the account unlocks silently on any signed-in device (180-day "stay signed
  in" cookie). Removing a project from the account only forgets the stored
  credential — the project itself and everyone else's access are untouched.
  Everything keeps working without an account; sharing stays password/key
  based. Tables: `users`, `user_projects` (the credential store),
  `user_tokens`. Participant endpoints never touch any of this.
- Config lives in `app/includes/config.php`; machine secrets in
  `app/includes/config.local.php`, which is git-ignored and never uploaded
  (`app/includes/config.local.example.php` is the template).

## Local setup

Requirements: PHP 8.1 or newer with the mysqli, curl, mbstring, iconv and zip
extensions; MySQL 8 or MariaDB 10.6 or newer; Node.js 20 or newer for the unit
tests and the relay.

```bash
# 1. Make sure MySQL runs (Homebrew example):
brew services start mysql

# 2. Local settings and secrets (API keys, database password):
cp app/includes/config.local.example.php app/includes/config.local.php
#    then edit the copy; it is git-ignored

# 3. Create the database and tables:
php app/install/install.php

# 4. Start the dev server from the project root (several workers, so a streaming
#    chat never blocks a save):
PHP_CLI_SERVER_WORKERS=16 php -S localhost:8020 -t .

# 5. Start the WebSocket relay (optional — without it, streams come directly from PHP):
RELAY_ORIGINS=http://localhost:8020 node app/relay/server.js
#    and in app/includes/config.local.php:
#    define('RELAY_WS_URL', 'ws://localhost:8021/relay');
#    define('RELAY_JOB_DIR', '/some/dir/outside/the/web/root');

# 6. Open the studio:
#    http://localhost:8020/app/projects.php
```

Unit tests for the chat runtime (scheduler timing, hidden-tab catch-up, team
sender mapping, streamed-plan extraction, the save queue and its batching under
the browsers' keepalive limit), the relay (WebSocket frames, job lifecycle,
cancel, disconnect, queueing — with a fake worker), the cleaner of formatted
text (browser and PHP side by side), the segments and the cost estimate run
without a browser:

```bash
node --test app/tests
```
