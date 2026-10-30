<?php
/**
 * richtext.php — formatted text that researchers write and participants see.
 *
 * Used for the instructions shown above the chat (projects.chat_instructions,
 * written in the editor of settings Step 1, shown by aiTeam.php).
 *
 * The stored format is a small, fixed subset of HTML:
 *
 *   <p>…</p>  <ul>/<ol> with <li>  <br>
 *   <strong>  <em>  <u>  <span style="color:#rrggbb;font-size:13|18|24px">
 *
 * richtext_sanitize() turns any HTML into that format. It never copies
 * markup: it reads the input, keeps the text and the few kinds of formatting
 * above, and writes the result from scratch. Scripts, links, pictures, event
 * handlers and styles of other programs therefore cannot get through, whatever
 * a request contains. The text is cleaned when it is saved
 * (api/save_project.php) and again when it is shown, so the participant page
 * never prints HTML that did not pass through here.
 *
 * The browser has the same rules in assets/js/richtext.js (sanitizeRichText),
 * for the editor and for pasted content. The unit test
 * app/tests/richtext.test.mjs runs both on the same inputs, so a change on
 * one side that is not made on the other fails there.
 *
 * @author Marc Becker
 */

const RICHTEXT_MAX_CHARS     = 20000;   // the longest text that is stored (characters of the cleaned HTML)
const RICHTEXT_MAX_INPUT     = 100000;  // bytes of HTML a request may send before cleaning
const RICHTEXT_DEFAULT_COLOR = '#0f172a'; // the text color when none is chosen (--ink in app.css); never written into the text
const RICHTEXT_SIZES         = ['small' => 13, 'large' => 18, 'xl' => 24]; // pixels; "normal" is the surrounding size and never written

/** Clean formatted text: any HTML → the stored format (see the top of this file). */
function richtext_sanitize(string $html): string
{
    return (new RichTextCleaner())->clean($html);
}

/** The plain text of cleaned formatted text (lines joined with spaces). */
function richtext_text(string $clean): string
{
    $text = preg_replace('/<[^>]*>/', ' ', $clean) ?? '';
    $text = str_replace(['&nbsp;', "\u{00A0}", '&lt;', '&gt;', '&amp;'], [' ', ' ', '<', '>', '&'], $text);
    return trim(preg_replace('/[ \t\n\r\f]+/', ' ', $text) ?? '', " \t\n\r\f");
}

/** True when cleaned formatted text has nothing a reader could see. */
function richtext_is_empty(string $clean): bool
{
    return richtext_text($clean) === '';
}

/** Formatted text ready to store or print: cleaned, and '' when there is nothing to read. */
function richtext_render(string $html): string
{
    $clean = richtext_sanitize($html);
    return richtext_is_empty($clean) ? '' : $clean;
}

/** The length of formatted text in characters. */
function richtext_length(string $html): int
{
    return function_exists('mb_strlen') ? mb_strlen($html, 'UTF-8') : strlen($html);
}

/**
 * One cleaning run. The input is read tag by tag; open elements are kept on a
 * stack with the formatting that applies inside them, and the text is
 * collected as paragraphs and lists, which are written out at the end.
 */
final class RichTextCleaner
{
    /** Elements without content or end tag. */
    private const VOID = ['br' => 1, 'hr' => 1, 'img' => 1, 'input' => 1, 'meta' => 1, 'link' => 1, 'wbr' => 1, 'col' => 1, 'area' => 1,
        'base' => 1, 'embed' => 1, 'source' => 1, 'track' => 1, 'param' => 1];
    /** Elements whose content is not markup: everything up to their end tag is dropped. */
    private const RAW_TEXT = ['script' => 1, 'style' => 1, 'textarea' => 1, 'title' => 1, 'xmp' => 1, 'iframe' => 1, 'noembed' => 1,
        'noframes' => 1, 'plaintext' => 1];
    /** Elements that are dropped with everything inside them. */
    private const DROP = ['head' => 1, 'template' => 1, 'noscript' => 1, 'object' => 1, 'applet' => 1, 'svg' => 1, 'math' => 1,
        'canvas' => 1, 'audio' => 1, 'video' => 1, 'select' => 1, 'button' => 1, 'datalist' => 1, 'map' => 1, 'frameset' => 1, 'xml' => 1];
    /** Elements that start a new line; in the result each stretch of text between them is a paragraph. */
    private const BLOCK = ['address' => 1, 'article' => 1, 'aside' => 1, 'blockquote' => 1, 'body' => 1, 'caption' => 1, 'center' => 1,
        'dd' => 1, 'details' => 1, 'dialog' => 1, 'div' => 1, 'dl' => 1, 'dt' => 1, 'fieldset' => 1, 'figcaption' => 1, 'figure' => 1,
        'footer' => 1, 'form' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1, 'header' => 1, 'hgroup' => 1,
        'html' => 1, 'legend' => 1, 'main' => 1, 'nav' => 1, 'p' => 1, 'pre' => 1, 'section' => 1, 'summary' => 1, 'table' => 1,
        'tbody' => 1, 'tfoot' => 1, 'thead' => 1, 'tr' => 1];
    private const LISTS = ['ul' => 'ul', 'ol' => 'ol', 'menu' => 'ul', 'dir' => 'ul'];
    private const MAX_DEPTH = 60;     // open elements that are followed; deeper ones are read as plain content
    private const MAX_LIST_DEPTH = 3; // lists inside lists

    private const NAMED_ENTITIES = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'nbsp' => "\u{00A0}"];
    private const COLOR_NAMES = ['black' => '#000000', 'white' => '#ffffff', 'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff',
        'yellow' => '#ffff00', 'orange' => '#ffa500', 'purple' => '#800080', 'gray' => '#808080', 'grey' => '#808080', 'silver' => '#c0c0c0',
        'maroon' => '#800000', 'navy' => '#000080', 'teal' => '#008080', 'olive' => '#808000', 'lime' => '#00ff00', 'aqua' => '#00ffff',
        'cyan' => '#00ffff', 'fuchsia' => '#ff00ff', 'magenta' => '#ff00ff', 'pink' => '#ffc0cb', 'brown' => '#a52a2a'];
    private const SIZE_KEYWORDS = ['xx-small' => 'small', 'x-small' => 'small', 'small' => 'small', 'smaller' => 'small', 'medium' => 'normal',
        'large' => 'large', 'larger' => 'large', 'x-large' => 'xl', 'xx-large' => 'xl', 'xxx-large' => 'xl', '-webkit-xxx-large' => 'xl'];
    private const WS = " \t\n\r\f";
    private const BASE_FORMAT = ['b' => false, 'i' => false, 'u' => false, 'c' => null, 's' => null, 'key' => '||'];

    private stdClass $root;
    /** @var array<int, stdClass> open elements: { name, type: inline | block | list | li, format, list, item } */
    private array $stack = [];
    /** The paragraph being filled: { runs, container }. */
    private ?stdClass $para = null;
    /** @var array{name:string, depth:int}|null inside a dropped element */
    private ?array $skip = null;

    public function clean(string $html): string
    {
        if (preg_match('//u', $html) !== 1) {
            // Not valid UTF-8 (cannot come from the editor): drop the broken bytes.
            $html = function_exists('mb_convert_encoding')
                ? (string)mb_convert_encoding($html, 'UTF-8', 'UTF-8')
                : (string)@iconv('UTF-8', 'UTF-8//IGNORE', $html);
        }
        $this->root  = (object)['blocks' => []];
        $this->stack = [];
        $this->para  = null;
        $this->skip  = null;

        // ---- Read the HTML ----------------------------------------------------------
        $n = strlen($html);
        $i = 0;
        while ($i < $n) {
            $lt = strpos($html, '<', $i);
            $textEnd = $lt === false ? $n : $lt;
            if ($textEnd > $i && $this->skip === null) {
                $this->addText(substr($html, $i, $textEnd - $i));
            }
            if ($lt === false) {
                break;
            }
            $i = $lt;
            $next = $i + 1 < $n ? $html[$i + 1] : '';
            if (substr_compare($html, '<!--', $i, 4) === 0) {
                $end = strpos($html, '-->', $i + 4);
                $i = $end === false ? $n : $end + 3;
            } elseif ($next === '!' || $next === '?') {
                $end = strpos($html, '>', $i + 2);
                $i = $end === false ? $n : $end + 1;
            } elseif ($next === '/') {
                $found = preg_match('/^[a-zA-Z][^ \t\n\r\f\/>]*/', substr($html, $i + 2, 64), $m);
                $end = strpos($html, '>', $i + 2);
                $i = $end === false ? $n : $end + 1;
                if ($found) {
                    $this->close(strtolower($m[0]));
                }
            } elseif (($next >= 'a' && $next <= 'z') || ($next >= 'A' && $next <= 'Z')) {
                preg_match('/^[a-zA-Z][^ \t\n\r\f\/>]*/', substr($html, $i + 1, 64), $m);
                $name = strtolower($m[0]);
                // The end of the tag: the first ">" outside quotes.
                $j = $i + 1 + strlen($name);
                $attrsStart = $j;
                while ($j < $n) {
                    $j += strcspn($html, '>"\'', $j);
                    if ($j >= $n || $html[$j] === '>') {
                        break;
                    }
                    $q = strpos($html, $html[$j], $j + 1);
                    $j = $q === false ? $n : $q + 1;
                }
                $attrs = substr($html, $attrsStart, $j - $attrsStart);
                $i = $j < $n ? $j + 1 : $n;
                if (isset(self::RAW_TEXT[$name])) {
                    // Skip to the end tag; without one the rest is dropped.
                    $from = $i;
                    $i = $n;
                    while (($c = stripos($html, '</' . $name, $from)) !== false) {
                        $after = $html[$c + 2 + strlen($name)] ?? '';
                        if ($after === '' || $after === '>' || $after === '/' || strpos(self::WS, $after) !== false) {
                            $end = strpos($html, '>', $c);
                            $i = $end === false ? $n : $end + 1;
                            break;
                        }
                        $from = $c + 2;
                    }
                } else {
                    $this->open($name, $this->skip !== null ? [] : self::parseAttrs($attrs), str_ends_with($attrs, '/'));
                }
            } else {
                if ($this->skip === null) {
                    $this->addText('<');
                }
                $i++;
            }
        }
        $this->flush();

        // ---- Write the result -------------------------------------------------------
        $blocks = $this->root->blocks;
        while ($blocks && self::isBlank($blocks[0])) {
            array_shift($blocks);
        }
        while ($blocks && self::isBlank($blocks[count($blocks) - 1])) {
            array_pop($blocks);
        }
        $out = '';
        foreach ($blocks as $block) {
            $out .= $block->type === 'p' ? '<p>' . self::renderRuns($block->runs) . '</p>' : self::renderList($block);
        }
        return $out;
    }

    // ---- Collecting -----------------------------------------------------------------

    private function format(): array
    {
        return $this->stack ? $this->stack[count($this->stack) - 1]->format : self::BASE_FORMAT;
    }

    /** Where paragraphs and lists go: the innermost open list item, otherwise the text itself. */
    private function container(): stdClass
    {
        for ($k = count($this->stack) - 1; $k >= 0; $k--) {
            if ($this->stack[$k]->type === 'li') {
                return $this->stack[$k]->item;
            }
        }
        return $this->root;
    }

    private function addText(string $raw): void
    {
        $text = self::cleanText(self::decodeEntities($raw));
        if ($text === '') {
            return;
        }
        $count = $this->para !== null ? count($this->para->runs) : 0;
        $last  = $count ? $this->para->runs[$count - 1] : null;
        // No space at the start of a line, and never two in a row.
        if ($text[0] === ' ' && ($last === null || isset($last['br']) || str_ends_with($last['text'], ' '))) {
            $text = (string)substr($text, 1);
        }
        if ($text === '') {
            return;
        }
        if ($this->para === null) {
            $this->para = (object)['runs' => [], 'container' => $this->container()];
        }
        $f = $this->format();
        $continues = $last !== null && !isset($last['br']) && $last['format']['key'] === $f['key'];
        unset($last); // the copy would make PHP duplicate the text on every append
        if ($continues) {
            $this->para->runs[$count - 1]['text'] .= $text;
        } else {
            $this->para->runs[] = ['text' => $text, 'format' => $f];
        }
    }

    /** No space before a line break or at the end of a paragraph. */
    private function trimEnd(): void
    {
        $count = $this->para !== null ? count($this->para->runs) : 0;
        if (!$count) {
            return;
        }
        $last = $this->para->runs[$count - 1];
        if (!isset($last['br']) && str_ends_with($last['text'], ' ')) {
            $text = (string)substr($last['text'], 0, -1);
            if ($text === '') {
                array_pop($this->para->runs);
            } else {
                $this->para->runs[$count - 1]['text'] = $text;
            }
        }
    }

    private function addBreak(): void
    {
        if ($this->para === null) {
            $this->para = (object)['runs' => [], 'container' => $this->container()];
        }
        $this->trimEnd();
        $this->para->runs[] = ['br' => true];
    }

    /** The current paragraph ends here. */
    private function flush(): void
    {
        if ($this->para === null) {
            return;
        }
        $this->trimEnd();
        // A single line break after the last text shows nothing (browsers leave one there as a placeholder).
        $count = count($this->para->runs);
        if ($count > 1 && isset($this->para->runs[$count - 1]['br']) && !isset($this->para->runs[$count - 2]['br'])) {
            array_pop($this->para->runs);
        }
        if ($this->para->runs) {
            $this->para->container->blocks[] = (object)['type' => 'p', 'runs' => $this->para->runs];
        }
        $this->para = null;
    }

    private static function frame(string $name, string $type, array $format, ?stdClass $list = null, ?stdClass $item = null): stdClass
    {
        return (object)['name' => $name, 'type' => $type, 'format' => $format, 'list' => $list, 'item' => $item];
    }

    /** Read a style attribute into the format $f. Returns true when the element is hidden (display: none). */
    private static function applyStyle(array &$f, string $style, array $parent): bool
    {
        $hidden = false;
        foreach (explode(';', strtolower($style)) as $part) {
            $at = strpos($part, ':');
            if ($at === false) {
                continue;
            }
            $prop = trim(substr($part, 0, $at), self::WS);
            $val  = trim((string)substr($part, $at + 1), self::WS);
            $val  = trim(preg_replace('/![ \t\n\r\f]*important$/D', '', $val) ?? $val, self::WS);
            if ($prop === 'font-weight') {
                if ($val === 'bold' || $val === 'bolder') {
                    $f['b'] = true;
                } elseif ($val === 'normal' || $val === 'lighter') {
                    $f['b'] = false;
                } elseif (preg_match('/^\d{1,4}(?:\.\d{1,4})?$/D', $val)) {
                    $f['b'] = (float)$val >= 600;
                }
            } elseif ($prop === 'font-style') {
                if ($val === 'italic' || str_starts_with($val, 'oblique')) {
                    $f['i'] = true;
                } elseif ($val === 'normal') {
                    $f['i'] = false;
                }
            } elseif ($prop === 'text-decoration' || $prop === 'text-decoration-line') {
                // A decoration without "underline" only takes back the element's own line, never the one around it.
                $f['u'] = preg_match('/(?:^|[ \t\n\r\f])underline(?:$|[ \t\n\r\f])/D', $val) ? true : $parent['u'];
            } elseif ($prop === 'color') {
                $c = self::parseColor($val);
                if ($c !== null) {
                    $f['c'] = self::isDefaultColor($c) ? null : $c;
                }
            } elseif ($prop === 'font-size') {
                $s = self::parseSize($val);
                if ($s !== null) {
                    $f['s'] = $s === 'normal' ? null : $s;
                }
            } elseif ($prop === 'display') {
                if ($val === 'none') {
                    $hidden = true;
                }
            }
        }
        return $hidden;
    }

    /** Start a list item: in the list that is open, or in a list of its own when there is none. */
    private function openItem(string $name, array $f): void
    {
        $this->flush();
        // An item that is still open in the same list ends here.
        $at = count($this->stack) - 1;
        while ($at >= 0 && $this->stack[$at]->type !== 'list') {
            if ($this->stack[$at]->type === 'li') {
                array_splice($this->stack, $at);
            }
            $at--;
        }
        if ($at >= 0) {
            $list = $this->stack[$at]->list;
        } else {
            $container = $this->container();
            $last = $container->blocks ? $container->blocks[count($container->blocks) - 1] : null;
            if ($last !== null && $last->type !== 'p' && $last->implicit) {
                $list = $last;
            } else {
                $list = (object)['type' => 'ul', 'items' => [], 'implicit' => true];
                $container->blocks[] = $list;
            }
        }
        $item = (object)['blocks' => []];
        $list->items[] = $item;
        $this->stack[] = self::frame($name, 'li', $f, $list, $item);
    }

    private function open(string $name, array $attrs, bool $selfClosing): void
    {
        if ($this->skip !== null) {
            if ($name === $this->skip['name'] && !$selfClosing) {
                $this->skip['depth']++;
            }
            return;
        }
        if ($name === 'br') {
            $this->addBreak();
            return;
        }
        if ($name === 'hr') {
            $this->flush();
            return;
        }
        if (isset(self::VOID[$name])) {
            return;
        }
        if (isset(self::DROP[$name])) {
            if (!$selfClosing) {
                $this->skip = ['name' => $name, 'depth' => 1]; // <svg/> has no content and no end tag
            }
            return;
        }

        $parent = $this->format();
        $f = $parent;
        if ($name === 'b' || $name === 'strong' || $name === 'th') {
            $f['b'] = true;
        } elseif ($name === 'i' || $name === 'em') {
            $f['i'] = true;
        } elseif ($name === 'u' || $name === 'ins') {
            $f['u'] = true;
        } elseif ($name === 'h1' || $name === 'h2') {
            $f['b'] = true;
            $f['s'] = 'xl';
        } elseif ($name === 'h3') {
            $f['b'] = true;
            $f['s'] = 'large';
        } elseif ($name === 'h4' || $name === 'h5' || $name === 'h6') {
            $f['b'] = true;
            $f['s'] = null;
        } elseif ($name === 'small') {
            $f['s'] = 'small';
        } elseif ($name === 'big') {
            $f['s'] = 'large';
        } elseif ($name === 'font') {
            if (isset($attrs['size'])) {
                $s = self::parseLegacySize($attrs['size']);
                if ($s !== null) {
                    $f['s'] = $s === 'normal' ? null : $s;
                }
            }
            if (isset($attrs['color'])) {
                $v = strtolower(trim($attrs['color'], self::WS));
                $c = self::parseColor($v) ?? (preg_match('/^[0-9a-f]{6}$/D', $v) ? '#' . $v : null);
                if ($c !== null) {
                    $f['c'] = self::isDefaultColor($c) ? null : $c;
                }
            }
        }
        if (isset($attrs['style']) && self::applyStyle($f, $attrs['style'], $parent)) {
            $this->skip = ['name' => $name, 'depth' => 1];
            return;
        }
        $f['key'] = self::formatKey($f);
        if (count($this->stack) >= self::MAX_DEPTH) {
            return;
        }

        $listType = self::LISTS[$name] ?? null;
        if ($listType !== null) {
            $this->flush();
            $depth = 0;
            foreach ($this->stack as $frame) {
                if ($frame->type === 'list') {
                    $depth++;
                }
            }
            if ($depth >= self::MAX_LIST_DEPTH) {
                $this->stack[] = self::frame($name, 'inline', $f);
                return;
            }
            $list = (object)['type' => $listType, 'items' => [], 'implicit' => false];
            $top = $this->stack ? $this->stack[count($this->stack) - 1] : null;
            if ($top !== null && $top->type === 'list') {
                // A list directly inside a list (browsers build this when indenting) belongs to the item before it.
                if (!$top->list->items) {
                    $top->list->items[] = (object)['blocks' => []];
                }
                $top->list->items[count($top->list->items) - 1]->blocks[] = $list;
            } else {
                $this->container()->blocks[] = $list;
            }
            $this->stack[] = self::frame($name, 'list', $f, $list);
        } elseif ($name === 'li') {
            $this->openItem($name, $f);
        } elseif (isset(self::BLOCK[$name])) {
            $this->flush();
            $this->stack[] = self::frame($name, 'block', $f);
        } else {
            if ($name === 'td' || $name === 'th') {
                $this->addText(' '); // cells of a row stay apart
            }
            $this->stack[] = self::frame($name, 'inline', $f);
        }
    }

    private function close(string $name): void
    {
        if ($this->skip !== null) {
            if ($name === $this->skip['name'] && --$this->skip['depth'] === 0) {
                $this->skip = null;
            }
            return;
        }
        $at = count($this->stack) - 1;
        while ($at >= 0 && $this->stack[$at]->name !== $name) {
            $at--;
        }
        if ($at < 0) {
            return;
        }
        for ($k = $at, $top = count($this->stack); $k < $top; $k++) {
            if ($this->stack[$k]->type !== 'inline') {
                $this->flush();
                break;
            }
        }
        array_splice($this->stack, $at);
    }

    // ---- Reading values ---------------------------------------------------------------

    /** &amp; &lt; &gt; &quot; &apos; &nbsp; and numeric references → characters. Anything else stays as written. */
    private static function decodeEntities(string $s): string
    {
        if (strpos($s, '&') === false) {
            return $s;
        }
        return preg_replace_callback(
            '/&(#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|amp|lt|gt|quot|apos|nbsp);/',
            static function (array $m): string {
                $ref = $m[1];
                if ($ref[0] !== '#') {
                    return self::NAMED_ENTITIES[$ref];
                }
                $code = ($ref[1] === 'x' || $ref[1] === 'X') ? (int)hexdec(substr($ref, 2)) : (int)substr($ref, 1);
                if ($code === 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                    return "\u{FFFD}";
                }
                return self::utf8($code);
            },
            $s
        ) ?? $s;
    }

    /** One Unicode code point as UTF-8. */
    private static function utf8(int $code): string
    {
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }
        if ($code < 0x10000) {
            return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        }
        return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }

    /** Text as it is kept: no control or zero-width characters, every run of white space one space. */
    private static function cleanText(string $s): string
    {
        $s = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{200B}\x{2060}\x{FEFF}]/u', '', $s) ?? '';
        return preg_replace('/[ \t\n\r\f\x{2028}\x{2029}]+/u', ' ', $s) ?? '';
    }

    private static function escapeText(string $s): string
    {
        return str_replace(['&', '<', '>', "\u{00A0}"], ['&amp;', '&lt;', '&gt;', '&nbsp;'], $s);
    }

    /** A CSS or <font> color (lower case, trimmed) → "#rrggbb", or null when it is none we read. */
    public static function parseColor(string $v): ?string
    {
        if (preg_match('/^#([0-9a-f]{3,8})$/D', $v, $m)) {
            $h = $m[1];
            $len = strlen($h);
            if ($len === 3 || $len === 4) {
                if ($len === 4 && $h[3] === '0') {
                    return null; // fully transparent
                }
                return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            }
            if ($len === 6) {
                return '#' . $h;
            }
            if ($len === 8) {
                return substr($h, 6) === '00' ? null : '#' . substr($h, 0, 6);
            }
            return null;
        }
        if (preg_match('/^rgba?\(([^()]*)\)$/D', $v, $m)) {
            $parts = preg_split('/[ \t\n\r\f,\/]+/', $m[1], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 3 || count($parts) > 4) {
                return null;
            }
            $hex = '#';
            for ($k = 0; $k < 3; $k++) {
                if (!preg_match('/^(\d{1,3}(?:\.\d{1,4})?)(%?)$/D', $parts[$k], $pm)) {
                    return null;
                }
                $num = (float)$pm[1];
                if ($pm[2] !== '') {
                    $num = $num * 255 / 100;
                }
                $hex .= str_pad(dechex(max(0, min(255, (int)round($num)))), 2, '0', STR_PAD_LEFT);
            }
            if (count($parts) === 4 && preg_match('/^0(?:\.0+)?%?$/D', $parts[3])) {
                return null; // fully transparent
            }
            return $hex;
        }
        return self::COLOR_NAMES[$v] ?? null;
    }

    /** A CSS font size (lower case, trimmed) → 'small' | 'normal' | 'large' | 'xl', or null when it cannot be read. */
    public static function parseSize(string $v): ?string
    {
        if (isset(self::SIZE_KEYWORDS[$v])) {
            return self::SIZE_KEYWORDS[$v];
        }
        if (!preg_match('/^(\d{1,4}(?:\.\d{1,4})?)(px|pt|pc|em|rem|%)$/D', $v, $m)) {
            return null;
        }
        $px = (float)$m[1];
        if ($m[2] === 'pt') {
            $px = $px * 4 / 3;
        } elseif ($m[2] === 'pc') {
            $px = $px * 16;
        } elseif ($m[2] === 'em' || $m[2] === 'rem') {
            $px = $px * 15;
        } elseif ($m[2] === '%') {
            $px = $px * 15 / 100;
        }
        if ($px <= 0) {
            return null;
        }
        // The nearest of the four sizes (13, 15, 18 and 24 px).
        if ($px <= 13.2) {
            return 'small';
        }
        if ($px <= 16.5) {
            return 'normal';
        }
        return $px <= 21 ? 'large' : 'xl';
    }

    /** <font size="1…7"> (or "+1", "-2") → 'small' | 'normal' | 'large' | 'xl', or null. */
    public static function parseLegacySize(string $v): ?string
    {
        if (!preg_match('/^([+-]?)(\d{1,2})$/D', trim($v, self::WS), $m)) {
            return null;
        }
        $size = (int)$m[2];
        if ($m[1] === '+') {
            $size = 3 + $size;
        } elseif ($m[1] === '-') {
            $size = 3 - $size;
        }
        $size = max(1, min(7, $size));
        if ($size <= 2) {
            return 'small';
        }
        if ($size === 3) {
            return 'normal';
        }
        return $size === 4 ? 'large' : 'xl';
    }

    /** Black and the default ink count as "no color": the text then simply has the color of its surroundings. */
    private static function isDefaultColor(string $hex): bool
    {
        return $hex === RICHTEXT_DEFAULT_COLOR || $hex === '#000000';
    }

    /** The attributes the cleaner reads (style, size, color) from the inside of a start tag. */
    private static function parseAttrs(string $str): array
    {
        $out = [];
        if (strlen($str) > 4000) {
            return $out;
        }
        $pattern = '/([^ \t\n\r\f"\'<>\/=]+)(?:[ \t\n\r\f]*=[ \t\n\r\f]*(?:"([^"]*)"|\'([^\']*)\'|([^ \t\n\r\f"\'=<>`]+)))?/';
        if (!preg_match_all($pattern, $str, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            return $out;
        }
        foreach ($matches as $m) {
            $name = strtolower($m[1]);
            if (($name === 'style' || $name === 'size' || $name === 'color') && !isset($out[$name])) {
                $out[$name] = self::decodeEntities($m[2] ?? $m[3] ?? $m[4] ?? '');
            }
        }
        return $out;
    }

    private static function formatKey(array $f): string
    {
        return ($f['b'] ? 'b' : '') . ($f['i'] ? 'i' : '') . ($f['u'] ? 'u' : '') . '|' . ($f['c'] ?? '') . '|' . ($f['s'] ?? '');
    }

    // ---- Writing ------------------------------------------------------------------------

    private static function isBlank(stdClass $block): bool
    {
        if ($block->type !== 'p') {
            return false;
        }
        foreach ($block->runs as $run) {
            if (!isset($run['br'])) {
                return false;
            }
        }
        return true;
    }

    /** The elements around a run, outermost first: [[start tag, end tag name], …]. */
    private static function wrappers(array $f): array
    {
        $w = [];
        $css = [];
        if ($f['c'] !== null) {
            $css[] = 'color:' . $f['c'];
        }
        if ($f['s'] !== null) {
            $css[] = 'font-size:' . RICHTEXT_SIZES[$f['s']] . 'px';
        }
        if ($css) {
            $w[] = ['span style="' . implode(';', $css) . '"', 'span'];
        }
        if ($f['b']) {
            $w[] = ['strong', 'strong'];
        }
        if ($f['i']) {
            $w[] = ['em', 'em'];
        }
        if ($f['u']) {
            $w[] = ['u', 'u'];
        }
        return $w;
    }

    private static function renderRuns(array $runs): string
    {
        $out = '';
        $opened = [];
        $closeTo = static function (int $count) use (&$out, &$opened): void {
            while (count($opened) > $count) {
                $out .= '</' . array_pop($opened)[1] . '>';
            }
        };
        $shared = static function (array $want) use (&$opened): int {
            $k = 0;
            while ($k < count($opened) && $k < count($want) && $opened[$k][0] === $want[$k][0]) {
                $k++;
            }
            return $k;
        };
        $total = count($runs);
        foreach ($runs as $idx => $run) {
            if (isset($run['br'])) {
                // A line break stays inside the formatting that the next text continues.
                $nextText = null;
                for ($k = $idx + 1; $k < $total; $k++) {
                    if (!isset($runs[$k]['br'])) {
                        $nextText = $runs[$k];
                        break;
                    }
                }
                $closeTo($nextText !== null ? $shared(self::wrappers($nextText['format'])) : 0);
                $out .= '<br>';
                continue;
            }
            $want = self::wrappers($run['format']);
            $keep = $shared($want);
            $closeTo($keep);
            for ($k = $keep; $k < count($want); $k++) {
                $out .= '<' . $want[$k][0] . '>';
                $opened[] = $want[$k];
            }
            $out .= self::escapeText($run['text']);
        }
        $closeTo(0);
        return $out;
    }

    private static function renderList(stdClass $list): string
    {
        if (!$list->items) {
            return '';
        }
        $out = '<' . $list->type . '>';
        foreach ($list->items as $item) {
            $out .= '<li>' . self::renderItem($item) . '</li>';
        }
        return $out . '</' . $list->type . '>';
    }

    /** An item holds its text directly; several paragraphs become lines of it. */
    private static function renderItem(stdClass $item): string
    {
        $out = '';
        $before = null;
        foreach ($item->blocks as $block) {
            if ($block->type === 'p') {
                if ($before !== null && !isset($before->runs[count($before->runs) - 1]['br'])) {
                    $out .= '<br>';
                }
                $out .= self::renderRuns($block->runs);
                $before = $block;
            } else {
                $out .= self::renderList($block);
                $before = null;
            }
        }
        return $out === '' ? '<br>' : $out;
    }
}
