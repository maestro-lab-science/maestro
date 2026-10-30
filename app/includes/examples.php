<?php
/**
 * examples.php — the example studies shown on the projects page.
 *
 * The three studies of the MAESTRO paper, two conditions each. The titles and
 * summaries live here; which project on this server holds which condition is
 * set in config.local.php (EXAMPLE_PROJECTS, condition slug → project ID),
 * because the IDs differ between servers. A condition without a project on
 * this server is listed without links.
 *
 * Example projects can be used by everyone: the test link opens their chat,
 * and settings.php?example=ID shows their setup as a DRAFT COPY (never the
 * example's API key) with a new project ID, a new password and the name "Copy of …". The
 * copy is stored only when the visitor clicks Save (api/save_project.php
 * with draft_from), so everyone gets their own project. Changing an example
 * itself still needs its password.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/pricing.php';

/**
 * The example studies in display order. Each study has a slug, a title, a
 * one-sentence summary and two conditions (slug, name, short label, note).
 *
 * Order, titles and wording follow the paper (Study 1 Norms, Study 2 Interaction
 * patterns, Study 3 Role expectations).
 */
function example_studies(): array
{
    return [
        [
            'slug'    => 'safety',
            'title'   => 'Norms',
            'summary' => 'Team members work on an idea-generation task under low or high psychological safety. '
                       . 'The study measures the perceived and objective voice of participants.',
            'conditions' => [
                ['slug' => 'safety-high', 'name' => 'High psychological safety', 'short' => 'High safety', 'note' => 'The actors welcome what the participant says.'],
                ['slug' => 'safety-low',  'name' => 'Low psychological safety',  'short' => 'Low safety',  'note' => 'The actors brush off what the participant says.'],
            ],
        ],
        [
            'slug'    => 'leadership',
            'title'   => 'Interaction patterns',
            'summary' => 'One AI teammate claims leadership and another teammate grants or withholds leadership. '
                       . 'The study assesses the subsequent leadership claiming of participants.',
            'conditions' => [
                ['slug' => 'lead-granted',  'name' => 'High leadership granting', 'short' => 'High granting', 'note' => 'The other teammate grants the leadership claim.'],
                ['slug' => 'lead-withheld', 'name' => 'Low leadership granting',  'short' => 'Low granting',  'note' => 'The other teammate withholds leadership.'],
            ],
        ],
        [
            'slug'    => 'roleconflict',
            'title'   => 'Role expectations',
            'summary' => 'Participants face intrasender or intersender role conflict during the conversation. '
                       . 'The study assesses the subsequent sensemaking of participants.',
            'conditions' => [
                ['slug' => 'conflict-intra', 'name' => 'Intrasender role conflict', 'short' => 'Intrasender', 'note' => 'One actor sends conflicting expectations.'],
                ['slug' => 'conflict-inter', 'name' => 'Intersender role conflict', 'short' => 'Intersender', 'note' => 'Two actors send conflicting expectations.'],
            ],
        ],
    ];
}

/** Project ID of a condition on this server, '' when none is configured. */
function example_project_id(string $conditionSlug): string
{
    $map = defined('EXAMPLE_PROJECTS') && is_array(EXAMPLE_PROJECTS) ? EXAMPLE_PROJECTS : [];
    return sanitize_project_id((string)($map[$conditionSlug] ?? ''));
}

/** True when this project ID is one of the configured examples. */
function example_is_project(string $projectId): bool
{
    return example_for_project($projectId) !== null;
}

/** The study and condition a project ID stands for, or null. */
function example_for_project(string $projectId): ?array
{
    if ($projectId === '') {
        return null;
    }
    foreach (example_studies() as $study) {
        foreach ($study['conditions'] as $cond) {
            if (example_project_id($cond['slug']) === $projectId) {
                return ['study' => $study, 'condition' => $cond];
            }
        }
    }
    return null;
}

/**
 * The studies with each condition's project ID and whether that project
 * exists on this server (one query for all of them). On a database error
 * every condition is reported as missing, so the page still renders.
 */
function example_studies_resolved(): array
{
    $studies = example_studies();
    $ids = [];
    foreach ($studies as $study) {
        foreach ($study['conditions'] as $cond) {
            $id = example_project_id($cond['slug']);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
    }
    $existing = [];
    if ($ids) {
        try {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT project_id FROM projects WHERE project_id IN ({$marks})");
            $keys = array_keys($ids);
            $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as $row) {
                $existing[$row[0]] = true;
            }
            $stmt->close();
        } catch (Throwable $e) {
            $existing = [];
        }
    }
    foreach ($studies as &$study) {
        foreach ($study['conditions'] as &$cond) {
            $id = example_project_id($cond['slug']);
            $cond['project_id'] = $id;
            $cond['available']  = $id !== '' && isset($existing[$id]);
        }
        unset($cond);
    }
    unset($study);
    return $studies;
}

/** A random project ID (16 alphanumeric characters) that no project uses yet. */
function example_new_id(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    for ($try = 0; $try < 10; $try++) {
        $id = '';
        for ($i = 0; $i < 16; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (!load_project($id)) {
            return $id;
        }
    }
    throw new RuntimeException('Could not create a new study ID. Please try again.');
}

/** A random 20-character password without look-alike characters. */
function example_new_password(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < 20; $i++) {
        $pw .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pw;
}

/** The name of a visitor's copy: "Copy of " + the example's name (at most 80 characters). */
function example_copy_name(array $source): string
{
    $base = trim((string)$source['name']) !== '' ? trim((string)$source['name']) : (string)$source['project_id'];
    $name = 'Copy of ' . $base;
    return function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
}

/**
 * The model settings of a copy where they differ from the example. A copy never
 * gets the example's key, so an example on a provider whose server key studies
 * may not use (SERVER_KEY_PROVIDERS, for example the gateway) is copied onto
 * OpenAI, with the same model when OpenAI offers it: the copy runs on the
 * server's OpenAI key right away.
 */
function example_copy_model(array $source): array
{
    if (llm_server_key((string)($source['api_provider'] ?? '')) !== '' || llm_server_key('openai') === '') {
        return [];
    }
    $model = (string)($source['model'] ?? '');
    $offered = [];
    foreach (pricing_model_catalog()['openai'] as $group) {
        $offered = array_merge($offered, array_column($group['models'], 'id'));
    }
    return [
        'api_provider' => 'openai',
        'model'        => in_array($model, $offered, true) ? $model : '',
        'api_endpoint' => '',
        'api_format'   => 'auto',
    ];
}

/**
 * The draft copy of an example that settings.php shows: every setting of the
 * example except its API key (and its provider, see example_copy_model), under
 * a new project ID that is not stored yet, with the name "Copy of …", not
 * paused, and a fresh password in 'draft_password' (the page sends it back
 * with the first Save, which stores the model settings of the draft).
 */
function example_draft_project(array $source): array
{
    $project = array_merge($source, example_copy_model($source));
    $project['project_id']     = example_new_id();
    $project['name']           = example_copy_name($source);
    $project['api_key']        = '';
    $project['key_test']       = null;
    $project['assistant_keys'] = null;
    $project['is_paused']      = 0;
    $project['paused_at']      = null;
    $project['password_hash']  = '';
    $project['draft_password'] = example_new_password();
    return $project;
}

/**
 * Store a visitor's copy of an example (the first Save of a draft): the
 * example's settings without its API key, under the chosen project ID and
 * password. Returns the new project row.
 */
function example_store_copy(array $source, string $project_id, string $password): array
{
    $name = example_copy_name($source);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $columns = ['participant_instructions', 'show_instructions', 'director_instructions', 'director_sections', 'conversation_script',
        'study_context', 'team_json', 'show_ready_modal', 'ready_wait_seconds', 'timer_enabled', 'timer_duration_seconds',
        'show_chat_instructions', 'chat_instructions',
        'api_provider', 'model', 'api_format', 'reasoning_effort', 'director_effort', 'api_endpoint'];
    $list = implode(', ', $columns);
    $stmt = db()->prepare("INSERT INTO projects (project_id, name, password_hash, api_key, {$list}) SELECT ?, ?, ?, '', {$list} FROM projects WHERE project_id = ?");
    $sourceId = (string)$source['project_id'];
    $stmt->bind_param('ssss', $project_id, $name, $hash, $sourceId);
    $stmt->execute();
    $stmt->close();
    $row = load_project($project_id);
    if (!$row) {
        throw new RuntimeException('The copy could not be stored. Please try again.');
    }
    return $row;
}
