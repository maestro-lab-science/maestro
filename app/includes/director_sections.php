<?php
/**
 * director_sections.php — the conductor instructions as sections.
 *
 * Since 2026-10 the settings page edits the conductor (director) instructions
 * in sections: goal (the purpose of the conductor: role and priorities),
 * situation (task and background), team (the AI actors: how the actors write
 * and behave, what the actors have in common), bots (the text per actor, a
 * place in the order only: the texts live in "actors"), manip (the social
 * dynamic, the manipulation in a multi-condition study), rewrite (what the
 * conductor keeps when rewriting) and tech (output format). The settings page
 * groups the sections into cards (Situation, AI actors, Dynamics, Conductor) in
 * a fixed display order; the stored order is the order in the prompt only. The sections are stored as
 * JSON in projects.director_sections:
 *
 *   {"order":["goal","situation",…,"tech"],
 *    "text":{"goal":"…","situation":"…",…,"tech":"…"},
 *    "actors":{"bot1":"…","bot2":"…"}}
 *
 * projects.director_instructions keeps the assembled prompt (the sections in
 * the stored order, empty sections skipped, joined by one blank line), so the
 * director, the cost estimate, the exports and the copies read one text as
 * before. director_assemble() is the only place that builds that text.
 *
 * A project without stored sections (older projects) shows its whole text in
 * the first section, so nothing changes until the researcher cuts the text
 * into the sections.
 *
 * @author Marc Becker
 */

declare(strict_types=1);

const DIRECTOR_SECTION_KEYS = ['goal', 'situation', 'team', 'bots', 'manip', 'rewrite', 'tech'];
/** Keys of earlier shapes and the section that holds their text now. */
const DIRECTOR_SECTION_LEGACY = [
    'style' => 'team', 'guard' => 'team', 'flow' => 'team', 'timing' => 'team', 'interact' => 'team', 'ending' => 'team',
    'notes' => 'manip',
];
const DIRECTOR_SECTION_MAX  = 200000; // characters per section

/** The default order of the sections for new projects. */
function director_sections_default_order(): array
{
    return DIRECTOR_SECTION_KEYS;
}

/**
 * Normalize a decoded sections value (from the database or a save request).
 * Returns null when the value is not a sections object at all.
 *
 * @param mixed $raw
 */
function director_sections_normalize($raw): ?array
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw) || !isset($raw['text']) || !is_array($raw['text'])) {
        return null;
    }
    // The texts in the stored order. Earlier shapes had finer sections: their
    // text joins the section that holds the theme now, at the place the stored
    // order gives the text, so the assembled prompt stays the same.
    $stored = [];
    foreach ((array)($raw['order'] ?? []) as $key) {
        if (is_string($key) && !in_array($key, $stored, true)) {
            $stored[] = $key;
        }
    }
    foreach (array_keys($raw['text']) as $key) {
        if (is_string($key) && !in_array($key, $stored, true)) {
            $stored[] = $key;
        }
    }
    $text = array_fill_keys(DIRECTOR_SECTION_KEYS, '');
    foreach ($stored as $old) {
        $target = in_array($old, DIRECTOR_SECTION_KEYS, true) ? $old : (DIRECTOR_SECTION_LEGACY[$old] ?? null);
        $value  = $raw['text'][$old] ?? '';
        if ($target === null || !is_string($value) || $value === '') {
            continue;
        }
        if ($text[$target] === '') {
            $text[$target] = director_section_clamp($value);
        } elseif (trim($value) !== '') {
            $text[$target] .= "\n\n" . director_section_clamp($value);
        }
    }
    $text['bots'] = ''; // the texts per actor live in "actors"; bots is a place in the order only
    $order = []; // a legacy key takes the place of the section that holds its text now
    foreach ($stored as $old) {
        $key = in_array($old, DIRECTOR_SECTION_KEYS, true) ? $old : (DIRECTOR_SECTION_LEGACY[$old] ?? null);
        if ($key !== null && !in_array($key, $order, true)) {
            $order[] = $key;
        }
    }
    if (!in_array('bots', $order, true) && in_array('team', $order, true)) { // earlier shapes: the texts per actor followed the actors section
        array_splice($order, array_search('team', $order, true) + 1, 0, ['bots']);
    }
    foreach (DIRECTOR_SECTION_KEYS as $key) { // sections missing from the order go last, in the default order
        if (!in_array($key, $order, true)) {
            $order[] = $key;
        }
    }
    $actors = [];
    foreach ((array)($raw['actors'] ?? []) as $id => $value) {
        if (is_string($id) && preg_match('/^bot(10|[1-9])$/', $id) && is_string($value) && trim($value) !== '') {
            $actors[$id] = director_section_clamp($value);
        }
    }
    return ['order' => $order, 'text' => $text, 'actors' => $actors];
}

function director_section_clamp(string $value): string
{
    $value = str_replace("\r\n", "\n", $value);
    if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > DIRECTOR_SECTION_MAX) {
        return mb_substr($value, 0, DIRECTOR_SECTION_MAX, 'UTF-8');
    }
    return strlen($value) > DIRECTOR_SECTION_MAX ? substr($value, 0, DIRECTOR_SECTION_MAX) : $value;
}

/** Sections with the whole text in the first section (projects saved before the sections existed). */
function director_sections_legacy(string $instructions): array
{
    $text = array_fill_keys(DIRECTOR_SECTION_KEYS, '');
    $text['goal'] = str_replace("\r\n", "\n", $instructions);
    return ['order' => director_sections_default_order(), 'text' => $text, 'actors' => []];
}

/** The sections of a stored project (normalized; the legacy shape when none are stored). */
function director_sections_of_project(array $project): array
{
    $sections = director_sections_normalize($project['director_sections'] ?? null);
    return $sections ?? director_sections_legacy((string)($project['director_instructions'] ?? ''));
}

/**
 * The prompt text the director reads: the sections in their order, empty
 * sections skipped, joined by one blank line. At the place of "bots" the
 * texts per actor appear, each under the actor's name.
 *
 * @param array $sections normalized sections
 * @param array $all      the team (team_all), for the actor names
 */
function director_assemble(array $sections, array $all): string
{
    $parts = [];
    foreach ($sections['order'] as $key) {
        $text = $key === 'bots' ? director_actors_text($sections['actors'] ?? [], $all) : (string)($sections['text'][$key] ?? '');
        if (trim($text) !== '') {
            $parts[] = $text;
        }
    }
    return implode("\n\n", $parts);
}

/** The texts per actor: one block per actor with specific instructions, in team order. */
function director_actors_text(array $actors, array $all): string
{
    $parts = [];
    foreach ($all as $member) {
        $id = (string)($member['id'] ?? '');
        if ($id === 'human' || !isset($actors[$id]) || trim((string)$actors[$id]) === '') {
            continue;
        }
        $name = trim((string)($member['name'] ?? '')) !== '' ? trim((string)$member['name']) : $id;
        $parts[] = 'Instructions for ' . $name . ' only:' . "\n" . $actors[$id];
    }
    return implode("\n\n", $parts);
}

/** JSON for the database column. */
function director_sections_encode(array $sections): string
{
    return json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
