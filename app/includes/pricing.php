<?php
/**
 * pricing.php — the models offered in the settings page, their prices, and
 * token arithmetic (estimates and costs).
 *
 * One source of truth for three places: the model list and the cost estimate
 * of the settings page (injected as JSON), the usage overview of the data
 * page, and the exports. Prices are USD per million tokens for the standard
 * (pay-as-you-go) tier, checked on PRICING_AS_OF against:
 *   https://developers.openai.com/api/docs/pricing
 *   https://platform.claude.com/docs/en/about-claude/pricing
 * Custom gateways (e.g. a university's Azure API Management) bill through
 * their own contracts, so they get token counts only, never a price.
 *
 * Update this file when providers change their prices or release models;
 * nothing else needs to change.
 *
 * @author Marc Becker
 */

const PRICING_AS_OF = '2026-09-24';

/**
 * Models offered per provider, in groups, with a short note for the select.
 * The first entry of a provider is not special; the project's stored model
 * (or the server default from config.php) is selected.
 */
function pricing_model_catalog(): array
{
    return [
        'openai' => [
            ['group' => 'GPT-6', 'models' => [
                ['id' => 'gpt-6-luna',  'note' => 'fastest, lowest cost'],
                ['id' => 'gpt-6-sol',   'note' => 'balanced'],
                ['id' => 'gpt-6-astra', 'note' => 'most capable, slow'],
            ]],
            ['group' => 'GPT-5.6', 'models' => [
                ['id' => 'gpt-5.6-luna',  'note' => 'fast, low cost'],
                ['id' => 'gpt-5.6-terra', 'note' => 'balanced'],
                ['id' => 'gpt-5.6-sol',   'note' => 'capable'],
            ]],
            ['group' => 'Older models', 'models' => [
                ['id' => 'gpt-5.5',      'note' => ''],
                ['id' => 'gpt-5.4',      'note' => ''],
                ['id' => 'gpt-5.4-mini', 'note' => ''],
                ['id' => 'gpt-5.4-nano', 'note' => ''],
                ['id' => 'gpt-5-mini',   'note' => ''],
                ['id' => 'gpt-5-nano',   'note' => ''],
                ['id' => 'gpt-4.1',      'note' => ''],
                ['id' => 'gpt-4.1-mini', 'note' => ''],
            ]],
        ],
        'anthropic' => [
            ['group' => 'Current models', 'models' => [
                ['id' => 'claude-haiku-4-5-20251001', 'note' => 'fastest, lowest cost'],
                ['id' => 'claude-sonnet-5',           'note' => 'balanced'],
                ['id' => 'claude-opus-5-5',           'note' => 'capable, slower'],
                ['id' => 'claude-fable-5-1',          'note' => 'most capable, slowest'],
            ]],
            ['group' => 'Older models', 'models' => [
                ['id' => 'claude-opus-5',     'note' => ''],
                ['id' => 'claude-opus-4-8',   'note' => ''],
                ['id' => 'claude-sonnet-4-6', 'note' => ''],
                ['id' => 'claude-fable-5',    'note' => ''],
            ]],
        ],
        'custom' => [
            ['group' => 'Gateway deployments', 'models' => [
                ['id' => 'gpt-5.6-sol',   'note' => ''],
                ['id' => 'gpt-5.6-terra', 'note' => ''],
                ['id' => 'gpt-5.6-luna',  'note' => ''],
                ['id' => 'gpt-5-mini',    'note' => ''],
            ]],
        ],
    ];
}

/**
 * Prices per million tokens: [input, cached input (cache read), cache write, output].
 * Cache writes are billed extra only where the provider says so (OpenAI GPT-5.6
 * and newer: 1.25x input; Anthropic only with explicit cache_control, which
 * this app does not use). Keys are model ids or id prefixes; the longest
 * matching key wins, so dated snapshots ("gpt-5-mini-2025-08-07",
 * "claude-haiku-4-5-20251001") find their family.
 */
function pricing_table(): array
{
    return [
        'openai' => [
            'gpt-6-astra'   => [10.00, 1.00, 12.50, 50.00],
            'gpt-6-sol'     => [2.00, 0.20, 2.50, 10.00],
            'gpt-6-luna'    => [0.10, 0.01, 0.125, 0.50],
            'gpt-5.6-sol'   => [4.00, 0.40, 5.00, 20.00], // promotional price, at least until 2026-11-21
            'gpt-5.6-terra' => [2.00, 0.20, 2.50, 12.00],
            'gpt-5.6-luna'  => [0.20, 0.02, 0.25, 1.20],
            'gpt-5.5'       => [5.00, 0.50, 5.00, 30.00],
            'gpt-5.4-nano'  => [0.20, 0.02, 0.20, 1.25],
            'gpt-5.4-mini'  => [0.75, 0.075, 0.75, 4.50],
            'gpt-5.4'       => [2.50, 0.25, 2.50, 15.00],
            'gpt-5.2'       => [1.75, 0.175, 1.75, 14.00],
            'gpt-5.1'       => [1.25, 0.125, 1.25, 10.00],
            'gpt-5-nano'    => [0.05, 0.005, 0.05, 0.40],
            'gpt-5-mini'    => [0.25, 0.025, 0.25, 2.00],
            'gpt-5'         => [1.25, 0.125, 1.25, 10.00],
            'gpt-4.1-nano'  => [0.10, 0.025, 0.10, 0.40],
            'gpt-4.1-mini'  => [0.40, 0.10, 0.40, 1.60],
            'gpt-4.1'       => [2.00, 0.50, 2.00, 8.00],
            'o4-mini'       => [1.10, 0.275, 1.10, 4.40],
            'o3'            => [2.00, 0.50, 2.00, 8.00],
        ],
        'anthropic' => [
            'claude-fable-5-1'  => [10.00, 0.25, 12.50, 50.00],
            'claude-fable-5'    => [10.00, 1.00, 12.50, 50.00],
            'claude-opus-5-5'   => [4.00, 0.20, 5.00, 20.00],
            'claude-opus-5'     => [5.00, 0.50, 6.25, 25.00],
            'claude-opus-4-8'   => [5.00, 0.50, 6.25, 25.00],
            'claude-opus-4-7'   => [5.00, 0.50, 6.25, 25.00],
            'claude-opus-4-6'   => [5.00, 0.50, 6.25, 25.00],
            'claude-opus-4-5'   => [5.00, 0.50, 6.25, 25.00],
            'claude-sonnet-5'   => [2.00, 0.20, 2.50, 10.00],
            'claude-sonnet-4-6' => [3.00, 0.30, 3.75, 15.00],
            'claude-sonnet-4-5' => [3.00, 0.30, 3.75, 15.00],
            'claude-haiku-4-5'  => [1.00, 0.10, 1.25, 5.00],
        ],
    ];
}

/**
 * The price row for a model: ['model' => matched key, 'input', 'cached', 'write', 'output'],
 * or null when unknown (custom gateways, unlisted models). A listed model also
 * matches its dated snapshots ("gpt-5-mini-2025-08-07", "claude-haiku-4-5-20251001")
 * and "-latest", but not other variants ("gpt-5-pro" is not "gpt-5"): those
 * are counted in tokens only. Mirrored in assets/js/estimate.js priceFor().
 */
function pricing_for(string $provider, string $model): ?array
{
    $table = pricing_table()[$provider] ?? null;
    $model = strtolower(trim($model));
    if ($table === null || $model === '') {
        return null;
    }
    $best = null;
    foreach ($table as $key => $row) {
        if ($model === $key || (str_starts_with($model, $key . '-')
            && preg_match('/^-(\d{4}-\d{2}-\d{2}|\d{8}|latest)$/', substr($model, strlen($key))))) {
            if ($best === null || strlen($key) > strlen($best)) {
                $best = $key;
            }
        }
    }
    if ($best === null) {
        return null;
    }
    [$in, $cached, $write, $out] = $table[$best];
    return ['model' => $best, 'input' => $in, 'cached' => $cached, 'write' => $write, 'output' => $out];
}

/**
 * Cost in USD of one or more calls. $input is ALL input tokens including the
 * cached and cache-write parts (that is how llm_extract_usage reports them).
 */
function pricing_cost(array $price, int $input, int $output, int $cached = 0, int $cacheWrite = 0): float
{
    $uncached = max(0, $input - $cached - $cacheWrite);
    return ($uncached * $price['input'] + $cached * $price['cached'] + $cacheWrite * $price['write'] + $output * $price['output']) / 1000000;
}

/**
 * Which tokenizer family a model uses, for estimates: OpenAI models; Claude
 * models before 4.7 (Haiku 4.5, Sonnet 4.6 and older); Claude 4.7 and later
 * (Opus 4.7+, Sonnet 5, Fable), whose tokenizer produces about 30 % more tokens
 * for the same text. Custom gateways serve OpenAI models.
 */
function pricing_token_family(string $provider, string $model): string
{
    if ($provider !== 'anthropic') {
        return 'openai';
    }
    $m = strtolower($model);
    if (preg_match('/claude-(haiku|sonnet|opus)-(3|4-[0-6])(\b|-|$)/', $m) || str_starts_with($m, 'claude-3')) {
        return 'claude';
    }
    return 'claude-new';
}

/**
 * Estimate multipliers per tokenizer family. Calibrated on 2026-09-24 with two
 * real director prompts (5–6k tokens) and replies against the providers' own
 * counts: gpt-6-luna / gpt-5.6-luna, Claude Haiku 4.5 / Sonnet 4.6, and
 * Claude Sonnet 5 / Opus 5.5 / Fable 5.1 (count_tokens). Remaining error ±5 %.
 */
const PRICING_TOKEN_FACTORS = ['openai' => 0.93, 'claude' => 1.03, 'claude-new' => 1.42];

/**
 * Estimate the tokens of a text without the provider's tokenizer. Counts word,
 * number, punctuation and whitespace pieces the way BPE tokenizers roughly
 * split them. Accurate to about ±10 % for English prose and the director's
 * JSON; assets/js/estimate.js implements the same rules for the browser.
 */
function pricing_estimate_tokens(string $text, string $family = 'openai'): int
{
    if ($text === '') {
        return 0;
    }
    if (!preg_match_all('/[\p{L}\p{M}]+|\p{N}+|[^\s\p{L}\p{M}\p{N}]+|\s+/u', $text, $m)) {
        return (int)ceil(strlen($text) / 4);
    }
    $tokens = 0.0;
    foreach ($m[0] as $piece) {
        $tokens += pricing_piece_tokens($piece);
    }
    return (int)round($tokens * (PRICING_TOKEN_FACTORS[$family] ?? 1.0));
}

/** Tokens of one piece (see pricing_estimate_tokens). */
function pricing_piece_tokens(string $p): float
{
    $len = function_exists('mb_strlen') ? mb_strlen($p, 'UTF-8') : strlen($p);
    if (preg_match('/^\s+$/u', $p)) {
        $nl = substr_count($p, "\n");
        return $nl > 0 ? ceil($nl / 2) : ($len > 1 ? 1 : 0);
    }
    if (preg_match('/^[\p{L}\p{M}]+$/u', $p)) {
        if (preg_match('/^[A-Za-z]+$/', $p)) {
            if ($len >= 2 && strtoupper($p) === $p) {
                return ceil($len / 3); // all caps (names in the prompts) split more
            }
            return $len <= 8 ? 1 : ($len <= 13 ? 2 : ceil($len / 5));
        }
        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $p)) {
            return ceil($len / 1.3); // Chinese, Japanese, Korean: about one token per character
        }
        return max(1, ceil($len / 4)); // accented and other non-Latin words
    }
    if (preg_match('/^\p{N}+$/u', $p)) {
        return ceil($len / 3);
    }
    $ascii = preg_match_all('/[\x21-\x7e]/', $p);
    return ceil($ascii / 3) + ceil(($len - $ascii) * 1.5);
}
