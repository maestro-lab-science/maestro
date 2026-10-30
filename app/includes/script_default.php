<?php
/**
 * script_default.php — the sample conversation a new study starts with: four
 * short opening lines of the default trio (Blue, Green, Red), so the editor in
 * Step 3 is never empty and shows what a line, a pause and a typing time look like.
 *
 * @author Marc Becker
 */

declare(strict_types=1);

/** The stored JSON of the default sample conversation. */
function default_conversation_script(): string
{
    $messages = [
        ['id' => 1, 'sender' => 'bot1', 'content' => 'Hello everyone!', 'typing_pct' => 40, 'typing_start' => 3, 'send_at' => 5, 'is_empty' => false],
        ['id' => 2, 'sender' => 'bot2', 'content' => 'hey', 'typing_pct' => 70, 'typing_start' => 6, 'send_at' => 10, 'is_empty' => false],
        ['id' => 3, 'sender' => 'bot3', 'content' => 'Hi! ready when you are', 'typing_pct' => 80, 'typing_start' => 11, 'send_at' => 15, 'is_empty' => false],
        ['id' => 4, 'sender' => 'bot1', 'content' => 'ok so do we all know the task? lets just throw ideas out and see what sticks', 'typing_pct' => 65, 'typing_start' => 19, 'send_at' => 26, 'is_empty' => false],
    ];
    return json_encode(['messages' => $messages], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
