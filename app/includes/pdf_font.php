<?php
/**
 * pdf_font.php — TrueType fonts for the PDF transcripts (includes/pdf.php).
 *
 * PdfFont reads what a PDF needs from a TrueType file: which glyph draws a
 * character (cmap), how wide each glyph is (hmtx), and the outlines (glyf),
 * from which subset() builds a font that holds only the glyphs one document
 * uses. The PDF embeds that subset as a CIDFontType2 with glyph ids as
 * character codes (Identity-H), so any character the font has can be shown.
 *
 * The fonts live in includes/fonts (Noto Sans and Noto Emoji, SIL Open Font
 * License, see includes/fonts/OFL.txt). Variable fonts are read at their
 * default instance. No shaping: scripts that need it (Arabic, the Indic
 * scripts) or run right to left (Arabic, Hebrew) are not supported.
 *
 * @author Marc Becker
 */

class PdfFont
{
    public string $name;           // PostScript-style name for the PDF (e.g. "NotoSans-Regular")
    public int $unitsPerEm;
    public int $ascent;
    public int $descent;
    public int $capHeight;
    public array $bbox;            // [xMin, yMin, xMax, yMax] in font units

    private string $data;
    private array $tables = [];    // tag => [offset, length]
    private array $cmap = [];      // code point => glyph id
    private array $advances = [];  // glyph id => advance width (font units)
    private int $numGlyphs;
    private int $longLoca;

    /** Fonts already read in this request, by file. */
    private static array $loaded = [];

    public static function load(string $file, string $name): self
    {
        return self::$loaded[$file] ??= new self($file, $name);
    }

    private function __construct(string $file, string $name)
    {
        $data = @file_get_contents($file);
        if ($data === false || strlen($data) < 12) {
            throw new RuntimeException('The PDF font ' . basename($file) . ' is missing (includes/fonts).');
        }
        $this->data = $data;
        $this->name = $name;
        $numTables = $this->u16(4);
        for ($i = 0; $i < $numTables; $i++) {
            $rec = 12 + 16 * $i;
            $this->tables[substr($data, $rec, 4)] = [$this->u32($rec + 8), $this->u32($rec + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap'] as $tag) {
            if (!isset($this->tables[$tag])) {
                throw new RuntimeException('The PDF font ' . basename($file) . ' has no ' . $tag . ' table.');
            }
        }
        $head = $this->tables['head'][0];
        $this->unitsPerEm = $this->u16($head + 18);
        $this->bbox       = [$this->i16($head + 36), $this->i16($head + 38), $this->i16($head + 40), $this->i16($head + 42)];
        $this->longLoca   = $this->i16($head + 50);
        $hhea = $this->tables['hhea'][0];
        $this->ascent  = $this->i16($hhea + 4);
        $this->descent = $this->i16($hhea + 6);
        $this->numGlyphs = $this->u16($this->tables['maxp'][0] + 4);
        $os2 = $this->tables['OS/2'] ?? null;
        $this->capHeight = $os2 && $this->u16($os2[0]) >= 2 ? $this->i16($os2[0] + 88) : $this->ascent;

        $metrics = $this->u16($hhea + 34);
        $hmtx = $this->tables['hmtx'][0];
        $last = 0;
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            if ($g < $metrics) {
                $last = $this->u16($hmtx + 4 * $g);
            }
            $this->advances[$g] = $last;
        }
        $this->readCmap();
    }

    // ---- Reading ---------------------------------------------------------------------

    private function u16(int $at): int
    {
        return unpack('n', $this->data, $at)[1];
    }

    private function i16(int $at): int
    {
        $v = $this->u16($at);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $at): int
    {
        return unpack('N', $this->data, $at)[1];
    }

    /** The Unicode subtable: format 12 (all planes) when there is one, otherwise format 4. */
    private function readCmap(): void
    {
        $cmap = $this->tables['cmap'][0];
        $best = null;
        $bestRank = 0;
        $n = $this->u16($cmap + 2);
        for ($i = 0; $i < $n; $i++) {
            $rec = $cmap + 4 + 8 * $i;
            $platform = $this->u16($rec);
            $encoding = $this->u16($rec + 2);
            $at = $cmap + $this->u32($rec + 4);
            $format = $this->u16($at);
            $rank = ($format === 12 ? 2 : ($format === 4 ? 1 : 0))
                * (($platform === 3 && in_array($encoding, [1, 10], true)) || $platform === 0 ? 1 : 0);
            if ($rank > $bestRank) {
                [$best, $bestRank] = [$at, $rank];
            }
        }
        if ($best === null) {
            throw new RuntimeException('The PDF font ' . $this->name . ' has no Unicode character map.');
        }
        if ($this->u16($best) === 12) {
            $groups = $this->u32($best + 12);
            for ($i = 0; $i < $groups; $i++) {
                $g = $best + 16 + 12 * $i;
                [$start, $end, $glyph] = [$this->u32($g), $this->u32($g + 4), $this->u32($g + 8)];
                for ($c = $start; $c <= $end; $c++) {
                    $this->cmap[$c] = $glyph + $c - $start;
                }
            }
            return;
        }
        $segs = $this->u16($best + 6) / 2;
        $ends = $best + 14;
        $starts = $ends + 2 * $segs + 2;
        $deltas = $starts + 2 * $segs;
        $ranges = $deltas + 2 * $segs;
        for ($s = 0; $s < $segs; $s++) {
            [$start, $end] = [$this->u16($starts + 2 * $s), $this->u16($ends + 2 * $s)];
            $delta = $this->u16($deltas + 2 * $s);
            $range = $this->u16($ranges + 2 * $s);
            for ($c = $start; $c <= $end && $c !== 0xFFFF; $c++) {
                if ($range === 0) {
                    $glyph = ($c + $delta) & 0xFFFF;
                } else {
                    $at = $ranges + 2 * $s + $range + 2 * ($c - $start);
                    $glyph = $this->u16($at);
                    $glyph = $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                }
                if ($glyph !== 0) {
                    $this->cmap[$c] = $glyph;
                }
            }
        }
    }

    // ---- What the PDF asks ------------------------------------------------------------

    /** The glyph of a code point, or 0 when the font has none. */
    public function glyph(int $codePoint): int
    {
        return $this->cmap[$codePoint] ?? 0;
    }

    /** The advance width of a glyph in 1/1000 em (the unit of PDF widths). */
    public function advance(int $glyph): float
    {
        return ($this->advances[$glyph] ?? 0) * 1000 / $this->unitsPerEm;
    }

    /** The outline data of a glyph (empty for glyphs without outlines, e.g. the space). */
    private function glyphData(int $glyph): string
    {
        $loca = $this->tables['loca'][0];
        if ($this->longLoca) {
            [$from, $to] = [$this->u32($loca + 4 * $glyph), $this->u32($loca + 4 * $glyph + 4)];
        } else {
            [$from, $to] = [2 * $this->u16($loca + 2 * $glyph), 2 * $this->u16($loca + 2 * $glyph + 2)];
        }
        return $to > $from ? substr($this->data, $this->tables['glyf'][0] + $from, $to - $from) : '';
    }

    /** The glyphs a composite glyph is built from (an outline glyph has none). */
    private function components(string $glyph): array
    {
        if (strlen($glyph) < 10 || unpack('n', $glyph, 0)[1] < 0x8000) {
            return []; // numberOfContours >= 0: a simple glyph
        }
        $out = [];
        $at = 10;
        do {
            [$flags, $component] = [unpack('n', $glyph, $at)[1], unpack('n', $glyph, $at + 2)[1]];
            $out[] = $component;
            $at += 4 + (($flags & 0x0001) ? 4 : 2);                 // the two arguments: words or bytes
            $at += ($flags & 0x0008) ? 2 : (($flags & 0x0040) ? 4 : (($flags & 0x0080) ? 8 : 0)); // the scale
        } while (($flags & 0x0020) && $at + 4 <= strlen($glyph)); // MORE_COMPONENTS
        return $out;
    }

    /**
     * A TrueType font with the outlines of $glyphs only (plus .notdef and the
     * glyphs composites are built from). Glyph ids stay the same, so the PDF
     * can use them directly; all other glyphs are left empty.
     */
    public function subset(array $glyphs): string
    {
        $keep = [0 => true];
        $todo = array_keys(array_flip(array_map('intval', $glyphs)));
        while ($todo) {
            $g = array_pop($todo);
            if ($g < 0 || $g >= $this->numGlyphs || isset($keep[$g])) {
                continue;
            }
            $keep[$g] = true;
            foreach ($this->components($this->glyphData($g)) as $c) {
                $todo[] = $c;
            }
        }
        $glyf = '';
        $loca = '';
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $loca .= pack('N', strlen($glyf));
            if (isset($keep[$g])) {
                $data = $this->glyphData($g);
                $glyf .= $data . str_repeat("\0", (4 - strlen($data) % 4) % 4);
            }
        }
        $loca .= pack('N', strlen($glyf));

        $head = $this->table('head');
        $head = substr_replace($head, "\0\0\0\0", 8, 4);      // checkSumAdjustment: not used by PDF viewers
        $head = substr_replace($head, pack('n', 1), 50, 2);   // the new loca has long offsets
        $tables = ['head' => $head, 'hhea' => $this->table('hhea'), 'maxp' => $this->table('maxp'),
            'hmtx' => $this->table('hmtx'), 'loca' => $loca, 'glyf' => $glyf];
        foreach (['OS/2', 'cvt ', 'fpgm', 'prep'] as $tag) {
            if (isset($this->tables[$tag])) {
                $tables[$tag] = $this->table($tag);
            }
        }
        return self::build($tables);
    }

    private function table(string $tag): string
    {
        return substr($this->data, $this->tables[$tag][0], $this->tables[$tag][1]);
    }

    /** A TrueType file from its tables (sorted, aligned, with checksums). */
    private static function build(array $tables): string
    {
        ksort($tables, SORT_STRING);
        $n = count($tables);
        $power = 1;
        $log = 0;
        while ($power * 2 <= $n) {
            $power *= 2;
            $log++;
        }
        $out = pack('Nnnnn', 0x00010000, $n, $power * 16, $log, $n * 16 - $power * 16);
        $offset = 12 + 16 * $n;
        $body = '';
        foreach ($tables as $tag => $data) {
            $padded = $data . str_repeat("\0", (4 - strlen($data) % 4) % 4);
            $sum = 0;
            foreach (unpack('N*', $padded) ?: [] as $word) {
                $sum = ($sum + $word) & 0xFFFFFFFF;
            }
            $out .= $tag . pack('NNN', $sum, $offset + strlen($body), strlen($data));
            $body .= $padded;
        }
        return $out . $body;
    }
}
