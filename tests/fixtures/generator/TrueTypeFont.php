<?php

namespace GlyphOcr\Tests\Fixtures\Generator;

/**
 * TrueTypeFont reads the glyph outlines and advance widths of a TrueType font with a glyf table.
 * It ignores hinting, kerning and every table the fixtures do not need.
 */
final class TrueTypeFont
{
    public readonly int $unitsPerEm;
    public readonly int $ascender;
    public readonly int $descender;

    /** @var array<string, array{int, int}> table tag to [offset, length] */
    private array $tables = [];

    /** @var array<int, int> code point to glyph id */
    private array $cmap = [];

    /** @var list<int> */
    private array $advances = [];

    /** @var list<int> */
    private array $locations = [];


    public function __construct(private readonly string $data)
    {
        $numTables = $this->u16(4);
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + $i * 16;
            $this->tables[substr($data, $record, 4)] = [$this->u32($record + 8), $this->u32($record + 12)];
        }

        $head = $this->table('head');
        $this->unitsPerEm = $this->u16($head + 18);
        $longLocations = $this->i16($head + 50) === 1;

        $hhea = $this->table('hhea');
        $this->ascender = $this->i16($hhea + 4);
        $this->descender = $this->i16($hhea + 6);
        $metricCount = $this->u16($hhea + 34);

        $glyphCount = $this->u16($this->table('maxp') + 4);
        $hmtx = $this->table('hmtx');
        for ($i = 0; $i < $glyphCount; $i++) {
            $this->advances[] = $this->u16($hmtx + 4 * min($i, $metricCount - 1));
        }

        $loca = $this->table('loca');
        for ($i = 0; $i <= $glyphCount; $i++) {
            $this->locations[] = $longLocations ? $this->u32($loca + 4 * $i) : 2 * $this->u16($loca + 2 * $i);
        }

        $this->readCmap();
    }


    public function glyphId(int $codePoint): int
    {
        return $this->cmap[$codePoint] ?? 0;
    }


    public function advance(int $glyphId): int
    {
        return $this->advances[$glyphId];
    }


    /**
     * Returns the contours in font units, each a list of [x, y, on curve].
     *
     * @return list<list<array{float, float, bool}>>
     */
    public function contours(int $glyphId, int $depth = 0): array
    {
        $start = $this->locations[$glyphId];
        if ($this->locations[$glyphId + 1] === $start || $depth > 8) {
            return [];
        }
        $offset = $this->table('glyf') + $start;
        $contourCount = $this->i16($offset);

        return $contourCount >= 0 ? $this->simpleContours($offset, $contourCount)
            : $this->compositeContours($offset, $depth);
    }


    /**
     * @return list<list<array{float, float, bool}>>
     */
    private function simpleContours(int $offset, int $contourCount): array
    {
        $endPoints = [];
        for ($i = 0; $i < $contourCount; $i++) {
            $endPoints[] = $this->u16($offset + 10 + 2 * $i);
        }
        if ($contourCount === 0) {
            return [];
        }
        $pointCount = $endPoints[$contourCount - 1] + 1;
        $cursor = $offset + 10 + 2 * $contourCount;
        $cursor += 2 + $this->u16($cursor);

        $flags = [];
        while (count($flags) < $pointCount) {
            $flag = ord($this->data[$cursor++]);
            $flags[] = $flag;
            if ($flag & 8) {
                $repeat = ord($this->data[$cursor++]);
                for ($r = 0; $r < $repeat; $r++) {
                    $flags[] = $flag;
                }
            }
        }

        $xs = [];
        $value = 0;
        foreach ($flags as $flag) {
            if ($flag & 2) {
                $delta = ord($this->data[$cursor++]);
                $value += ($flag & 16) ? $delta : -$delta;
            } elseif (!($flag & 16)) {
                $value += $this->i16($cursor);
                $cursor += 2;
            }
            $xs[] = $value;
        }
        $ys = [];
        $value = 0;
        foreach ($flags as $flag) {
            if ($flag & 4) {
                $delta = ord($this->data[$cursor++]);
                $value += ($flag & 32) ? $delta : -$delta;
            } elseif (!($flag & 32)) {
                $value += $this->i16($cursor);
                $cursor += 2;
            }
            $ys[] = $value;
        }

        $contours = [];
        $first = 0;
        foreach ($endPoints as $last) {
            $contour = [];
            for ($i = $first; $i <= $last; $i++) {
                $contour[] = [(float)$xs[$i], (float)$ys[$i], ($flags[$i] & 1) === 1];
            }
            $contours[] = $contour;
            $first = $last + 1;
        }

        return $contours;
    }


    /**
     * @return list<list<array{float, float, bool}>>
     */
    private function compositeContours(int $offset, int $depth): array
    {
        $cursor = $offset + 10;
        $contours = [];
        do {
            $flags = $this->u16($cursor);
            $component = $this->u16($cursor + 2);
            $cursor += 4;
            if ($flags & 1) {
                $dx = $this->i16($cursor);
                $dy = $this->i16($cursor + 2);
                $cursor += 4;
            } else {
                $dx = $this->i8($cursor);
                $dy = $this->i8($cursor + 1);
                $cursor += 2;
            }
            [$a, $b, $c, $d] = [1.0, 0.0, 0.0, 1.0];
            if ($flags & 8) {
                $a = $d = $this->f2dot14($cursor);
                $cursor += 2;
            } elseif ($flags & 0x40) {
                $a = $this->f2dot14($cursor);
                $d = $this->f2dot14($cursor + 2);
                $cursor += 4;
            } elseif ($flags & 0x80) {
                $a = $this->f2dot14($cursor);
                $b = $this->f2dot14($cursor + 2);
                $c = $this->f2dot14($cursor + 4);
                $d = $this->f2dot14($cursor + 6);
                $cursor += 8;
            }
            foreach ($this->contours($component, $depth + 1) as $contour) {
                $contours[] = array_map(
                    fn(array $p): array => [$a * $p[0] + $c * $p[1] + $dx, $b * $p[0] + $d * $p[1] + $dy, $p[2]],
                    $contour,
                );
            }
        } while ($flags & 0x20);

        return $contours;
    }


    private function readCmap(): void
    {
        $cmap = $this->table('cmap');
        $count = $this->u16($cmap + 2);
        $best = null;
        $bestFormat = 0;
        for ($i = 0; $i < $count; $i++) {
            $record = $cmap + 4 + 8 * $i;
            $platform = $this->u16($record);
            $subtableOffset = $cmap + $this->u32($record + 4);
            $format = $this->u16($subtableOffset);
            if (($platform === 3 || $platform === 0) && ($format === 4 || $format === 12) && $format > $bestFormat) {
                $best = $subtableOffset;
                $bestFormat = $format;
            }
        }
        if ($best === null) {
            throw new \RuntimeException('The font has no Unicode cmap of format 4 or 12.');
        }

        if ($bestFormat === 12) {
            $groups = $this->u32($best + 12);
            for ($g = 0; $g < $groups; $g++) {
                $record = $best + 16 + 12 * $g;
                $startCode = $this->u32($record);
                $endCode = $this->u32($record + 4);
                $startGlyph = $this->u32($record + 8);
                for ($code = $startCode; $code <= $endCode && $code <= 0x2FFFF; $code++) {
                    $this->cmap[$code] = $startGlyph + $code - $startCode;
                }
            }

            return;
        }

        $segments = $this->u16($best + 6) >> 1;
        $ends = $best + 14;
        $starts = $ends + 2 * $segments + 2;
        $deltas = $starts + 2 * $segments;
        $rangeOffsets = $deltas + 2 * $segments;
        for ($s = 0; $s < $segments; $s++) {
            $end = $this->u16($ends + 2 * $s);
            $start = $this->u16($starts + 2 * $s);
            $delta = $this->u16($deltas + 2 * $s);
            $rangeOffset = $this->u16($rangeOffsets + 2 * $s);
            for ($code = $start; $code <= $end && $code !== 0xFFFF; $code++) {
                if ($rangeOffset === 0) {
                    $glyph = ($code + $delta) & 0xFFFF;
                } else {
                    $glyph = $this->u16($rangeOffsets + 2 * $s + $rangeOffset + 2 * ($code - $start));
                    if ($glyph !== 0) {
                        $glyph = ($glyph + $delta) & 0xFFFF;
                    }
                }
                $this->cmap[$code] = $glyph;
            }
        }
    }


    private function table(string $tag): int
    {
        if (!isset($this->tables[$tag])) {
            throw new \RuntimeException("The font has no $tag table.");
        }

        return $this->tables[$tag][0];
    }


    private function u32(int $offset): int
    {
        return unpack('N', $this->data, $offset)[1];
    }


    private function u16(int $offset): int
    {
        return unpack('n', $this->data, $offset)[1];
    }


    private function i16(int $offset): int
    {
        $value = $this->u16($offset);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }


    private function i8(int $offset): int
    {
        $value = ord($this->data[$offset]);

        return $value >= 0x80 ? $value - 0x100 : $value;
    }


    private function f2dot14(int $offset): float
    {
        return $this->i16($offset) / 16384.0;
    }
}
