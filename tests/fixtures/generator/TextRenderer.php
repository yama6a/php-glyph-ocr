<?php

namespace GlyphOcr\Tests\Fixtures\Generator;

/**
 * TextRenderer draws centred text lines as an image subtitle: a fill colour, an optional outline, and a
 * transparent background.
 */
final class TextRenderer
{
    /**
     * Returns the PNG bytes.
     *
     * @param list<string> $lines
     * @param array{int, int, int} $fill
     * @param array{int, int, int} $outline
     * @param string $output "rgba" for 8-bit RGBA, "pgs" for a palette of up to 256 entries like Blu-ray,
     *                       "dvd" for a 4-colour palette like DVD
     */
    public static function render(TrueTypeFont $font, array $lines, float $size, array $fill, array $outline,
                                  float $outlineWidth, bool $antiAlias, string $output, int $padding,
                                  ?int $canvasWidth = null): string
    {
        $scale = $size / $font->unitsPerEm;
        $lineHeight = ($font->ascender - $font->descender) * $scale;
        $lineStep = (int)ceil($lineHeight * 1.05);
        $margin = (int)ceil($outlineWidth) + 2;

        $widths = array_map(fn(string $line): float => self::advance($font, $line) * $scale, $lines);
        $textWidth = (int)ceil(max($widths));
        $width = max($canvasWidth ?? 0, $textWidth + 2 * ($padding + $margin));
        $height = count($lines) * $lineStep + 2 * ($padding + $margin);

        $rasterizer = new Rasterizer($width, $height);
        foreach ($lines as $index => $line) {
            $x = ($width - $widths[$index]) / 2;
            $baseline = $padding + $margin + $index * $lineStep + $font->ascender * $scale;
            foreach (mb_str_split($line) as $char) {
                $glyph = $font->glyphId(mb_ord($char));
                $rasterizer->addContours($font->contours($glyph), $scale, $x, $baseline);
                $x += $font->advance($glyph) * $scale;
            }
        }
        $fillCoverage = $rasterizer->coverage();
        $outlineCoverage = $outlineWidth > 0 ? self::dilate($fillCoverage, $width, $height, $outlineWidth)
            : array_fill(0, $width * $height, 0.0);

        if (!$antiAlias) {
            $fillCoverage = array_map(fn(float $c): float => $c >= 0.5 ? 1.0 : 0.0, $fillCoverage);
            $outlineCoverage = array_map(fn(float $c): float => $c >= 0.5 ? 1.0 : 0.0, $outlineCoverage);
        }

        return match ($output) {
            'rgba' => self::rgbaPng($width, $height, $fillCoverage, $outlineCoverage, $fill, $outline, 255),
            'pgs'  => self::palettePng($width, $height, $fillCoverage, $outlineCoverage, $fill, $outline, 15),
            'dvd'  => self::dvdPng($width, $height, $fillCoverage, $outlineCoverage, $fill, $outline),
        };
    }


    private static function advance(TrueTypeFont $font, string $line): int
    {
        $total = 0;
        foreach (mb_str_split($line) as $char) {
            $total += $font->advance($font->glyphId(mb_ord($char)));
        }

        return $total;
    }


    /**
     * Returns the outline coverage: the highest fill coverage within the outline width, with a soft edge.
     *
     * @param list<float> $coverage
     * @return list<float>
     */
    private static function dilate(array $coverage, int $width, int $height, float $radius): array
    {
        $reach = (int)ceil($radius + 0.5);
        $offsets = [];
        for ($dy = -$reach; $dy <= $reach; $dy++) {
            for ($dx = -$reach; $dx <= $reach; $dx++) {
                $weight = min(1.0, max(0.0, $radius + 0.5 - sqrt($dx * $dx + $dy * $dy)));
                if ($weight > 0) {
                    $offsets[] = [$dx, $dy, $weight];
                }
            }
        }

        $result = array_fill(0, $width * $height, 0.0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $value = $coverage[$y * $width + $x];
                if ($value <= 0.0) {
                    continue;
                }
                foreach ($offsets as [$dx, $dy, $weight]) {
                    $tx = $x + $dx;
                    $ty = $y + $dy;
                    if ($tx < 0 || $ty < 0 || $tx >= $width || $ty >= $height) {
                        continue;
                    }
                    $candidate = $value * $weight;
                    $index = $ty * $width + $tx;
                    if ($candidate > $result[$index]) {
                        $result[$index] = $candidate;
                    }
                }
            }
        }

        return $result;
    }


    /**
     * Puts the fill over the outline. Returns [r, g, b, a] as integers from 0 to 255.
     *
     * @param array{int, int, int} $fill
     * @param array{int, int, int} $outline
     * @return array{int, int, int, int}
     */
    private static function compose(float $f, float $o, array $fill, array $outline): array
    {
        $o = max($o, $f);
        $alpha = $f + $o * (1 - $f);
        if ($alpha <= 0.0) {
            return [0, 0, 0, 0];
        }
        $pixel = [];
        for ($c = 0; $c < 3; $c++) {
            $pixel[] = (int)floor(($fill[$c] * $f + $outline[$c] * $o * (1 - $f)) / $alpha + 0.5);
        }
        $pixel[] = (int)floor($alpha * 255 + 0.5);

        return $pixel;
    }


    /**
     * @param list<float> $fillCoverage
     * @param list<float> $outlineCoverage
     * @param array{int, int, int} $fill
     * @param array{int, int, int} $outline
     */
    private static function rgbaPng(int $width, int $height, array $fillCoverage, array $outlineCoverage,
                                    array $fill, array $outline, int $levels): string
    {
        $rgba = '';
        foreach ($fillCoverage as $i => $f) {
            $rgba .= pack('C4', ...self::compose($f, $outlineCoverage[$i], $fill, $outline));
        }

        return PngWriter::rgba($width, $height, $rgba);
    }


    /**
     * Quantizes fill and outline coverage to 16 levels each, so the palette never needs more than 256 entries.
     *
     * @param list<float> $fillCoverage
     * @param list<float> $outlineCoverage
     * @param array{int, int, int} $fill
     * @param array{int, int, int} $outline
     */
    private static function palettePng(int $width, int $height, array $fillCoverage, array $outlineCoverage,
                                       array $fill, array $outline, int $levels): string
    {
        $palette = ["\0\0\0\0"];
        $lookup = ["\0\0\0\0" => 0];
        $indexes = '';
        foreach ($fillCoverage as $i => $f) {
            $qf = floor($f * $levels + 0.5) / $levels;
            $qo = floor($outlineCoverage[$i] * $levels + 0.5) / $levels;
            $entry = pack('C4', ...self::compose($qf, $qo, $fill, $outline));
            if ($entry[3] === "\0") {
                $entry = "\0\0\0\0";
            }
            if (!isset($lookup[$entry])) {
                $lookup[$entry] = count($palette);
                $palette[] = $entry;
            }
            $indexes .= chr($lookup[$entry]);
        }

        return PngWriter::palette($width, $height, $palette, $indexes);
    }


    /**
     * Maps every pixel to one of the 4 DVD colours: background, fill, outline, and a fill edge colour.
     *
     * @param list<float> $fillCoverage
     * @param list<float> $outlineCoverage
     * @param array{int, int, int} $fill
     * @param array{int, int, int} $outline
     */
    private static function dvdPng(int $width, int $height, array $fillCoverage, array $outlineCoverage,
                                   array $fill, array $outline): string
    {
        $edge = [];
        for ($c = 0; $c < 3; $c++) {
            $edge[] = intdiv($fill[$c] + $outline[$c], 2);
        }
        $palette = ["\0\0\0\0", pack('C4', ...[...$fill, 255]), pack('C4', ...[...$outline, 255]), pack('C4', ...[...$edge, 255])];
        $indexes = '';
        foreach ($fillCoverage as $i => $f) {
            if ($f >= 0.6) {
                $indexes .= "\x01";
            } elseif ($f >= 0.25) {
                $indexes .= "\x03";
            } elseif ($outlineCoverage[$i] >= 0.5) {
                $indexes .= "\x02";
            } else {
                $indexes .= "\0";
            }
        }

        return PngWriter::palette($width, $height, $palette, $indexes);
    }
}
