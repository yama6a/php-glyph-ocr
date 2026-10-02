<?php

namespace GlyphOcr\Tests\Fixtures\Generator;

/**
 * Rasterizer fills outlines with exact area coverage, after the accumulation method of font-rs
 * (https://github.com/raphlinus/font-rs, Apache 2.0). It uses only float arithmetic, so every PHP build
 * gives the same bytes.
 */
final class Rasterizer
{
    /** @var list<float> */
    private array $accumulator;


    public function __construct(public readonly int $width, public readonly int $height)
    {
        $this->accumulator = array_fill(0, $width * $height + 4, 0.0);
    }


    /**
     * Adds the contours of a glyph. Points are in font units, y up. The transform maps them to pixels, y down.
     *
     * @param list<list<array{float, float, bool}>> $contours
     */
    public function addContours(array $contours, float $scale, float $originX, float $baselineY): void
    {
        foreach ($contours as $contour) {
            $points = array_map(
                fn(array $p): array => [$originX + $p[0] * $scale, $baselineY - $p[1] * $scale, $p[2]],
                $contour,
            );
            $this->addContour($points);
        }
    }


    /**
     * @param list<array{float, float, bool}> $points
     */
    private function addContour(array $points): void
    {
        $count = count($points);
        if ($count < 2) {
            return;
        }

        // Start at an on-curve point, or at the midpoint of the first two off-curve points.
        $startIndex = null;
        foreach ($points as $i => $point) {
            if ($point[2]) {
                $startIndex = $i;
                break;
            }
        }
        if ($startIndex === null) {
            $start = [($points[0][0] + $points[1][0]) / 2, ($points[0][1] + $points[1][1]) / 2];
            $startIndex = 0;
        } else {
            $start = [$points[$startIndex][0], $points[$startIndex][1]];
            $startIndex++;
        }

        $current = $start;
        $control = null;
        for ($k = 0; $k < $count; $k++) {
            $point = $points[($startIndex + $k) % $count];
            $xy = [$point[0], $point[1]];
            if ($point[2]) {
                if ($control === null) {
                    $this->line($current, $xy);
                } else {
                    $this->quadratic($current, $control, $xy);
                    $control = null;
                }
                $current = $xy;
            } elseif ($control === null) {
                $control = $xy;
            } else {
                $middle = [($control[0] + $xy[0]) / 2, ($control[1] + $xy[1]) / 2];
                $this->quadratic($current, $control, $middle);
                $current = $middle;
                $control = $xy;
            }
        }
        if ($control === null) {
            $this->line($current, $start);
        } else {
            $this->quadratic($current, $control, $start);
        }
    }


    /**
     * @param array{float, float} $p0
     * @param array{float, float} $p1
     * @param array{float, float} $p2
     */
    private function quadratic(array $p0, array $p1, array $p2): void
    {
        $deviation = abs($p0[0] - 2 * $p1[0] + $p2[0]) + abs($p0[1] - 2 * $p1[1] + $p2[1]);
        $segments = max(1, min(32, (int)ceil(sqrt($deviation * 2))));
        $previous = $p0;
        for ($i = 1; $i <= $segments; $i++) {
            $t = $i / $segments;
            $u = 1 - $t;
            $next = [
                $u * $u * $p0[0] + 2 * $u * $t * $p1[0] + $t * $t * $p2[0],
                $u * $u * $p0[1] + 2 * $u * $t * $p1[1] + $t * $t * $p2[1],
            ];
            $this->line($previous, $next);
            $previous = $next;
        }
    }


    /**
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    private function line(array $from, array $to): void
    {
        if (abs($from[1] - $to[1]) <= 1e-9) {
            return;
        }
        [$direction, $p0, $p1] = $from[1] < $to[1] ? [1.0, $from, $to] : [-1.0, $to, $from];
        $dxdy = ($p1[0] - $p0[0]) / ($p1[1] - $p0[1]);
        $x = $p0[0];
        if ($p0[1] < 0) {
            $x -= $p0[1] * $dxdy;
        }
        $yEnd = min($this->height, (int)ceil($p1[1]));
        for ($y = max(0, (int)$p0[1]); $y < $yEnd; $y++) {
            $lineStart = $y * $this->width;
            $dy = min($y + 1.0, $p1[1]) - max((float)$y, $p0[1]);
            $xNext = $x + $dxdy * $dy;
            $d = $dy * $direction;
            [$x0, $x1] = $x < $xNext ? [$x, $xNext] : [$xNext, $x];
            $x0Floor = floor($x0);
            $x0i = (int)$x0Floor;
            $x1Ceil = ceil($x1);
            $x1i = (int)$x1Ceil;
            $base = $lineStart + $x0i;
            if ($base < 0) {
                $x = $xNext;
                continue;
            }
            if ($x1i <= $x0i + 1) {
                $xmf = 0.5 * ($x + $xNext) - $x0Floor;
                $this->accumulator[$base] += $d - $d * $xmf;
                $this->accumulator[$base + 1] += $d * $xmf;
            } else {
                $s = 1.0 / ($x1 - $x0);
                $x0f = $x0 - $x0Floor;
                $a0 = 0.5 * $s * (1.0 - $x0f) * (1.0 - $x0f);
                $x1f = $x1 - $x1Ceil + 1.0;
                $am = 0.5 * $s * $x1f * $x1f;
                $this->accumulator[$base] += $d * $a0;
                if ($x1i === $x0i + 2) {
                    $this->accumulator[$base + 1] += $d * (1.0 - $a0 - $am);
                } else {
                    $a1 = $s * (1.5 - $x0f);
                    $this->accumulator[$base + 1] += $d * ($a1 - $a0);
                    for ($xi = $x0i + 2; $xi < $x1i - 1; $xi++) {
                        $this->accumulator[$lineStart + $xi] += $d * $s;
                    }
                    $a2 = $a1 + ($x1i - $x0i - 3) * $s;
                    $this->accumulator[$lineStart + $x1i - 1] += $d * (1.0 - $a2 - $am);
                }
                $this->accumulator[$lineStart + $x1i] += $d * $am;
            }
            $x = $xNext;
        }
    }


    /**
     * Returns the coverage of every pixel, from 0 to 1, indexed by y * width + x.
     *
     * @return list<float>
     */
    public function coverage(): array
    {
        $coverage = [];
        $sum = 0.0;
        $count = $this->width * $this->height;
        for ($i = 0; $i < $count; $i++) {
            $sum += $this->accumulator[$i];
            $coverage[] = min(1.0, abs($sum));
        }

        return $coverage;
    }
}
