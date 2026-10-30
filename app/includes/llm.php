<?php
/**
 * llm.php — one place that talks to language-model providers.
 *
 * Every model call in MAESTRO goes through here, whether it is the live
 * director rewriting a script for a participant or the settings-page assistant
 * editing text for a researcher. Three jobs:
 *
 *   1. Resolve which provider/model/key/effort a project uses
 *      → llm_provider_config()
 *   2. Turn an abstract request into the provider's wire format
 *      → llm_build_request(): OpenAI chat completions, OpenAI Responses
 *        (needed for streamed reasoning summaries), Anthropic Messages, or an
 *        OpenAI-compatible gateway such as Azure API Management.
 *   3. Execute it: streamed to the browser as Server-Sent Events
 *      → llm_stream(), or buffered → llm_call().
 *
 * Streaming contract with the browser (see assets/js/chat/director.js):
 *   - LlmSse::begin() sends HTTP 200 + text/event-stream IMMEDIATELY, before
 *     the model is contacted, followed by ": connected". While the model is
 *     silent, ": ping" comment lines go out every LLM_PING_SECONDS, so no
 *     browser or proxy ever sees an idle connection during a reasoning pause.
 *   - Callers emit their own data lines (LlmSse::send); this file never decides
 *     the payload shape. Failures after the stream began are reported in-band
 *     by the caller as data: {"error":"…","code":429} and then data: [DONE].
 *   - Comment lines (": …") carry no payload; besides "connected" and "ping"
 *     they include padding that pushes output through buffering proxies
 *     (LLM_SSE_PAD_BYTES). Browsers ignore comments by definition.
 *
 * The same events also travel over the WebSocket relay: there this code runs in
 * command-line PHP (app/relay/worker.php), stdout carries the lines, and
 * LlmSse::aborted() watches stdin instead of the browser connection.
 *
 * Provider keys never leave the server; nothing here echoes a key back.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/config.php';

const LLM_PROVIDERS    = ['openai', 'anthropic', 'custom'];
const LLM_EFFORTS      = ['default', 'off', 'none', 'minimal', 'low', 'medium', 'high'];
const LLM_FORMATS      = ['auto', 'chat', 'responses']; // request shape for OpenAI-style providers
const LLM_PING_SECONDS = 3;    // keep-alive cadence while the upstream is silent (also how fast a vanished browser is noticed)
const LLM_CONNECT_S    = 10;   // TCP connect timeout to the provider
const LLM_STREAM_S     = 150;  // hard cap for one streamed director call (the participant is waiting)
const LLM_ASSIST_S     = 600;  // hard cap for one settings-assistant call (writing a whole script takes minutes)
const LLM_STALL_S      = 90;   // give up when a stream delivers nothing at all for this long
const LLM_CALL_S       = 60;   // hard cap for one buffered model call
const LLM_NO_USAGE     = ['input' => 0, 'output' => 0, 'cached' => 0, 'cache_write' => 0];

// ---------------------------------------------------------------------------
// 1. Which model does this project use?
// ---------------------------------------------------------------------------

/**
 * Resolve provider, model, key, endpoint and effort for a project.
 *
 * $purpose picks the effort setting: 'director' → director_effort (the live
 * chat, where every second of thinking is dead air for the participant),
 * 'assistant' → reasoning_effort (the fallback for the settings-page assistants;
 * their panels normally send an explicit effort_override).
 * Project values win; empty ones fall back to the server-wide defaults in
 * config.php / config.local.php (a key only for SERVER_KEY_PROVIDERS).
 *
 * The request format ('auto' | 'chat' | 'responses') is a per-project choice:
 * some gateways deliver chat completions in one block instead of streaming,
 * while their Responses route streams properly (see README).
 *
 * @return array{provider:string, model:string, key:string, endpoint:string, effort:string, format:string}
 */
function llm_provider_config(array $project, string $purpose = 'director'): array
{
    $provider = (string)($project['api_provider'] ?? '');
    if (!in_array($provider, LLM_PROVIDERS, true)) {
        $provider = 'openai';
    }
    $model    = trim((string)($project['model'] ?? ''));
    $key      = trim((string)($project['api_key'] ?? ''));
    $endpoint = trim((string)($project['api_endpoint'] ?? ''));
    $effort   = $purpose === 'director'
        ? trim((string)($project['director_effort'] ?? 'minimal'))
        : trim((string)($project['reasoning_effort'] ?? 'medium'));
    if (!in_array($effort, LLM_EFFORTS, true)) {
        $effort = $purpose === 'director' ? 'minimal' : 'medium';
    }
    $format = trim((string)($project['api_format'] ?? 'auto'));
    if (!in_array($format, LLM_FORMATS, true)) {
        $format = 'auto';
    }

    switch ($provider) {
        case 'anthropic':
            $model = $model !== '' ? $model : ANTHROPIC_MODEL;
            break;
        case 'custom':
            $model    = $model !== '' ? $model : CUSTOM_LLM_MODEL;
            $endpoint = $endpoint !== '' ? $endpoint : llm_server_endpoint();
            break;
        default:
            $model = $model !== '' ? $model : OPENAI_MODEL;
    }
    $key = $key !== '' ? $key : llm_server_key($provider);
    return ['provider' => $provider, 'model' => $model, 'key' => $key, 'endpoint' => $endpoint, 'effort' => $effort, 'format' => $format];
}

/**
 * The server-wide key of a provider from config.local.php, or '' when there is
 * none or the provider is not in SERVER_KEY_PROVIDERS (studies may not use it).
 */
function llm_server_key(string $provider): string
{
    if (!in_array($provider, SERVER_KEY_PROVIDERS, true)) {
        return '';
    }
    return trim((string)(['openai' => OPENAI_API_KEY, 'anthropic' => ANTHROPIC_API_KEY, 'custom' => CUSTOM_LLM_KEY][$provider] ?? ''));
}

/** The server-wide gateway endpoint, or '' when the gateway is not in SERVER_KEY_PROVIDERS. */
function llm_server_endpoint(): string
{
    return in_array('custom', SERVER_KEY_PROVIDERS, true) ? trim((string)CUSTOM_LLM_ENDPOINT) : '';
}

/** The error of a call the browser stopped waiting for, in plain words (not cURL's). */
const LLM_CANCELLED = 'Cancelled by the browser (a newer message, Stop, or the page was closed)';

/** Why a configuration cannot be used, or '' when it is fine. */
function llm_config_problem(array $cfg): string
{
    if ($cfg['key'] === '') {
        return 'No API key configured for provider "' . $cfg['provider'] . '". Add an API key in the study settings (AI model) or in includes/config.local.php.';
    }
    if ($cfg['provider'] === 'custom' && $cfg['endpoint'] === '') {
        return 'The custom provider needs an endpoint URL. Add the endpoint URL in the study settings (AI model).';
    }
    // Only web addresses: the key test sends whatever is in the form, and cURL
    // would otherwise also read file:// URLs or speak gopher:// to local services.
    if ($cfg['provider'] === 'custom' && !preg_match('#^https?://#i', $cfg['endpoint'])) {
        return 'The endpoint URL must start with https:// (or http:// for local testing).';
    }
    return '';
}

/** cURL may speak HTTP(S) only, whatever URL a configuration holds. */
function llm_curl_protocols($ch): void
{
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
    } else {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
}

// ---------------------------------------------------------------------------
// 2. Wire formats
// ---------------------------------------------------------------------------

/**
 * The reasoning_effort value an OpenAI-style model accepts for a chosen effort.
 *
 * OpenAI renamed its lowest setting between generations: gpt-5, gpt-5-mini and
 * the o-series accept "minimal" and reject "none"; gpt-5.1 and newer accept
 * "none" and reject "minimal". Researchers pick one "fastest" option in the
 * UI and this maps it to whatever the model understands. Models this guess
 * gets wrong are corrected at run time by llm_effort_fallback().
 *
 * @return string|null  null = omit the parameter (effort 'default')
 */
function llm_openai_effort(string $effort, string $model): ?string
{
    if ($effort === 'default') {
        return null;
    }
    $newScale = (bool)preg_match('/^gpt-(5\.\d|[6-9]|\d{2})/i', $model);
    // The largest models skip "none" altogether (gpt-6-astra starts at "low").
    $lowest = preg_match('/^gpt-\d+(\.\d+)?-astra/i', $model) ? 'low' : ($newScale ? 'none' : 'minimal');
    return match ($effort) {
        'off', 'none', 'minimal' => $lowest,
        default                  => $effort, // low | medium | high
    };
}

/** True for OpenAI models that take a reasoning effort: gpt-5 and newer and the o-series (also as fine-tunes, "ft:…"). */
function llm_openai_reasons(string $model): bool
{
    return (bool)preg_match('/^(ft:)?(gpt-(5|[6-9]|\d{2})|o\d)/i', $model);
}

/**
 * Read the provider's complaint about a reasoning effort and pick the value to
 * retry with: the closest supported one ("'minimal' … Supported values are:
 * 'none', 'low', …" → none), or 'default' (drop the parameter) for models that
 * do not reason at all. Null when the error is about something else.
 */
function llm_effort_fallback(string $message): ?string
{
    if (preg_match('/[\'"]reasoning[._]effort[\'"] is not supported/i', $message)) {
        return 'default';
    }
    if (!preg_match('/does not support [\'"]([a-z]+)[\'"].*?supported values are:?\s*(.+)/is', $message, $m)) {
        return null;
    }
    $rank = ['none' => 0, 'minimal' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'xhigh' => 5];
    preg_match_all('/[\'"]([a-z]+)[\'"]/i', $m[2], $found);
    $supported = array_values(array_filter(array_map('strtolower', $found[1]), fn($v) => isset($rank[$v])));
    if ($supported === []) {
        return null;
    }
    $want = $rank[strtolower($m[1])] ?? 1;
    usort($supported, fn($a, $b) => (abs($rank[$a] - $want) <=> abs($rank[$b] - $want)) ?: ($rank[$a] <=> $rank[$b]));
    return $supported[0];
}

/**
 * The same request with a different reasoning effort ('default' removes the
 * parameter). Null for wires without such a parameter (Anthropic).
 */
function llm_request_with_effort(array $req, string $effort): ?array
{
    $body = json_decode($req['body'], true);
    if (!is_array($body)) {
        return null;
    }
    if ($req['wire'] === 'openai-chat') {
        unset($body['reasoning_effort']);
        if ($effort !== 'default') {
            $body['reasoning_effort'] = $effort;
        }
    } elseif ($req['wire'] === 'openai-responses') {
        $reasoning = is_array($body['reasoning'] ?? null) ? $body['reasoning'] : [];
        unset($reasoning['effort']);
        if ($effort !== 'default') {
            $reasoning['effort'] = $effort;
        }
        unset($body['reasoning']);
        if ($reasoning !== []) {
            $body['reasoning'] = $reasoning;
        }
    } else {
        return null;
    }
    $req['body'] = json_encode($body);
    return $req;
}

/**
 * The URL of a custom (OpenAI-compatible) gateway for one kind of request.
 *
 * A bare base URL gets the route appended — the Azure OpenAI deployments path
 * for chat completions, /openai/v1/responses for the Responses API. An endpoint
 * that already names a route is used as configured, with "{model}" filled in;
 * a chat URL is trimmed back to the base when the Responses route is wanted.
 */
function llm_custom_url(string $endpoint, string $kind, string $model): string
{
    $url = rtrim($endpoint, '/');
    if ($kind === 'responses') {
        if (stripos($url, 'responses') === false) {
            $cut = stripos($url, '/openai/');
            $url = ($cut !== false ? substr($url, 0, $cut) : $url) . '/openai/v1/responses';
        }
    } elseif (strpos($url, 'chat/completions') === false) {
        $url .= '/openai/deployments/' . rawurlencode($model) . '/chat/completions?api-version=' . rawurlencode(CUSTOM_LLM_API_VERSION);
    }
    return str_replace('{model}', rawurlencode($model), $url);
}

/**
 * How a Claude model takes its thinking settings. They changed across
 * generations, and a setting one model needs is rejected by another:
 *
 *   'budget'    Haiku 4.5, Sonnet/Opus 4.5 and older: thinking with a token budget
 *   'adaptive'  Opus 4.6–4.8, Sonnet 4.6: adaptive thinking plus an effort level;
 *               without the thinking parameter they do not think
 *   'disable'   Sonnet 5, Opus 5: adaptive by default; switched off explicitly
 *   'always'    Opus 5.5, Fable 5 / 5.1 (and Mythos): thinking cannot be switched
 *               off, the lowest effort ("low") is the fastest they get
 */
function llm_anthropic_thinking_style(string $model): string
{
    $m = strtolower($model);
    if (preg_match('/^claude-(opus-5-5|fable|mythos)/', $m)) {
        return 'always';
    }
    if (preg_match('/^claude-(sonnet-5|opus-5)(-|$)/', $m)) {
        return 'disable';
    }
    if (preg_match('/^claude-(opus|sonnet)-4-[6-9]/', $m)) {
        return 'adaptive';
    }
    return 'budget';
}

/**
 * Apply the project's effort (off | low | medium | high) to a Claude request
 * body in the form the model accepts (see llm_anthropic_thinking_style).
 * $summary: show a readable summary of the thinking (the settings assistants
 * stream it; newer models return empty thinking text unless asked).
 */
function llm_anthropic_thinking(array $body, string $model, string $effort, bool $summary): array
{
    $thinks = in_array($effort, ['low', 'medium', 'high'], true);
    $adaptive = ['type' => 'adaptive'] + ($summary ? ['display' => 'summarized'] : []);
    switch (llm_anthropic_thinking_style($model)) {
        case 'budget':
            $budgets = ['low' => 2048, 'medium' => 8192, 'high' => 16384];
            if ($thinks) {
                $body['thinking']   = ['type' => 'enabled', 'budget_tokens' => $budgets[$effort]];
                $body['max_tokens'] = $budgets[$effort] + 16000; // must exceed the budget
            }
            break;
        case 'adaptive':
            if ($thinks) {
                $body['thinking']      = $adaptive;
                $body['output_config'] = ['effort' => $effort];
                $body['max_tokens']    = 32000;
            }
            break;
        case 'disable':
            if ($thinks) {
                $body['thinking']      = $adaptive;
                $body['output_config'] = ['effort' => $effort];
                $body['max_tokens']    = 32000;
            } elseif ($effort !== 'default') {
                $body['thinking'] = ['type' => 'disabled'];
            }
            break;
        default: // 'always'
            if ($effort !== 'default') {
                $body['output_config'] = ['effort' => $thinks ? $effort : 'low'];
            }
            if ($summary) {
                $body['thinking'] = $adaptive;
            }
            $body['max_tokens'] = 32000;
    }
    return $body;
}

/**
 * Build the HTTP request for one model call.
 *
 * $messages: list of ['role' => system|user|assistant, 'content' => text].
 * $opts:
 *   stream            (bool) stream tokens — default true
 *   reasoning_summary (bool) OpenAI only: use the Responses API so reasoning
 *                            summaries stream alongside the text — default false
 *
 * @return array{url:string, headers:string[], body:string, wire:string}
 *   'wire' is 'openai-chat' | 'openai-responses' | 'anthropic' and tells the
 *   stream reader how to interpret the provider's events.
 */
function llm_build_request(array $cfg, array $messages, array $opts = []): array
{
    $stream   = $opts['stream'] ?? true;
    $provider = $cfg['provider'];
    $effort   = $cfg['effort'];

    // Split the system prompt from the conversation turns (needed by two wires).
    $system = '';
    $turns  = [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') {
            $system .= ($system !== '' ? "\n\n" : '') . (string)$m['content'];
        } else {
            $turns[] = ['role' => (string)$m['role'], 'content' => (string)$m['content']];
        }
    }

    if ($provider === 'anthropic') {
        $body = ['model' => $cfg['model'], 'max_tokens' => 16000, 'messages' => $turns, 'stream' => $stream];
        if ($system !== '') {
            $body['system'] = $system;
        }
        $body = llm_anthropic_thinking($body, $cfg['model'], $effort, !empty($opts['reasoning_summary']));
        return [
            'url'     => 'https://api.anthropic.com/v1/messages',
            'headers' => ['Content-Type: application/json', 'x-api-key: ' . $cfg['key'], 'anthropic-version: 2023-06-01'],
            'body'    => json_encode($body),
            'wire'    => 'anthropic',
        ];
    }

    // Responses API: picked per project, or automatically when the caller wants
    // streamed reasoning summaries — the only surface that sends them.
    $wantsSummary = !empty($opts['reasoning_summary']);
    $format       = $cfg['format'] ?? 'auto';
    if ($format === 'responses' || ($format === 'auto' && $provider === 'openai' && $wantsSummary)) {
        $body = ['model' => $cfg['model'], 'input' => $turns, 'stream' => $stream, 'store' => false];
        if ($system !== '') {
            $body['instructions'] = $system;
        }
        // Reasoning-capable models, and every gateway model: a gateway may serve
        // reasoning models under deployment names of its own (as with chat
        // completions below), so the effort is sent and llm_effort_fallback()
        // handles a rejection. Reasoning summaries only for models known to reason.
        $reasons = llm_openai_reasons($cfg['model']);
        if ($reasons || $provider !== 'openai') {
            $reasoning = $wantsSummary && $reasons ? ['summary' => 'auto'] : [];
            if (($eff = llm_openai_effort($effort, $cfg['model'])) !== null) {
                $reasoning['effort'] = $eff;
            }
            if ($reasoning !== []) {
                $body['reasoning'] = $reasoning;
            }
        }
        return [
            'url'     => $provider === 'custom'
                ? llm_custom_url($cfg['endpoint'], 'responses', $cfg['model'])
                : 'https://api.openai.com/v1/responses',
            'headers' => $provider === 'custom'
                ? ['Content-Type: application/json', 'Ocp-Apim-Subscription-Key: ' . $cfg['key'], 'api-key: ' . $cfg['key']]
                : ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']],
            'body'    => json_encode($body),
            'wire'    => 'openai-responses',
        ];
    }

    // OpenAI chat completions — also the wire format of Azure and compatible gateways.
    $body = ['model' => $cfg['model'], 'messages' => $messages, 'stream' => $stream];
    if ($stream) {
        // Token usage arrives in one last chunk only when asked for (llm_stream
        // drops the option again for a gateway that rejects it).
        $body['stream_options'] = ['include_usage' => true];
    }
    // Models that do not reason (gpt-4.1, …) reject the parameter, and sending
    // it anyway would cost a failed request and a retry on every call. A
    // gateway may serve reasoning models under deployment names of its own, so
    // there the parameter is sent and llm_effort_fallback() handles a rejection.
    if ($provider !== 'openai' || llm_openai_reasons($cfg['model'])) {
        if (($eff = llm_openai_effort($effort, $cfg['model'])) !== null) {
            $body['reasoning_effort'] = $eff;
        }
    }
    if ($provider === 'custom') {
        return [
            'url'     => llm_custom_url($cfg['endpoint'], 'chat', $cfg['model']),
            'headers' => ['Content-Type: application/json', 'Ocp-Apim-Subscription-Key: ' . $cfg['key'], 'api-key: ' . $cfg['key']],
            'body'    => json_encode($body),
            'wire'    => 'openai-chat',
        ];
    }
    return [
        'url'     => 'https://api.openai.com/v1/chat/completions',
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']],
        'body'    => json_encode($body),
        'wire'    => 'openai-chat',
    ];
}

/**
 * Interpret one decoded upstream stream event.
 * @return array{0:string, 1:string, 2:bool} [text delta, reasoning delta, stream finished]
 */
function llm_extract_delta(string $wire, array $obj): array
{
    if ($wire === 'anthropic') {
        $type = (string)($obj['type'] ?? '');
        if ($type === 'content_block_delta') {
            $d = $obj['delta'] ?? [];
            if (($d['type'] ?? '') === 'thinking_delta') {
                return ['', (string)($d['thinking'] ?? ''), false];
            }
            return [(string)($d['text'] ?? ''), '', false];
        }
        return ['', '', $type === 'message_stop'];
    }
    if ($wire === 'openai-responses') {
        $type = (string)($obj['type'] ?? '');
        if ($type === 'response.output_text.delta') {
            return [(string)($obj['delta'] ?? ''), '', false];
        }
        if ($type === 'response.reasoning_summary_text.delta') {
            return ['', (string)($obj['delta'] ?? ''), false];
        }
        if ($type === 'response.reasoning_summary_part.added') {
            return ['', "\n\n", false];
        }
        return ['', '', $type === 'response.completed'];
    }
    // openai-chat and compatible gateways
    $choice = $obj['choices'][0] ?? [];
    return [(string)($choice['delta']['content'] ?? ''), '', ($choice['finish_reason'] ?? null) !== null];
}

/**
 * A failure the provider reports as an event inside an HTTP 200 stream, as
 * its error object, or null for any other event. Anthropic sends overloads
 * and similar failures as {"type":"error","error":{…}} and then closes the
 * stream; the Responses API sends "error" and "response.failed"; a gateway
 * may put a chat-completions {"error":…} object in place of a chunk.
 */
function llm_extract_stream_error(string $wire, array $obj): ?array
{
    if ($wire === 'openai-responses') {
        $type = (string)($obj['type'] ?? '');
        if ($type !== 'error' && $type !== 'response.failed') {
            return null;
        }
        $err = $obj['error'] ?? ($obj['response']['error'] ?? null);
        return is_array($err) ? $err : ['message' => 'The model reported a failure (' . $type . ').'];
    }
    if ($wire === 'anthropic') {
        if ((string)($obj['type'] ?? '') !== 'error') {
            return null;
        }
        return is_array($obj['error'] ?? null) ? $obj['error'] : ['message' => 'The model reported a failure.'];
    }
    return is_array($obj['error'] ?? null) && !isset($obj['choices']) ? $obj['error'] : null;
}

/**
 * Token usage carried by one stream event or a buffered body, if any:
 *   input        every input token, including the cached part
 *   output       every output token, including thinking/reasoning
 *   cached       input read from the provider's prompt cache (billed cheaper)
 *   cache_write  input written to the prompt cache (billed extra on some models)
 * Streams report usage in pieces (Anthropic: input at message_start, output at
 * message_delta; OpenAI: one final chunk), which llm_merge_usage() combines.
 */
function llm_extract_usage(string $wire, array $obj): ?array
{
    if ($wire === 'anthropic') {
        $u = $obj['usage'] ?? ($obj['message']['usage'] ?? null);
        if (!is_array($u)) {
            return null;
        }
        $read  = (int)($u['cache_read_input_tokens'] ?? 0);
        $write = (int)($u['cache_creation_input_tokens'] ?? 0);
        return ['input' => (int)($u['input_tokens'] ?? 0) + $read + $write, 'output' => (int)($u['output_tokens'] ?? 0),
                'cached' => $read, 'cache_write' => $write];
    }
    if ($wire === 'openai-responses') {
        $u = $obj['response']['usage'] ?? ($obj['usage'] ?? null);
        if (!is_array($u)) {
            return null;
        }
        $d = is_array($u['input_tokens_details'] ?? null) ? $u['input_tokens_details'] : [];
        return ['input' => (int)($u['input_tokens'] ?? 0), 'output' => (int)($u['output_tokens'] ?? 0),
                'cached' => (int)($d['cached_tokens'] ?? 0), 'cache_write' => (int)($d['cache_write_tokens'] ?? 0)];
    }
    $u = $obj['usage'] ?? null;
    if (!is_array($u)) {
        return null;
    }
    $d = is_array($u['prompt_tokens_details'] ?? null) ? $u['prompt_tokens_details'] : [];
    return ['input' => (int)($u['prompt_tokens'] ?? 0), 'output' => (int)($u['completion_tokens'] ?? 0),
            'cached' => (int)($d['cached_tokens'] ?? 0), 'cache_write' => (int)($d['cache_write_tokens'] ?? 0)];
}

/** Combine usage pieces of one stream (each count only grows). */
function llm_merge_usage(?array $a, ?array $b): ?array
{
    if ($b === null) {
        return $a;
    }
    if ($a === null) {
        return $b;
    }
    foreach ($b as $k => $v) {
        $a[$k] = max((int)($a[$k] ?? 0), (int)$v);
    }
    return $a;
}

/** The same request without stream_options (for gateways that reject the option). Null when absent. */
function llm_request_without_stream_options(array $req): ?array
{
    $body = json_decode($req['body'], true);
    if (!is_array($body) || !isset($body['stream_options'])) {
        return null;
    }
    unset($body['stream_options']);
    $req['body'] = json_encode($body);
    return $req;
}

/** Text of a buffered (non-streamed) response body. */
function llm_extract_content(string $wire, array $data): string
{
    if ($wire === 'anthropic') {
        $out = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $out .= (string)($block['text'] ?? '');
            }
        }
        return $out;
    }
    if ($wire === 'openai-responses') {
        $out = '';
        foreach (($data['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $part) {
                if (($part['type'] ?? '') === 'output_text') {
                    $out .= (string)($part['text'] ?? '');
                }
            }
        }
        return $out;
    }
    return (string)($data['choices'][0]['message']['content'] ?? '');
}

/**
 * What to tell the researcher when a stream ended before the model was done.
 * The two timeout cases need different advice, and cURL's own wording
 * ("Operation timed out after 150000 milliseconds…") explains neither.
 */
function llm_stream_error(string $curlErr, int $timeout, int $stall, int $received): string
{
    $tooSlow = stripos($curlErr, 'too slow') !== false || stripos($curlErr, 'less than') !== false;
    if (!$tooSlow && stripos($curlErr, 'timed out') === false) {
        return 'Stream interrupted: ' . $curlErr;
    }
    if ($tooSlow) {
        return 'The model stopped sending anything for ' . $stall . ' seconds, so the request was cancelled. Please try again.';
    }
    $waited = $timeout >= 120 ? round($timeout / 60) . ' minutes' : $timeout . ' seconds';
    if ($received === 0) {
        return 'The model sent nothing within ' . $waited . ', so the request was cancelled. Please try again.';
    }
    return 'The model was still writing after ' . $waited . ', so the answer was cut off and nothing was changed. '
        . 'Try a shorter request (for example one part of the conversation at a time) or a lower thinking effort.';
}

/** Provider error message from an error body, or a generic one. */
function llm_error_message(string $body, int $status): string
{
    $err = json_decode($body, true);
    if (is_array($err)) {
        $m = $err['error']['message'] ?? ($err['error'] ?? ($err['message'] ?? null));
        if (is_string($m) && trim($m) !== '') {
            return trim($m);
        }
    }
    return $status > 0 ? 'Upstream error ' . $status : 'Upstream connection failed';
}

// ---------------------------------------------------------------------------
// 3a. Server-Sent Events towards the browser
// ---------------------------------------------------------------------------

// Web servers between PHP and the browser may hold output until a buffer fills
// (Apache's mod_proxy_fcgi on Uberspace releases it in ~4 KB blocks, and
// flush() cannot change that). A comment of this many bytes after a write
// pushes everything before it out to the browser; gzip shrinks it to a few
// bytes on the wire. Define it as 0 in config.local.php on hosts that pass
// flushes straight through.
if (!defined('LLM_SSE_PAD_BYTES')) {
    define('LLM_SSE_PAD_BYTES', 4200);
}
const LLM_SSE_PUSH_INTERVAL = 0.15; // seconds between pushes while tokens stream

/** The SSE channel to the browser: begin once, send data lines, keep it warm. */
final class LlmSse
{
    private static bool $begun = false;
    private static float $lastWrite  = 0.0; // anything written (ping cadence)
    private static float $lastPushed = 0.0; // last write that was padded through the proxy buffer

    /** Send the headers and a first comment so the browser sees bytes immediately. */
    public static function begin(): void
    {
        if (self::$begun) {
            return;
        }
        self::$begun = true;
        while (ob_get_level() > 0) {
            ob_end_flush(); // no buffering inside PHP
        }
        http_response_code(200);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        self::write(": connected\n\n", true);
    }

    /**
     * One data line with a JSON payload. Deltas are pushed through the proxy
     * buffer at most every LLM_SSE_PUSH_INTERVAL seconds (so a token waits at
     * most that long, and the padding stays a fraction of the traffic); pass
     * $now = true for anything the browser must see immediately.
     */
    public static function send(array $obj, bool $now = false): void
    {
        $push = $now || microtime(true) - self::$lastPushed >= LLM_SSE_PUSH_INTERVAL;
        self::write('data: ' . json_encode($obj, JSON_UNESCAPED_UNICODE) . "\n\n", $push);
    }

    /** Comment line without payload; keeps the connection alive. */
    public static function ping(): void
    {
        self::write(": ping\n\n", true);
    }

    /**
     * Whether the receiving side went away. Behind a web server that is the
     * browser (connection_aborted(), noticed on the next write). Under the
     * WebSocket relay (app/relay/, command-line PHP) the relay keeps this
     * process's stdin open for as long as the browser wants the result and
     * closes it on cancel or disconnect.
     */
    public static function aborted(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return (bool)connection_aborted();
        }
        static $closed = false, $nonBlocking = false;
        if ($closed || !defined('STDIN')) {
            return $closed;
        }
        if (!$nonBlocking) {
            stream_set_blocking(STDIN, false);
            $nonBlocking = true;
        }
        $chunk = @fread(STDIN, 256); // the relay never writes; only EOF matters
        if (($chunk === '' || $chunk === false) && feof(STDIN)) {
            $closed = true;
        }
        return $closed;
    }

    /**
     * Called regularly while waiting on the model: pushes out deltas that are
     * still sitting in a proxy buffer, and pings when the browser has heard
     * nothing for LLM_PING_SECONDS. A failed write here is also how a browser
     * that went away is noticed (aborted()).
     */
    public static function keepalive(): void
    {
        $now = microtime(true);
        if (self::$lastWrite > self::$lastPushed && $now - self::$lastWrite >= LLM_SSE_PUSH_INTERVAL) {
            self::write('', true);
        } elseif ($now - self::$lastWrite > LLM_PING_SECONDS) {
            self::ping();
        }
    }

    /** Terminate the stream; nothing may be written afterwards. */
    public static function done(): void
    {
        self::write("data: [DONE]\n\n", true);
    }

    private static function write(string $chunk, bool $push): void
    {
        echo $chunk;
        if ($push) {
            if (LLM_SSE_PAD_BYTES > 0) {
                echo ': ' . str_repeat(' ', LLM_SSE_PAD_BYTES) . "\n\n";
            }
            self::$lastPushed = microtime(true);
        }
        flush();
        self::$lastWrite = microtime(true);
    }
}

// ---------------------------------------------------------------------------
// 3b. Execution
// ---------------------------------------------------------------------------

/**
 * Stream one model call. The SSE channel must already be open (LlmSse::begin).
 *
 * $handlers: 'text' => fn(string $delta), 'reasoning' => fn(string $delta).
 * The handlers decide what reaches the browser; this function only relays,
 * pings while the model is silent, stops when the browser disconnects, and —
 * as long as no text has arrived — retries once after a transient failure
 * (5xx, 429, connection error) and once with a corrected reasoning effort
 * when the model rejects the configured one ('effort' in the result).
 *
 * $opts: 'timeout' — hard cap in seconds (default LLM_STREAM_S; the assistants
 * pass LLM_ASSIST_S, because a whole conversation script takes minutes to
 * write), 'stall' — abort when the provider sends nothing at all for this many
 * seconds (default LLM_STALL_S), which ends a hung connection long before the
 * hard cap without cutting off a model that is still writing.
 *
 * 'usage' holds the provider's token counts (see llm_extract_usage);
 * 'usage_reported' is false when the provider sent none (for example a call
 * cancelled before its last event), and the counts are then zero.
 *
 * @return array{ok:bool, status:int, content:string, usage:array{input:int,output:int,cached:int,cache_write:int},
 *               usage_reported:bool, error:string, first_token_ms:?int, duration_ms:int, attempts:int, effort:?string}
 */
function llm_stream(array $req, array $handlers = [], array $opts = []): array
{
    $timeout   = max(5, (int)($opts['timeout'] ?? LLM_STREAM_S));
    $stall     = max(5, (int)($opts['stall'] ?? LLM_STALL_S));
    $t0        = microtime(true);
    $onText    = $handlers['text'] ?? null;
    $onReason  = $handlers['reasoning'] ?? null;
    $wire      = $req['wire'];
    $result    = ['ok' => false, 'status' => 0, 'content' => '', 'usage' => LLM_NO_USAGE, 'usage_reported' => false,
                  'error' => '', 'first_token_ms' => null, 'duration_ms' => 0, 'attempts' => 0, 'effort' => null];

    $retriedTransient = false;
    $retriedEffort    = false;
    $retriedOptions   = false;
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $result['attempts'] = $attempt;
        $status   = 0;
        $errBody  = '';
        $lineBuf  = '';
        $content  = '';
        $usage    = null;
        $firstAt  = null;
        $finished = false;
        $streamError = '';

        $ch = curl_init($req['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $req['body'],
            CURLOPT_HTTPHEADER     => $req['headers'],
            CURLOPT_CONNECTTIMEOUT => LLM_CONNECT_S,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_LOW_SPEED_LIMIT => 1,      // …and stop earlier when the
            CURLOPT_LOW_SPEED_TIME  => $stall, // provider has gone quiet entirely
            CURLOPT_NOPROGRESS     => false,
        ]);
        llm_curl_protocols($ch);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $h) use (&$status) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
            }
            return strlen($h);
        });
        // Called regularly even without upstream bytes: keep the browser warm,
        // and stop the upstream call when the browser went away.
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function () {
            LlmSse::keepalive();
            return LlmSse::aborted() ? 1 : 0;
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $data) use (
            &$status, &$errBody, &$lineBuf, &$content, &$usage, &$firstAt, &$finished, &$streamError, $wire, $onText, $onReason, $t0
        ) {
            if ($status >= 400) {
                $errBody .= $data;
                return strlen($data);
            }
            $lineBuf .= $data;
            while (($nl = strpos($lineBuf, "\n")) !== false) {
                $line    = trim(substr($lineBuf, 0, $nl));
                $lineBuf = substr($lineBuf, $nl + 1);
                if ($line === '' || strncmp($line, 'data:', 5) !== 0) {
                    continue; // "event:" lines, comments, keep-alives
                }
                $payload = trim(substr($line, 5));
                if ($payload === '[DONE]') {
                    $finished = true;
                    continue;
                }
                $obj = json_decode($payload, true);
                if (!is_array($obj)) {
                    continue;
                }
                // A failure the provider reports inside the stream (see
                // llm_extract_stream_error): the stream ends without a result.
                if (($failure = llm_extract_stream_error($wire, $obj)) !== null) {
                    $streamError = json_encode(['error' => $failure]);
                    $finished    = true;
                    continue;
                }
                if ($wire === 'openai-responses' && (string)($obj['type'] ?? '') === 'response.incomplete') {
                    // The model stopped early (token limit, content filter): a reply
                    // that has begun is taken as it is; without one it is a failure.
                    // Either way the event carries the tokens that were billed.
                    $usage = llm_merge_usage($usage, llm_extract_usage($wire, $obj));
                    if ($content === '') {
                        $reason      = (string)($obj['response']['incomplete_details']['reason'] ?? 'response.incomplete');
                        $streamError = json_encode(['error' => ['message' => 'The model stopped before it answered (' . $reason . ').']]);
                    }
                    $finished = true;
                    continue;
                }
                $usage = llm_merge_usage($usage, llm_extract_usage($wire, $obj));
                [$text, $reasoning, $stop] = llm_extract_delta($wire, $obj);
                if ($text !== '') {
                    if ($firstAt === null) {
                        $firstAt = (int)round((microtime(true) - $t0) * 1000);
                    }
                    $content .= $text;
                    if ($onText) {
                        $onText($text);
                    }
                }
                if ($reasoning !== '' && $onReason) {
                    $onReason($reasoning);
                }
                if ($stop) {
                    $finished = true;
                }
            }
            return LlmSse::aborted() ? -1 : strlen($data);
        });

        curl_exec($ch);
        $curlErr  = curl_error($ch);
        $timedOut = curl_errno($ch) === 28; // CURLE_OPERATION_TIMEDOUT: the hard cap or the stall timer
        unset($ch); // PHP frees the handle itself; curl_close() is deprecated since 8.5

        if ($content === '' && $streamError !== '' && $status < 400) {
            $status  = 502; // the provider reported the failure inside the stream
            $errBody = $streamError;
        }

        if ($content === '') {
            // A timeout is not repeated: the participant (or the researcher) would wait the whole limit again.
            $transient = $status >= 500 || $status === 429 || ($status === 0 && $curlErr !== '' && !$timedOut);
            if ($transient && !$retriedTransient && !LlmSse::aborted()) { // no retry once the caller left
                $retriedTransient = true;
                usleep(random_int(1500, 2500) * 1000);
                continue;
            }
            if ($status === 400 && !$retriedOptions && stripos(llm_error_message($errBody, $status), 'stream_options') !== false) {
                $patched = llm_request_without_stream_options($req);
                if ($patched !== null) {
                    $retriedOptions = true;
                    $req            = $patched;
                    continue;
                }
            }
            if ($status === 400 && !$retriedEffort) {
                $fallback = llm_effort_fallback(llm_error_message($errBody, $status));
                $patched  = $fallback !== null ? llm_request_with_effort($req, $fallback) : null;
                if ($patched !== null) {
                    $retriedEffort    = true;
                    $req              = $patched;
                    $result['effort'] = $fallback;
                    continue;
                }
            }
        }

        $result['status']         = $status;
        $result['content']        = $content;
        $result['first_token_ms'] = $firstAt;
        $result['duration_ms']    = (int)round((microtime(true) - $t0) * 1000);
        // Counts are only complete when the provider closed the stream itself:
        // Anthropic reports the input (and one output token) at the start, so a
        // call cancelled midway would otherwise be costed as a one-token reply.
        if ($usage && $finished && $streamError === '') {
            $result['usage']          = array_merge(LLM_NO_USAGE, $usage);
            $result['usage_reported'] = true;
        }
        if ($status >= 400) {
            $result['error'] = llm_error_message($errBody, $status);
        } elseif ($content === '' && $curlErr !== '') {
            $result['error'] = stripos($curlErr, 'timed out') !== false || stripos($curlErr, 'too slow') !== false
                ? llm_stream_error($curlErr, $timeout, $stall, 0)
                : 'Upstream connection failed: ' . $curlErr;
        } elseif ($content === '') {
            $result['error'] = 'The model returned an empty response.';
        } elseif ($streamError !== '') {
            // The provider stopped the stream after the reply had begun: what
            // arrived is incomplete and must not pass as the whole answer.
            $result['status'] = 502;
            $result['error']  = llm_error_message($streamError, 502);
        } elseif (!$finished && $curlErr !== '') {
            $result['error'] = llm_stream_error($curlErr, $timeout, $stall, strlen($content));
        } else {
            $result['ok'] = true;
        }
        break;
    }
    return $result;
}

/**
 * Buffered (non-streamed) model call, retried once on transient failures.
 * $keepalive, when given, is invoked regularly while waiting — pass
 * [LlmSse::class, 'keepalive'] when this call runs inside an open SSE stream.
 *
 * @return array{ok:bool, status:int, content:string, usage:array{input:int,output:int,cached:int,cache_write:int},
 *               usage_reported:bool, error:string, duration_ms:int, effort:?string}
 */
function llm_call(array $req, int $timeoutSeconds = LLM_CALL_S, ?callable $keepalive = null): array
{
    $t0 = microtime(true);
    $result = ['ok' => false, 'status' => 0, 'content' => '', 'usage' => LLM_NO_USAGE, 'usage_reported' => false,
               'error' => '', 'duration_ms' => 0, 'effort' => null];

    $retriedTransient = false;
    $retriedEffort    = false;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init($req['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $req['body'],
            CURLOPT_HTTPHEADER     => $req['headers'],
            CURLOPT_CONNECTTIMEOUT => LLM_CONNECT_S,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        llm_curl_protocols($ch);
        if ($keepalive) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function () use ($keepalive) {
                $keepalive();
                return LlmSse::aborted() ? 1 : 0;
            });
        }
        $body     = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr  = curl_error($ch);
        $timedOut = curl_errno($ch) === 28; // CURLE_OPERATION_TIMEDOUT
        unset($ch); // PHP frees the handle itself; curl_close() is deprecated since 8.5

        // A timeout is not repeated: the caller would wait the whole limit again
        // (the key test would run past the host's 45-second limit for a response).
        $transient = ($body === false && !$timedOut) || $status >= 500 || $status === 429;
        if ($transient && !$retriedTransient && !LlmSse::aborted()) { // no retry once the caller left
            $retriedTransient = true;
            usleep(random_int(1000, 2000) * 1000);
            continue;
        }
        if ($status === 400 && !$retriedEffort) {
            $fallback = llm_effort_fallback(llm_error_message((string)$body, $status));
            $patched  = $fallback !== null ? llm_request_with_effort($req, $fallback) : null;
            if ($patched !== null) {
                $retriedEffort    = true;
                $req              = $patched;
                $result['effort'] = $fallback;
                continue;
            }
        }

        $result['status']      = $status;
        $result['duration_ms'] = (int)round((microtime(true) - $t0) * 1000);
        if ($body === false) {
            $result['error'] = $timedOut
                ? llm_stream_error($curlErr, $timeoutSeconds, $timeoutSeconds, 0)
                : 'Upstream connection failed: ' . ($curlErr ?: 'unknown error');
            break;
        }
        $data = json_decode((string)$body, true);
        if ($status !== 200 || !is_array($data)) {
            $result['error'] = llm_error_message((string)$body, $status);
            break;
        }
        $result['content'] = llm_extract_content($req['wire'], $data);
        if (($usage = llm_extract_usage($req['wire'], $data)) !== null) {
            $result['usage']          = $usage;
            $result['usage_reported'] = true;
        }
        $result['ok']      = trim($result['content']) !== '';
        if (!$result['ok']) {
            $result['error'] = 'The model returned an empty response.';
        }
        break;
    }
    return $result;
}
