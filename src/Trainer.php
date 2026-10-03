<?php

namespace GlyphOcr;

use GlyphOcr\Exceptions\InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Trainer turns a glyph sample with its confirmed text into a database glyph. Port of the random line
 * generator of NOcrChar in Subtitle Edit.
 *
 * The trainer draws random line segments and keeps those that run fully over ink (foreground) or fully over
 * background. With the same seed, the same sample always gives the same glyph.
 */
final class Trainer
{
    private const GIVE_UP_COUNT = 15_000;

    /** Angles closer than this count as equal, so the result does not depend on the last bit of atan2(). */
    private const ANGLE_EPSILON = 1e-9;

    private Randomizer $random;


    /**
     * @param int $lineCount the line segments to find of each kind; Subtitle Edit uses 60
     */
    public function __construct(private readonly int $lineCount = 60, int $seed = 0)
    {
        if ($lineCount < 1 || $lineCount > 255) {
            throw new InvalidArgumentException("Cannot create a trainer with $lineCount lines - it needs 1 to 255!");
        }
        $this->random = new Randomizer(new Mt19937($seed));
    }


    public function train(GlyphSample $sample, string $text, bool $italic = false): Glyph
    {
        if ($text === '') {
            throw new InvalidArgumentException("Cannot train a glyph without text!");
        }

        $lineCount = $this->lineCount + ($sample->parts === 1 ? 0 : ($sample->parts === 2 ? 5 : 10));
        $foreground = $this->generate($sample, $lineCount, true);
        $background = $this->generate($sample, $lineCount, false);

        return new Glyph($text, $sample->width, $sample->height, $sample->marginTop, $italic,
                         $sample->parts > 1 ? $sample->parts : 0, $foreground, $background);
    }


    /**
     * @return list<array{int, int, int, int}>
     */
    private function generate(GlyphSample $sample, int $maxLines, bool $foreground): array
    {
        $width = $sample->width;
        $height = $sample->height;
        $lines = [];
        $count = 0;
        $hits = 0;
        $veryPrecise = false;
        $verticalX = 2;
        $horizontalY = 2;
        $large = $width > 4 && $height > 4;

        while ($hits < $maxLines && $count < self::GIVE_UP_COUNT) {
            $start = [$this->random->getInt(0, $width - 1), $this->random->getInt(0, $height - 1)];
            $end = [$this->random->getInt(0, $width - 1), $this->random->getInt(0, $height - 1)];

            if ($count === 1 && !$foreground && $large) {
                [$start, $end] = [[0, 4], [4, 0]];
            } elseif ($count === 2 && !$foreground && $large) {
                [$start, $end] = [[0, 2], [2, 0]];
            } elseif ($count === 3 && !$foreground && $large) {
                [$start, $end] = [[$width, 4], [$width - 4, 0]];
            } elseif ($count === 4 && !$foreground && $large) {
                [$start, $end] = [[$width, 2], [$width - 2, 0]];
            } elseif ($foreground && $hits < 5 && $count < 200 && $large) {
                [$start, $end] = [[0, 0], [0, 0]];
                for (; $verticalX < $width - 3; $verticalX++) {
                    $start = [$verticalX, 2];
                    $end = [$verticalX, $height - 3];
                    if ($this->isMatch($sample, [...$start, ...$end], true, true)) {
                        $verticalX++;
                        break;
                    }
                }
            } elseif ($hits < 10 && $count < ($foreground ? 400 : 1000) && $large) {
                [$start, $end] = [[0, 0], [0, 0]];
                for (; $horizontalY < $height - 3; $horizontalY++) {
                    $start = [2, $horizontalY];
                    $end = [$width - ($foreground ? 3 : 2), $horizontalY];
                    if ($this->isMatch($sample, [...$start, ...$end], true, $foreground)) {
                        $horizontalY++;
                        break;
                    }
                }
            } elseif ($hits < ($foreground ? 20 : 10) && $count < ($foreground ? 2000 : 1000)) {
                for ($k = 0; $k < 500; $k++) {
                    if (abs($start[0] - $end[0]) + abs($start[1] - $end[1]) > intdiv($height, 2)) {
                        break;
                    }
                    $end = [$this->random->getInt(0, $width - 1), $this->random->getInt(0, $height - 1)];
                }
            } elseif ($hits < 30 && $count < ($foreground ? 3000 : 2000)) {
                for ($k = 0; $k < 500; $k++) {
                    if (abs($start[0] - $end[0]) + abs($start[1] - $end[1]) < 15) {
                        break;
                    }
                    $end = [$this->random->getInt(0, $width - 1), $this->random->getInt(0, $height - 1)];
                }
            } else {
                $minLength = $foreground ? 15 : 5;
                for ($k = 0; $k < 500; $k++) {
                    if (abs($start[0] - $end[0]) + abs($start[1] - $end[1]) < $minLength) {
                        break;
                    }
                    $end = [$this->random->getInt(0, $width - 1), $this->random->getInt(0, $height - 1)];
                }
            }

            $line = [...$start, ...$end];
            $ok = !in_array($line, $lines, true) && $start !== $end;
            if ($ok && $this->isMatch($sample, $line, !$veryPrecise, $foreground)) {
                $lines[] = $line;
                $hits++;
            }

            $count++;
            if ($count > self::GIVE_UP_COUNT - 100) {
                $veryPrecise = true;
            }
        }

        $lines = self::removeContained($lines);

        return self::removeSimilar($lines, $width, $height);
    }


    /**
     * Returns true when every point of the line is on ink (foreground) or on background. With $strict, the four
     * neighbours of each point must agree too, on glyphs wider or taller than 10 pixels.
     *
     * @param array{int, int, int, int} $line
     */
    private function isMatch(GlyphSample $sample, array $line, bool $strict, bool $foreground): bool
    {
        $width = $sample->width;
        $height = $sample->height;
        if ($foreground && abs($line[0] - $line[2]) < 2 && abs($line[3] - $line[1]) < 2) {
            return false;
        }

        $pixels = $sample->pixels;
        $wrong = $foreground ? 0 : 1;
        $points = Internal\LinePoints::walk(...$line);
        for ($i = 0, $n = count($points); $i < $n; $i += 2) {
            $x = $points[$i];
            $y = $points[$i + 1];
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
                continue;
            }
            if ($pixels[$y * $width + $x] === $wrong) {
                return false;
            }
            if (!$strict) {
                continue;
            }
            if ($width > 10 && $x + 1 < $width && $pixels[$y * $width + $x + 1] === $wrong) {
                return false;
            }
            if ($width > 10 && $x >= 1 && $pixels[$y * $width + $x - 1] === $wrong) {
                return false;
            }
            if ($height > 10 && $y + 1 < $height && $pixels[($y + 1) * $width + $x] === $wrong) {
                return false;
            }
            if ($height > 10 && $y >= 1 && $pixels[($y - 1) * $width + $x] === $wrong) {
                return false;
            }
        }

        return true;
    }


    /**
     * Drops a vertical or horizontal line that lies inside another one on the same column or row.
     *
     * @param list<array{int, int, int, int}> $lines
     * @return list<array{int, int, int, int}>
     */
    private static function removeContained(array $lines): array
    {
        $delete = [];
        foreach ($lines as $index => $outer) {
            foreach ($lines as $innerIndex => $inner) {
                if ($innerIndex === $index || isset($delete[$innerIndex])) {
                    continue;
                }
                if ($inner[0] === $inner[2] && $outer[0] === $outer[2] && $inner[0] === $outer[0]) {
                    if (max($inner[1], $inner[3]) <= max($outer[1], $outer[3]) &&
                        min($inner[1], $inner[3]) >= min($outer[1], $outer[3])) {
                        $delete[$innerIndex] = true;
                    }
                } elseif ($inner[1] === $inner[3] && $outer[1] === $outer[3] && $inner[1] === $outer[1]) {
                    if (max($inner[0], $inner[2]) <= max($outer[0], $outer[2]) &&
                        min($inner[0], $inner[2]) >= min($outer[0], $outer[2])) {
                        $delete[$innerIndex] = true;
                    }
                }
            }
        }

        return array_values(array_diff_key($lines, $delete));
    }


    /**
     * Drops one of two lines with about the same angle and midpoint, keeping the longer or the more upright one.
     *
     * @param list<array{int, int, int, int}> $lines
     * @return list<array{int, int, int, int}>
     */
    private static function removeSimilar(array $lines, int $width, int $height): array
    {
        $count = count($lines);
        if ($count < 2) {
            return $lines;
        }

        $angleThreshold = M_PI * 5.0 / 180.0;
        $midThreshold = max(2.0, min($width, $height) / 8.0);
        $midThresholdSquared = $midThreshold * $midThreshold;
        $angles = [];
        $midX = [];
        $midY = [];
        $lengths = [];
        foreach ($lines as $i => [$x1, $y1, $x2, $y2]) {
            // A line and its reverse get the same angle on every platform only when atan2() sees the same input.
            $reverse = $x2 < $x1 || ($x2 === $x1 && $y2 < $y1);
            $angle = $reverse ? atan2($y1 - $y2, $x1 - $x2) : atan2($y2 - $y1, $x2 - $x1);
            if ($angle < 0) {
                $angle += M_PI;
            }
            $angles[$i] = $angle;
            $midX[$i] = ($x1 + $x2) / 2.0;
            $midY[$i] = ($y1 + $y2) / 2.0;
            $lengths[$i] = ($x2 - $x1) ** 2 + ($y2 - $y1) ** 2;
        }

        $remove = [];
        for ($i = 0; $i < $count; $i++) {
            if (isset($remove[$i])) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if (isset($remove[$j])) {
                    continue;
                }
                $angleDelta = abs($angles[$i] - $angles[$j]);
                if ($angleDelta > M_PI / 2) {
                    $angleDelta = M_PI - $angleDelta;
                }
                if ($angleDelta > $angleThreshold) {
                    continue;
                }
                $dx = $midX[$i] - $midX[$j];
                $dy = $midY[$i] - $midY[$j];
                if ($dx * $dx + $dy * $dy > $midThresholdSquared) {
                    continue;
                }

                $maxLength = max($lengths[$i], $lengths[$j]);
                if (abs($lengths[$i] - $lengths[$j]) <= $maxLength * 0.25) {
                    $axisI = self::distanceToAxis($angles[$i]);
                    $axisJ = self::distanceToAxis($angles[$j]);
                    $dropJ = abs($axisI - $axisJ) > self::ANGLE_EPSILON ? $axisI <= $axisJ
                        : $lengths[$j] <= $lengths[$i];
                } else {
                    $dropJ = $lengths[$j] <= $lengths[$i];
                }

                if ($dropJ) {
                    $remove[$j] = true;
                } else {
                    $remove[$i] = true;
                    break;
                }
            }
        }

        return array_values(array_diff_key($lines, $remove));
    }


    private static function distanceToAxis(float $angle): float
    {
        return min(min($angle, M_PI - $angle), abs($angle - M_PI / 2));
    }
}
