<?php

namespace GlyphOcr\Internal;

/**
 * LinePoints lists the pixels of a line segment exactly as NOcrLine.GetPoints of Subtitle Edit does.
 *
 * @internal
 */
final class LinePoints
{
    /**
     * Returns the points as a flat list: x0, y0, x1, y1, and so on.
     *
     * @return list<int>
     */
    public static function walk(int $startX, int $startY, int $endX, int $endY): array
    {
        $dx = $endX - $startX;
        $dy = $endY - $startY;
        $points = [];
        if (abs($dx) > abs($dy)) {
            [$x1, $y1, $x2, $y2] = $dx > 0 ? [$startX, $startY, $endX, $endY] : [$endX, $endY, $startX, $startY];
            $factor = ($y2 - $y1) / ($x2 - $x1);
            for ($i = $x1; $i <= $x2; $i++) {
                $points[] = $i;
                $points[] = Rounding::awayFromZero($y1 + $factor * ($i - $x1));
            }

            return $points;
        }

        [$x1, $y1, $x2, $y2] = $dy > 0 ? [$startX, $startY, $endX, $endY] : [$endX, $endY, $startX, $startY];
        if ($y2 === $y1) {
            // A single point: Subtitle Edit divides 0 by 0 here, and .NET turns the NaN into x = 0.
            return [0, $y1];
        }
        $factor = ($x2 - $x1) / ($y2 - $y1);
        for ($i = $y1; $i <= $y2; $i++) {
            $points[] = Rounding::awayFromZero($x1 + $factor * ($i - $y1));
            $points[] = $i;
        }

        return $points;
    }


    /**
     * Scales a line from the trained glyph size to the target size, then walks it.
     *
     * @param array{int, int, int, int} $line
     * @return list<int>
     */
    public static function walkScaled(array $line, int $glyphWidth, int $glyphHeight, int $width, int $height): array
    {
        return self::walk(
            Rounding::awayFromZero($line[0] * $width / $glyphWidth),
            Rounding::awayFromZero($line[1] * $height / $glyphHeight),
            Rounding::awayFromZero($line[2] * $width / $glyphWidth),
            Rounding::awayFromZero($line[3] * $height / $glyphHeight),
        );
    }
}
