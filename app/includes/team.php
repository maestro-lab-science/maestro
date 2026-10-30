<?php
/**
 * team.php — everyone in the chat: the AI teammates and the participant.
 *
 * A project has one to TEAM_MAX AI teammates and exactly one human participant.
 * Each teammate has a stable id (bot1 … bot10 — the value stored in
 * messages.sender and used in scripts); the participant's id is always "human".
 * Everyone has a display name, a color and optionally a small picture
 * (avatar, stored as a data URL). In their own chat the participant sees
 * their messages labelled "You"; the prompts and the data use their name.
 *
 * The configuration lives in projects.team_json as one list in display order.
 * Older projects stored the teammates only (the participant was always
 * Yellow); such lists get the participant added with its defaults, and
 * NULL/empty means the classic default trio Blue / Green / Red plus Yellow.
 *
 * Names and colors are display attributes only. Scripts and the director refer
 * to teammates by id; the prompts show the names in upper case ("BLUE",
 * "ANNA") next to the short wire codes b1, b2, …, and the participant by their
 * name in upper case ("YELLOW").
 *
 * @author Marc Becker
 */

const TEAM_MAX        = 10;
const TEAM_NAME_MAX   = 24;
/** Largest accepted avatar data URL (the settings page sends ~10–30 KB pictures). */
const TEAM_AVATAR_MAX = 150000;

/** Default names/colors by position — also what "Add teammate" suggests. */
const TEAM_PALETTE = [
    ['Blue', '#3b82f6'], ['Green', '#22c55e'], ['Red', '#ef4444'], ['Purple', '#a855f7'], ['Orange', '#f97316'],
    ['Teal', '#14b8a6'], ['Pink', '#ec4899'], ['Indigo', '#6366f1'], ['Cyan', '#06b6d4'], ['Gray', '#78716c'],
];

/** The participant as older projects had it: Yellow, shown to themselves as "You". */
const TEAM_HUMAN_DEFAULT = ['id' => 'human', 'name' => 'Yellow', 'color' => '#eab308'];

/** The participant's original hand-tuned color set (kept exactly for the default yellow). */
const TEAM_HUMAN_COLORS = ['color' => '#eab308', 'soft' => '#fdf1bd', 'deep' => '#854d0e', 'text' => '#ffffff'];

/** The default team: Blue, Green, Red and the participant Yellow. */
function team_default_list(): array
{
    $out = [];
    for ($i = 0; $i < 3; $i++) {
        $out[] = ['id' => 'bot' . ($i + 1), 'name' => TEAM_PALETTE[$i][0], 'color' => TEAM_PALETTE[$i][1]];
    }
    $out[] = TEAM_HUMAN_DEFAULT;
    return $out;
}

/** True for a teammate id: bot1 … bot10. */
function team_is_bot_id(string $id): bool
{
    return (bool)preg_match('/^bot(10|[1-9])$/', $id);
}

/** A picture as a data URL (PNG, JPEG, WebP or GIF, base64), or '' when unusable. */
function team_clean_avatar($value): string
{
    $s = trim((string)$value);
    if ($s === '' || strlen($s) > TEAM_AVATAR_MAX) {
        return '';
    }
    return preg_match('#^data:image/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/]+={0,2}$#', $s) ? $s : '';
}

/**
 * Validate a raw team (from the settings page or team_json).
 * Accepts the current format (everyone, with exactly one "human" entry) and
 * the older one (teammates only — the participant is then added at the end
 * with its defaults).
 *
 * @return array|string  the normalized list in display order, or an error message
 */
function team_normalize_all($raw)
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (is_array($raw) && isset($raw['members'])) {
        $raw = $raw['members'];
    }
    if (!is_array($raw) || !array_is_list($raw)) {
        return 'The team must be a list of participants.';
    }
    $all    = [];
    $ids    = [];
    $names  = [];
    $bots   = 0;
    $humans = 0;
    foreach ($raw as $i => $m) {
        if (!is_array($m)) {
            return 'Participant #' . ($i + 1) . ' is not valid.';
        }
        $id = strtolower(trim((string)($m['id'] ?? '')));
        $isHuman = $id === 'human';
        if (!$isHuman && !team_is_bot_id($id)) {
            return 'Participant #' . ($i + 1) . ' has an invalid id (expected bot1 … bot' . TEAM_MAX . ' or human).';
        }
        if (isset($ids[$id])) {
            return $isHuman ? 'Only one person can be the human participant.' : 'Teammate id ' . $id . ' is used twice.';
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string)($m['name'] ?? '')) ?? '');
        $name = function_exists('mb_substr') ? mb_substr($name, 0, TEAM_NAME_MAX, 'UTF-8') : substr($name, 0, TEAM_NAME_MAX);
        if ($name === '') {
            return ($isHuman ? 'The participant' : 'Teammate ' . $id) . ' needs a name.';
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if ($key === 'you') {
            return '"' . $name . '" cannot be used as a name, because the participant sees their own messages labelled "You".';
        }
        if (isset($names[$key])) {
            return 'Two people are called "' . $name . '". Names must be unique.';
        }
        $color = strtolower(trim((string)($m['color'] ?? '')));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $color = $isHuman ? TEAM_HUMAN_DEFAULT['color'] : TEAM_PALETTE[$i % count(TEAM_PALETTE)][1];
        }
        $entry = ['id' => $id, 'name' => $name, 'color' => $color];
        $avatar = team_clean_avatar($m['avatar'] ?? '');
        if ($avatar !== '') {
            $entry['avatar'] = $avatar;
        }
        if ($isHuman) {
            $humans++;
        } else {
            $bots++;
        }
        $ids[$id]    = true;
        $names[$key] = true;
        $all[]       = $entry;
    }
    if ($bots < 1 || $bots > TEAM_MAX) {
        return 'The chat needs between 1 and ' . TEAM_MAX . ' AI teammates.';
    }
    if ($humans === 0) {
        // Older format: the participant was implicit. Add it, avoiding a name clash.
        $human = TEAM_HUMAN_DEFAULT;
        if (isset($names['yellow'])) {
            $human['name'] = 'Participant';
        }
        $all[] = $human;
    }
    return $all;
}

/** Everyone in the chat (normalized, display order), falling back to the default team. */
function team_all(array $project): array
{
    $raw = trim((string)($project['team_json'] ?? ''));
    if ($raw === '') {
        return team_default_list();
    }
    $all = team_normalize_all($raw);
    return is_array($all) ? $all : team_default_list();
}

/** The AI teammates of a full list. */
function team_bots(array $all): array
{
    return array_values(array_filter($all, fn($m) => ($m['id'] ?? '') !== 'human'));
}

/** The participant entry of a full list (defaults when missing). */
function team_human(array $all): array
{
    foreach ($all as $m) {
        if (($m['id'] ?? '') === 'human') {
            return $m;
        }
    }
    return TEAM_HUMAN_DEFAULT;
}

/** @return string[] the teammate ids, in order */
function team_ids(array $members): array
{
    return array_map(fn($m) => $m['id'], $members);
}

/** Map an accepted sender spelling (bot2, b2, BOT2) to the teammate id, or null. */
function team_sender_id(array $members, $raw): ?string
{
    $s = strtolower(trim((string)$raw));
    if (!preg_match('/^(?:bot|b)(10|[1-9])$/', $s, $m)) {
        return null;
    }
    $id = 'bot' . $m[1];
    return in_array($id, team_ids($members), true) ? $id : null;
}

/** Upper-case name as the prompts show it ("BLUE", "ANNA"). */
function team_prompt_name(string $name): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
}

/** How the prompts name the participant ("YELLOW"). */
function team_human_prompt_name(array $all): string
{
    return team_prompt_name((string)team_human($all)['name']);
}

/** "b1 = BLUE, b2 = GREEN, …" — the director's sender codes. */
function team_code_legend(array $members): string
{
    $parts = [];
    foreach ($members as $m) {
        $parts[] = 'b' . substr($m['id'], 3) . ' = ' . team_prompt_name($m['name']);
    }
    return implode(', ', $parts);
}

/** "bot1" (Blue), "bot2" (Green), … — the script's sender ids. */
function team_id_legend(array $members): string
{
    $parts = [];
    foreach ($members as $m) {
        $parts[] = '"' . $m['id'] . '" (' . $m['name'] . ')';
    }
    return implode(', ', $parts);
}

/** First letter of a name, upper case — the avatar label. */
function team_initial(string $name): string
{
    $c = function_exists('mb_substr') ? mb_substr(trim($name), 0, 1, 'UTF-8') : substr(trim($name), 0, 1);
    if ($c === '') {
        return '?';
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($c, 'UTF-8') : strtoupper($c);
}

// ---------------------------------------------------------------------------
// Colors. Everyone has one chosen color; the tint for bubbles ("soft") and the
// dark shade for names and text ("deep") are derived from it in HSL, so
// whatever a researcher picks stays readable. assets/js/app.js does the same
// math for the browser.
// ---------------------------------------------------------------------------

function team_hex_to_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
}

function team_rgb_to_hex(array $rgb): string
{
    $c = fn(float $v) => (int)round(max(0.0, min(1.0, $v)) * 255);
    return sprintf('#%02x%02x%02x', $c($rgb[0]), $c($rgb[1]), $c($rgb[2]));
}

function team_rgb_to_hsl(array $rgb): array
{
    [$r, $g, $b] = $rgb;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l   = ($max + $min) / 2;
    if ($max === $min) {
        return [0.0, 0.0, $l];
    }
    $d = $max - $min;
    $s = $d / (1 - abs(2 * $l - 1));
    if ($max === $r) {
        $h = fmod(($g - $b) / $d, 6);
    } elseif ($max === $g) {
        $h = ($b - $r) / $d + 2;
    } else {
        $h = ($r - $g) / $d + 4;
    }
    $h *= 60;
    if ($h < 0) {
        $h += 360;
    }
    return [$h, $s, $l];
}

function team_hsl_to_rgb(array $hsl): array
{
    [$h, $s, $l] = $hsl;
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    $table  = [[$c, $x, 0], [$x, $c, 0], [0, $c, $x], [0, $x, $c], [$x, 0, $c], [$c, 0, $x]];
    [$r, $g, $b] = $table[((int)floor($h / 60)) % 6];
    return [$r + $m, $g + $m, $b + $m];
}

/** @return array{color:string, soft:string, deep:string, text:string} */
function team_color_variants(string $hex): array
{
    if (strtolower($hex) === TEAM_HUMAN_COLORS['color']) {
        return TEAM_HUMAN_COLORS; // the participant's original yellow, unchanged
    }
    $rgb = team_hex_to_rgb($hex);
    [$h, $s, $l] = team_rgb_to_hsl($rgb);
    $soft = team_rgb_to_hex(team_hsl_to_rgb([$h, $s, 0.92]));
    $deep = team_rgb_to_hex(team_hsl_to_rgb([$h, $s * 0.85, min(0.40, max(0.22, $l * 0.62))]));
    $lum  = 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
    return ['color' => strtolower($hex), 'soft' => $soft, 'deep' => $deep, 'text' => $lum > 0.7 ? '#1f2937' : '#ffffff'];
}

/**
 * Everything a browser needs to draw the roles: id → name, initial, avatar,
 * the name the prompts use (promptName) and the color set. Includes the
 * participant under "human".
 *
 * $view 'participant' (the chat): the participant's own messages are labelled
 * "You". 'researcher' (data page, exports): the participant's name.
 */
function team_roles_map(array $all, string $view = 'participant'): array
{
    $map = [];
    foreach ($all as $m) {
        $isHuman = $m['id'] === 'human';
        $label = ($isHuman && $view === 'participant') ? 'You' : $m['name'];
        $role = team_color_variants($m['color']) + [
            'name'       => $label,
            'initial'    => team_initial($m['name']),
            'promptName' => team_prompt_name($m['name']),
        ];
        if (!empty($m['avatar'])) {
            $role['avatar'] = $m['avatar'];
        }
        $map[$m['id']] = $role;
    }
    if (!isset($map['human'])) {
        $map['human'] = team_roles_map([TEAM_HUMAN_DEFAULT], $view)['human'];
    }
    return $map;
}

/** One avatar circle with its colors (and picture) inline, for server-rendered pages. */
function team_avatar_html(array $role, string $classes = 'avatar'): string
{
    $style = '--role-color:' . $role['color'] . ';--role-text:' . $role['text'];
    $avatar = team_clean_avatar($role['avatar'] ?? '');
    if ($avatar !== '') {
        $classes .= ' has-img';
        $style .= ";background-image:url('" . $avatar . "')"; // base64 only: no quotes can occur
    }
    return '<span class="' . htmlspecialchars($classes) . '" style="' . htmlspecialchars($style) . '" title="' . htmlspecialchars($role['name']) . '">'
        . ($avatar !== '' ? '' : htmlspecialchars($role['initial'])) . '</span>';
}
