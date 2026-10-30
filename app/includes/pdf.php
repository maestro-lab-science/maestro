<?php
/**
 * pdf.php — dependency-free PDF transcript renderer.
 *
 * MiniPdf is a small PDF 1.4 writer (Flate-compressed streams). Text is set
 * in Noto Sans with Noto Emoji for what Noto Sans lacks, both embedded as
 * subsets (includes/pdf_font.php), so participants' messages keep their
 * accents, Greek and Cyrillic letters and emoji; a character neither font
 * has (Chinese, Arabic) is shown as "?". render_transcript_pdf() lays a conversation out
 * the same way the app does: sender label + timestamp above a rounded, tinted
 * bubble — participant (yellow) on the right, the teammates (in the project's
 * team colors) on the left.
 *
 * @author Marc Becker
 */

require_once __DIR__ . '/team.php';
require_once __DIR__ . '/pdf_font.php';

class MiniPdf
{
    public const W = 595.28;  // A4 portrait, points
    public const H = 841.89;

    private array $pages = [];      // each: array of content-stream chunks
    private int $current = -1;
    private $footer = null;         // fn(MiniPdf $pdf, int $page, int $total): string
    private array $used = [];       // font key => [glyph id => code point]: what the subsets must hold

    /** The fonts: file in includes/fonts, name in the PDF, resource name on the pages. */
    private const FONTS = [
        'regular' => ['NotoSans-Regular.ttf', 'NotoSans-Regular', 'F1'],
        'bold'    => ['NotoSans-Bold.ttf', 'NotoSans-Bold', 'F2'],
        'emoji'   => ['NotoEmoji.ttf', 'NotoEmoji-Regular', 'F3'],
    ];

    public function newPage(): void
    {
        $this->pages[] = [];
        $this->current = count($this->pages) - 1;
    }

    public function setFooter(callable $fn): void
    {
        $this->footer = $fn;
    }

    private function raw(string $ops): void
    {
        if ($this->current < 0) {
            $this->newPage();
        }
        $this->pages[$this->current][] = $ops;
    }

    // ---- Text ------------------------------------------------------------------

    private static function font(string $key): PdfFont
    {
        [$file, $name] = self::FONTS[$key];
        return PdfFont::load(__DIR__ . '/fonts/' . $file, $name);
    }

    /**
     * The text as runs of one font each: [[font key, [glyph ids], [code points]], …].
     * A character takes Noto Sans (regular or bold), else Noto Emoji, else "?".
     * Characters that only steer how others look (variation selectors, joiners,
     * skin tones of the black-and-white emoji) are left out; control
     * characters become spaces.
     */
    private static function runs(string $text, bool $bold): array
    {
        $main = $bold ? 'bold' : 'regular';
        $runs = [];
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false || ($cp >= 0x200B && $cp <= 0x200F) || ($cp >= 0xFE00 && $cp <= 0xFE0F)
                || ($cp >= 0x2060 && $cp <= 0x2064) || ($cp >= 0x1F3FB && $cp <= 0x1F3FF) || ($cp >= 0xE0000 && $cp <= 0xE007F)) {
                continue;
            }
            if ($cp < 0x20 || ($cp >= 0x7F && $cp < 0xA0)) {
                $cp = 0x20;
            }
            $key = $main;
            $glyph = self::font($main)->glyph($cp);
            if ($glyph === 0) {
                $glyph = self::font('emoji')->glyph($cp);
                $key = 'emoji';
                if ($glyph === 0) {
                    [$key, $cp] = [$main, 0x3F]; // "?"
                    $glyph = self::font($main)->glyph($cp);
                }
            }
            $last = count($runs) - 1;
            if ($last < 0 || $runs[$last][0] !== $key) {
                $runs[] = [$key, [], []];
                $last++;
            }
            $runs[$last][1][] = $glyph;
            $runs[$last][2][] = $cp;
        }
        return $runs;
    }

    /** String width in points. */
    public static function width(string $utf8, float $size, bool $bold = false): float
    {
        $units = 0.0;
        foreach (self::runs($utf8, $bold) as [$key, $glyphs]) {
            $font = self::font($key);
            foreach ($glyphs as $glyph) {
                $units += $font->advance($glyph);
            }
        }
        return $units / 1000 * $size;
    }

    /** Word-wrap into lines that fit $maxW at $size. Keeps explicit newlines. */
    public static function wrap(string $text, float $maxW, float $size, bool $bold = false): array
    {
        $out = [];
        foreach (preg_split('/\n/u', str_replace("\r", '', $text)) as $para) {
            if (trim($para) === '') {
                $out[] = '';
                continue;
            }
            $line = '';
            foreach (preg_split('/ +/u', $para) as $word) {
                // Hard-split single words wider than the line: full lines of
                // characters, and the rest goes on as a word. The character
                // widths are added up once; re-measuring the rest after every
                // character made a long unbroken word take minutes.
                if (self::width($word, $size, $bold) > $maxW) {
                    if ($line !== '') {
                        $out[] = $line;
                        $line = '';
                    }
                    $piece = '';
                    $pieceW = 0.0;
                    foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                        $chW = self::width($ch, $size, $bold);
                        if ($piece !== '' && $pieceW + $chW > $maxW) {
                            $out[] = $piece;
                            $piece = '';
                            $pieceW = 0.0;
                        }
                        $piece .= $ch;
                        $pieceW += $chW;
                    }
                    $word = $piece;
                }
                if ($word === '') {
                    continue;
                }
                $try = $line === '' ? $word : $line . ' ' . $word;
                if (self::width($try, $size, $bold) <= $maxW) {
                    $line = $try;
                } else {
                    if ($line !== '') {
                        $out[] = $line;
                    }
                    $line = $word;
                }
            }
            $out[] = $line;
        }
        return $out ?: [''];
    }

    /** Content-stream ops for one text run anchored at its TOP-left corner. */
    public function textOps(float $x, float $yTop, string $text, float $size, bool $bold, array $rgb): string
    {
        $base = self::H - $yTop - $size * 0.8; // baseline ≈ 80% below the top edge
        $ops = sprintf("%.3F %.3F %.3F rg BT %.2F %.2F Td", $rgb[0], $rgb[1], $rgb[2], $x, $base);
        // Glyph ids as two-byte codes (Identity-H); each run moves the pen by its own widths.
        foreach (self::runs($text, $bold) as [$key, $glyphs, $codes]) {
            foreach ($glyphs as $i => $glyph) {
                $this->used[$key][$glyph] = $codes[$i];
            }
            $hex = implode('', array_map(fn($g) => sprintf('%04X', $g), $glyphs));
            $ops .= sprintf(' /%s %.2F Tf <%s> Tj', self::FONTS[$key][2], $size, $hex);
        }
        return $ops . " ET\n";
    }

    public function text(float $x, float $yTop, string $text, float $size, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $this->raw($this->textOps($x, $yTop, $text, $size, $bold, $rgb));
    }

    // ---- Shapes -------------------------------------------------------------------

    public function roundRect(float $x, float $yTop, float $w, float $h, float $r, array $fill): void
    {
        $y = self::H - $yTop - $h; // PDF-space bottom edge
        $r = min($r, $w / 2, $h / 2);
        $k = 0.5523 * $r;
        $ops  = sprintf("%.3F %.3F %.3F rg\n", $fill[0], $fill[1], $fill[2]);
        $ops .= sprintf("%.2F %.2F m\n", $x + $r, $y);
        $ops .= sprintf("%.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c\n", $x + $w - $r, $y, $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r);
        $ops .= sprintf("%.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c\n", $x + $w, $y + $h - $r, $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h);
        $ops .= sprintf("%.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c\n", $x + $r, $y + $h, $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r);
        $ops .= sprintf("%.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c\n", $x, $y + $r, $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y);
        $ops .= "f\n";
        $this->raw($ops);
    }

    public function hline(float $x1, float $x2, float $yTop, float $widthPt, array $rgb): void
    {
        $y = self::H - $yTop;
        $this->raw(sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $widthPt, $x1, $y, $x2, $y
        ));
    }

    // ---- Output ----------------------------------------------------------------------

    public function output(): string
    {
        if ($this->current < 0) {
            $this->newPage();
        }
        $total = count($this->pages);
        if ($this->footer) {
            for ($i = 0; $i < $total; $i++) {
                $this->pages[$i][] = ($this->footer)($this, $i + 1, $total);
            }
        }

        // Objects: 1 catalog, 2 page tree, then a page and its content per page,
        // then five objects per font used (see fontObjects).
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';                     // 1
        $kids = [];
        $first_page_obj = 3;
        for ($i = 0; $i < $total; $i++) {
            $kids[] = ($first_page_obj + $i * 2) . ' 0 R';
        }
        $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>'; // 2
        $fontObj = $first_page_obj + 2 * $total;
        $fontRefs = '';
        foreach (array_keys(self::FONTS) as $key) {
            if (!empty($this->used[$key])) {
                $fontRefs .= ' /' . self::FONTS[$key][2] . ' ' . $fontObj . ' 0 R';
                $fontObj += 5;
            }
        }

        foreach ($this->pages as $i => $chunks) {
            $contentObj = $first_page_obj + $i * 2 + 1;
            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font <<%s >> >> /Contents %d 0 R >>',
                self::W,
                self::H,
                $fontRefs,
                $contentObj
            );
            $stream = implode('', $chunks);
            $flate = function_exists('gzcompress') ? gzcompress($stream, 6) : false;
            if ($flate !== false) {
                $objects[] = '<< /Length ' . strlen($flate) . " /Filter /FlateDecode >>\nstream\n" . $flate . "\nendstream";
            } else {
                $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            }
        }

        foreach (array_keys(self::FONTS) as $key) {
            if (!empty($this->used[$key])) {
                array_push($objects, ...$this->fontObjects($key, count($objects) + 1));
            }
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $idx => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($idx + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $n = count($objects) + 1;
        $pdf .= "xref\n0 " . $n . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size " . $n . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    /**
     * The five objects of one embedded font, numbered from $first: the Type0
     * font the pages use, its CIDFontType2 with the widths, the descriptor,
     * the subset itself, and the map back to Unicode (copying and searching).
     */
    private function fontObjects(string $key, int $first): array
    {
        $font  = self::font($key);
        $used  = $this->used[$key];
        ksort($used);
        $scale = 1000 / $font->unitsPerEm;
        $tag   = '';
        foreach (str_split(substr(md5($key . implode(',', array_keys($used))), 0, 6)) as $c) {
            $tag .= chr(65 + hexdec($c) % 26); // the subset prefix ("ABCDEF+")
        }
        $name = $tag . '+' . $font->name;

        $widths = '';
        foreach ($used as $glyph => $_) {
            $widths .= $glyph . ' [' . (int)round($font->advance($glyph)) . '] ';
        }
        $file = $font->subset(array_keys($used));
        $fileZ = gzcompress($file, 6);

        $map = '';
        foreach (array_chunk($used, 100, true) as $chunk) {
            $map .= count($chunk) . " beginbfchar\n";
            foreach ($chunk as $glyph => $cp) {
                $utf16 = strtoupper(bin2hex(mb_convert_encoding(mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8')));
                $map .= sprintf("<%04X> <%s>\n", $glyph, $utf16);
            }
            $map .= "endbfchar\n";
        }
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . $map
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
        $cmapZ = gzcompress($cmap, 6);

        return [
            sprintf('<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
                $name, $first + 1, $first + 4),
            sprintf('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> '
                . '/FontDescriptor %d 0 R /CIDToGIDMap /Identity /DW %d /W [%s] >>',
                $name, $first + 2, (int)round($font->advance(0)), trim($widths)),
            sprintf('<< /Type /FontDescriptor /FontName /%s /Flags 4 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
                $name, $font->bbox[0] * $scale, $font->bbox[1] * $scale, $font->bbox[2] * $scale, $font->bbox[3] * $scale,
                $font->ascent * $scale, $font->descent * $scale, $font->capHeight * $scale, $first + 3),
            '<< /Length ' . strlen($fileZ) . ' /Length1 ' . strlen($file) . " /Filter /FlateDecode >>\nstream\n" . $fileZ . "\nendstream",
            '<< /Length ' . strlen($cmapZ) . " /Filter /FlateDecode >>\nstream\n" . $cmapZ . "\nendstream",
        ];
    }
}

// =====================================================================================
// Transcript rendering
// =====================================================================================

/** Hex "#rrggbb" → [r,g,b] in 0..1. */
function pdf_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    ];
}

/**
 * Render one or more conversations into a single PDF.
 *
 * @param array $project       project row (name/id, team, settings for the cover)
 * @param array $conversations list of ['conversation_id' => string, 'messages' => rows, 'notes' => [client_id => text]]
 *                             each row: sender, shown_at, message_text, client_id; notes are
 *                             printed under the participant message they belong to
 *                             (e.g. "no team reaction — the director failed")
 */
function render_transcript_pdf(array $project, array $conversations): string
{
    $everyone = team_all($project);
    $members  = team_bots($everyone);
    $human    = team_human($everyone);
    $humanV   = team_color_variants($human['color']);
    $roles = [
        'human' => ['label' => $human['name'] . ' (participant)', 'bubble' => pdf_rgb($humanV['soft']), 'deep' => pdf_rgb($humanV['deep'])],
    ];
    foreach ($members as $m) {
        $v = team_color_variants($m['color']);
        $roles[$m['id']] = ['label' => $m['name'], 'bubble' => pdf_rgb($v['soft']), 'deep' => pdf_rgb($v['deep'])];
    }
    $unknownRole = ['label' => '?', 'bubble' => pdf_rgb('#e2e8f0'), 'deep' => pdf_rgb('#334155')];
    $ink   = pdf_rgb('#1f2937');
    $muted = pdf_rgb('#64748b');
    $faint = pdf_rgb('#e2e8f0');
    $warn  = pdf_rgb('#b45309');

    $mL = 46.0;
    $mR = 46.0;
    $top = 54.0;
    $bottom = 60.0;
    $contentW = MiniPdf::W - $mL - $mR;

    $fontSize   = 10.0;
    $lineH      = 13.6;
    $padX       = 9.0;
    $padY       = 6.5;
    $maxTextW   = 330.0;
    $labelSize  = 8.6;
    $timeSize   = 8.0;

    $projectLabel = trim((string)($project['name'] ?? '')) !== '' ? (string)$project['name'] : (string)$project['project_id'];

    $pdf = new MiniPdf();
    $pdf->setFooter(function (MiniPdf $p, int $page, int $total) use ($projectLabel, $muted, $mL, $mR) {
        $txt = $projectLabel . '  ·  page ' . $page . ' of ' . $total;
        $w = MiniPdf::width($txt, 7.8);
        $x = (MiniPdf::W - $w) / 2;
        return $p->textOps($x, MiniPdf::H - 34, $txt, 7.8, false, $muted);
    });

    // =================================================================================
    // Cover page — project + key info, then every conversation starts on a new page.
    // =================================================================================
    $pdf->newPage();

    $convCount = count($conversations);
    $msgTotal = 0;
    $humanTotal = 0;
    $firstTs = '';
    $lastTs = '';
    foreach ($conversations as $c) {
        foreach ($c['messages'] as $m) {
            $msgTotal++;
            if (($m['sender'] ?? '') === 'human') {
                $humanTotal++;
            }
            $ts = (string)$m['shown_at'];
            if ($ts !== '') {
                if ($firstTs === '' || strcmp($ts, $firstTs) < 0) {
                    $firstTs = $ts;
                }
                if ($lastTs === '' || strcmp($ts, $lastTs) > 0) {
                    $lastTs = $ts;
                }
            }
        }
    }

    $y = 128.0;
    $pdf->text($mL, $y, 'MAESTRO  ·  CONVERSATION TRANSCRIPTS', 8.6, true, $muted);
    $y += 26;
    foreach (MiniPdf::wrap($projectLabel, $contentW, 23.0, true) as $ln) {
        $pdf->text($mL, $y, $ln, 23.0, true, $ink);
        $y += 30;
    }
    $y += 2;
    $pdf->text($mL, $y, 'Study ' . (string)$project['project_id'], 10.5, false, $muted);
    $y += 15;
    $pdf->hline($mL, MiniPdf::W - $mR, $y, 0.7, $faint);
    $y += 20;

    // ---- Key-info card -----------------------------------------------------------
    $providerNames = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'custom' => 'Custom (OpenAI-compatible)'];
    $provider = (string)($project['api_provider'] ?? '');
    $modelBits = [(string)($project['model'] ?? '') !== '' ? (string)$project['model'] : '(server default)'];
    if ($provider !== '') {
        $modelBits[] = 'via ' . ($providerNames[$provider] ?? $provider);
    }
    if (trim((string)($project['director_effort'] ?? '')) !== '') {
        $modelBits[] = 'conductor effort ' . (string)$project['director_effort'];
    }

    $timerTxt = !empty($project['timer_enabled'])
        ? sprintf('timer %d:%02d min', intdiv((int)($project['timer_duration_seconds'] ?? 0), 60), (int)($project['timer_duration_seconds'] ?? 0) % 60)
        : 'timer off';
    $gateTxt = !empty($project['show_ready_modal'])
        ? sprintf('ready gate on (%d s matchmaking wait)', (int)($project['ready_wait_seconds'] ?? 0))
        : 'ready gate off';

    $rows = [];
    $rows['Exported'] = gmdate('Y-m-d H:i') . ' UTC'; // message times are stored in UTC too
    $rows['Conversations'] = $convCount === 1
        ? '1  ·  ' . (string)$conversations[0]['conversation_id']
        : (string)$convCount;
    $rows['Messages'] = $msgTotal . ' total  ·  ' . $humanTotal . ' from participant' . ($convCount === 1 ? '' : 's');
    if ($firstTs !== '') {
        $rows['Recorded'] = substr($firstTs, 0, 16) . '  –  ' . substr($lastTs, 0, 16) . ' UTC';
    }
    $rows['Team'] = implode(', ', array_map(fn($m) => $m['name'] . ' (' . $m['id'] . ')', $members)) . '  +  the participant (' . $human['name'] . ')';
    $rows['AI model'] = implode('  ·  ', $modelBits);
    if ($provider === 'custom' && trim((string)($project['api_endpoint'] ?? '')) !== '') {
        $rows['Endpoint'] = trim((string)$project['api_endpoint']);
    }
    $rows['Session'] = $timerTxt . '  ·  ' . $gateTxt;

    $cardPad = 18.0;
    $labelW = 112.0;
    $valX = $mL + $cardPad + $labelW;
    $valW = $mL + $contentW - $cardPad - $valX;
    $rowLineH = 14.5;
    $rowGap = 7.5;

    $wrapped = [];
    $cardH = 2 * $cardPad - $rowGap;
    foreach ($rows as $label => $value) {
        $vl = MiniPdf::wrap($value, $valW, 10.5);
        $wrapped[] = [$label, $vl];
        $cardH += count($vl) * $rowLineH + $rowGap;
    }
    $pdf->roundRect($mL, $y, $contentW, $cardH, 12.0, pdf_rgb('#f1f5f9'));
    $ry = $y + $cardPad;
    foreach ($wrapped as [$label, $vl]) {
        $pdf->text($mL + $cardPad, $ry + 1.2, strtoupper($label), 8.2, true, $muted);
        foreach ($vl as $ln) {
            $pdf->text($valX, $ry, $ln, 10.5, false, $ink);
            $ry += $rowLineH;
        }
        $ry += $rowGap;
    }
    $y += $cardH + 26;

    // ---- Color legend ----------------------------------------------------------------
    $pdf->text($mL, $y, 'Reading the transcript', 10.5, true, $ink);
    $y += 17;
    $x = $mL;
    $legend = [[$human['name'] . ' (participant)', $roles['human']['bubble']]];
    foreach ($members as $m) {
        $legend[] = [$m['name'], $roles[$m['id']]['bubble']];
    }
    foreach ($legend as [$label, $swatch]) {
        $w = 33 + MiniPdf::width($label, 9.5) + 24;
        if ($x > $mL && $x + $w > $mL + $contentW) { // wrap a long team onto the next line
            $x = $mL;
            $y += 19;
        }
        $pdf->roundRect($x, $y, 26.0, 13.0, 5.0, $swatch);
        $pdf->text($x + 33, $y + 1.0, $label, 9.5, false, $ink);
        $x += $w;
    }
    $y += 24;
    $pdf->text($mL, $y, 'Participant messages are right-aligned; the scripted teammates are left-aligned.', 9.3, false, $muted);
    $y += 13;
    $pdf->text($mL, $y, 'A note under a participant message means the team did not react to the message (the conductor failed).', 9.3, false, $muted);
    $y += 13;
    $pdf->text($mL, $y, 'Each conversation starts on a new page.', 9.3, false, $muted);

    // =================================================================================
    // Conversations
    // =================================================================================
    $newPage = function () use ($pdf, $top, &$y) {
        $pdf->newPage();
        $y = $top;
    };
    $ensure = function (float $need) use (&$y, $bottom, $newPage) {
        if ($y + $need > MiniPdf::H - $bottom) {
            $newPage();
        }
    };

    foreach ($conversations as $conv) {
        $cid = (string)$conv['conversation_id'];
        $messages = $conv['messages'];
        $notes = is_array($conv['notes'] ?? null) ? $conv['notes'] : [];

        $newPage(); // every conversation starts on a fresh page (after the cover)

        // ---- Conversation header ------------------------------------------------
        $ensure(60);
        $pdf->text($mL, $y, $cid, 12.5, true, $ink);
        $y += 17;

        $count = count($messages);
        $humanCount = 0;
        foreach ($messages as $m) {
            if ($m['sender'] === 'human') {
                $humanCount++;
            }
        }
        $metaBits = [$count . ' messages', $humanCount . ' from participant', ($count - $humanCount) . ' from team'];
        if ($count > 0) {
            $metaBits[] = 'started ' . substr((string)$messages[0]['shown_at'], 0, 16) . ' UTC';
            try {
                $a = new DateTime((string)$messages[0]['shown_at']);
                $b = new DateTime((string)$messages[$count - 1]['shown_at']);
                $secs = max(0, (int)round((float)$b->format('U.u') - (float)$a->format('U.u')));
                $metaBits[] = sprintf('%d:%02d min', intdiv($secs, 60), $secs % 60);
            } catch (Throwable $e) {
                // no duration when timestamps are malformed
            }
        }
        $pdf->text($mL, $y, implode('  ·  ', $metaBits), 9.3, false, $muted);
        $y += 14;
        $pdf->hline($mL, MiniPdf::W - $mR, $y, 0.7, $faint);
        $y += 14;

        if ($count === 0) {
            $pdf->text($mL, $y, 'No messages in this conversation.', $fontSize, false, $muted);
            $y += $lineH;
            continue;
        }

        // ---- Messages ------------------------------------------------------------
        $prevSender = null;
        $prevTs = 0.0;

        foreach ($messages as $m) {
            $sender = (string)$m['sender'];
            $role = $roles[$sender] ?? $unknownRole;
            $isHuman = $sender === 'human';
            $text = (string)$m['message_text'];
            if ($isHuman) {
                // The keystroke-count marker ("… [[//12]]") is analytics data —
                // keep it in the CSVs but not in the rendered transcript.
                $text = preg_replace('/\s*\[\[\/\/\d+\]\]\s*$/', '', $text) ?? $text;
            }
            $shownAt = (string)$m['shown_at'];
            $ts = (float)(strtotime(substr($shownAt, 0, 19)) ?: 0);

            $grouped = $prevSender === $sender && ($ts - $prevTs) < 90;
            $prevSender = $sender;
            $prevTs = $ts;

            $lines = MiniPdf::wrap($text, $maxTextW - 2 * $padX, $fontSize);

            // Widest line decides the bubble width.
            $textW = 0.0;
            foreach ($lines as $ln) {
                $textW = max($textW, MiniPdf::width($ln, $fontSize));
            }
            $bubbleW = min($maxTextW, max($textW + 2 * $padX, 30));
            $bubbleX = $isHuman ? (MiniPdf::W - $mR - $bubbleW) : $mL;

            $y += $grouped ? 3 : 11;

            // Label row (skipped for grouped follow-ups)
            if (!$grouped) {
                $labelH = 12.5;
                $ensure($labelH + 2 * $padY + $lineH); // label + at least one bubble line
                $label = $role['label'];
                $labelW = MiniPdf::width($label, $labelSize, true);
                $timeTxt = strlen($shownAt) >= 16 ? substr($shownAt, 11, 5) : '';
                if ($isHuman) {
                    $timeW = $timeTxt !== '' ? MiniPdf::width($timeTxt, $timeSize) : 0;
                    $lx = MiniPdf::W - $mR - $labelW;
                    if ($timeTxt !== '') {
                        $pdf->text($lx - $timeW - 6, $y + 1.2, $timeTxt, $timeSize, false, $muted);
                    }
                    $pdf->text($lx, $y, $label, $labelSize, true, $role['deep']);
                } else {
                    $pdf->text($bubbleX + 1, $y, $label, $labelSize, true, $role['deep']);
                    if ($timeTxt !== '') {
                        $pdf->text($bubbleX + 1 + $labelW + 6, $y + 1.2, $timeTxt, $timeSize, false, $muted);
                    }
                }
                $y += $labelH;
            }

            // Bubble(s) — split across pages if the chunk does not fit.
            $remaining = $lines;
            while ($remaining) {
                $avail = MiniPdf::H - $bottom - $y - 2 * $padY;
                $fit = max(1, (int)floor($avail / $lineH));
                if ($fit < min(2, count($remaining)) && $y > $top + 40) {
                    // Too little room for a meaningful chunk — move on.
                    $newPage();
                    continue;
                }
                $chunk = array_slice($remaining, 0, $fit);
                $remaining = array_slice($remaining, count($chunk));

                $h = count($chunk) * $lineH + 2 * $padY - ($lineH - $fontSize * 1.12);
                $pdf->roundRect($bubbleX, $y, $bubbleW, $h, 8.0, $role['bubble']);
                $ty = $y + $padY - 1;
                foreach ($chunk as $ln) {
                    $pdf->text($bubbleX + $padX, $ty, $ln, $fontSize, false, $ink);
                    $ty += $lineH;
                }
                $y += $h;
                if ($remaining) {
                    $y += 2;
                    $ensure(2 * $padY + $lineH);
                }
            }

            // Researcher note under a participant message (e.g. the director failed).
            $note = $isHuman ? (string)($notes[(string)($m['client_id'] ?? '')] ?? '') : '';
            if ($note !== '') {
                foreach (MiniPdf::wrap($note, $maxTextW, 8.2) as $ln) {
                    $ensure(12);
                    $y += 3;
                    $pdf->text(MiniPdf::W - $mR - MiniPdf::width($ln, 8.2), $y, $ln, 8.2, false, $warn);
                    $y += 9;
                }
            }
        }
    }

    return $pdf->output();
}
