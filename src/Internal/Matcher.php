<?php

namespace GlyphOcr\Internal;

use GlyphOcr\Glyph;
use GlyphOcr\GlyphDatabase;

/**
 * Matcher finds the database glyph for a cut-out glyph. Port of NOcrDb.GetMatch of Subtitle Edit.
 *
 * @internal
 */
final class Matcher
{
    /** A glyph with fewer lines than this matches almost anything of its size, so the matcher skips it. */
    private const MIN_LINES = 1;

    /** Below this pixel area, the matcher compares the aspect ratio as a ratio, not as a difference. */
    private const SMALL_GLYPH_AREA = 150;

    private const SMALL_GLYPH_MAX_ASPECT_RATIO = 2.0;

    private const MATCH_CACHE_LIMIT = 5000;

    private const POINT_CACHE_LIMIT = 500;

    /**
     * The cascade of match passes, strictest first. Keys: minAllowance (run only if the wrong pixel budget is
     * at least this), deepSeek and lastDitch (run only when the caller asks), aspect, size and margin (fail when
     * the difference is this or more, null for no check), minLines, sensitivity ('either', 'not', 'only'),
     * errors (budget: an int, 'max', 'max2', or [cap]), sensitiveErrors and sensitiveAspect (for glyphs like O
     * and 0, null for the same as the others).
     */
    private const PASSES = [
        ['minAllowance' => 0, 'deepSeek' => false, 'lastDitch' => false, 'aspect' => 15, 'size' => 5,
         'margin' => 5, 'minLines' => 0, 'errors' => 0, 'sensitiveErrors' => null, 'sensitiveAspect' => null],
        ['minAllowance' => 1, 'deepSeek' => false, 'lastDitch' => false, 'aspect' => null, 'size' => 4,
         'margin' => 8, 'minLines' => 0, 'errors' => 1, 'sensitiveErrors' => null, 'sensitiveAspect' => null],
        ['minAllowance' => 1, 'deepSeek' => false, 'lastDitch' => false, 'aspect' => null, 'size' => 8,
         'margin' => 8, 'minLines' => 0, 'errors' => 1, 'sensitiveErrors' => null, 'sensitiveAspect' => null],
        ['minAllowance' => 2, 'deepSeek' => false, 'lastDitch' => false, 'aspect' => 20, 'size' => null,
         'margin' => 15, 'minLines' => 0, 'errors' => [3], 'sensitiveErrors' => null, 'sensitiveAspect' => null],
        ['minAllowance' => 10, 'deepSeek' => false, 'lastDitch' => false, 'aspect' => 20, 'size' => null,
         'margin' => 15, 'minLines' => 41, 'errors' => [20], 'sensitiveErrors' => 10, 'sensitiveAspect' => 30],
        ['minAllowance' => 0, 'deepSeek' => true, 'lastDitch' => false, 'aspect' => 60, 'size' => null,
         'margin' => 17, 'minLines' => 51, 'errors' => 'max', 'sensitiveErrors' => null, 'sensitiveAspect' => null],
        ['minAllowance' => 0, 'deepSeek' => false, 'lastDitch' => true, 'aspect' => 80, 'size' => null,
         'margin' => 25, 'minLines' => 15, 'errors' => 'max2', 'sensitiveErrors' => null, 'sensitiveAspect' => null],
    ];

    /** @var array<string, array{bool, array{Glyph, float, bool}|null}> */
    private array $matchCache = [];

    /** @var array<string, array{list<int>, list<int>}> */
    private array $pointCache = [];

    /** @var array<string, list<Glyph>>|null */
    private ?array $sizeIndex = null;

    private int $revision;


    public function __construct(
        private readonly GlyphDatabase $database,
        private readonly int $maxWrongPixels,
        private readonly bool $deepSeek,
        private readonly bool $lastDitch,
        private readonly float $italicFactor,
    ) {
        $this->revision = $database->revision();
    }


    /**
     * Returns the glyph, the confidence from 0 to 1 and whether the match needed the italic retry.
     *
     * @param list<SplitItem> $items
     * @return array{Glyph, float, bool}|null
     */
    public function match(Bitmap $parent, array $items, int $index): ?array
    {
        if ($this->revision !== $this->database->revision()) {
            $this->matchCache = [];
            $this->pointCache = [];
            $this->sizeIndex = null;
            $this->revision = $this->database->revision();
        }

        $item = $items[$index];
        $bitmap = $item->bitmap;
        $key = $bitmap->width . ':' . $item->top . ':' . implode('', $bitmap->pixels);
        if (array_key_exists($key, $this->matchCache)) {
            [$isExact, $cached] = $this->matchCache[$key];
            if ($isExact) {
                return $cached;
            }

            // The expanded scan depends on the neighbouring glyphs, so it never comes from the cache.
            return $this->matchExpanded($parent, $item, $index, $items) ?? $cached;
        }

        $exact = $this->matchExact($bitmap, $item->top);
        if ($exact !== null) {
            $this->remember($key, true, [$exact, 1.0, false]);

            return [$exact, 1.0, false];
        }

        $expanded = $this->matchExpanded($parent, $item, $index, $items);
        if ($expanded !== null) {
            return $expanded;
        }

        $single = $this->matchSingle($bitmap, $item->top);
        if ($single !== null) {
            $single[] = false;
        } elseif ($this->italicFactor > 0) {
            $straight = $bitmap->unItalic($this->italicFactor);
            if ($straight->width > 0 && $straight->height > 0) {
                $italic = $this->matchExact($straight, $item->top);
                $italicMatch = $italic !== null ? [$italic, 1.0] : $this->matchSingle($straight, $item->top);
                if ($italicMatch !== null) {
                    $single = [$italicMatch[0], $italicMatch[1], true];
                }
            }
        }

        $this->remember($key, false, $single);

        return $single;
    }


    /**
     * @param array{Glyph, float, bool}|null $result
     */
    private function remember(string $key, bool $isExact, ?array $result): void
    {
        if (count($this->matchCache) >= self::MATCH_CACHE_LIMIT) {
            $this->matchCache = [];
        }
        $this->matchCache[$key] = [$isExact, $result];
    }


    private function matchExact(Bitmap $bitmap, int $topMargin): ?Glyph
    {
        if ($this->sizeIndex === null) {
            $this->sizeIndex = [];
            foreach ($this->database->singleGlyphs() as $glyph) {
                $this->sizeIndex[$glyph->width . 'x' . $glyph->height][] = $glyph;
            }
        }

        foreach ($this->sizeIndex[$bitmap->width . 'x' . $bitmap->height] ?? [] as $glyph) {
            if (abs($glyph->marginTop - $topMargin) < 5 && $this->countErrors($bitmap, $glyph, 0) !== null) {
                return $glyph;
            }
        }

        return null;
    }


    /**
     * @return array{Glyph, float}|null
     */
    private function matchSingle(Bitmap $bitmap, int $topMargin): ?array
    {
        $heightToWidth = $bitmap->height * 100.0 / $bitmap->width;
        $areaErrorCap = max(8, intdiv($bitmap->width * $bitmap->height, 8));
        $max = $this->maxWrongPixels;

        foreach (self::PASSES as $pass) {
            if (($pass['deepSeek'] && !$this->deepSeek) || ($pass['lastDitch'] && !$this->lastDitch) ||
                $max < $pass['minAllowance']) {
                continue;
            }
            $allowed = self::budget($pass['errors'], $max);

            $best = null;
            $bestErrors = PHP_INT_MAX;
            $bestPoints = 0;
            foreach ($this->database->singleGlyphs() as $glyph) {
                if (!self::passFilter($bitmap, $heightToWidth, $glyph, $topMargin, $pass)) {
                    continue;
                }
                $candidateAllowed = $glyph->isSensitive() && $pass['sensitiveErrors'] !== null
                    ? $pass['sensitiveErrors'] : $allowed;
                $budget = min($candidateAllowed, $areaErrorCap, $bestErrors - 1);
                $counted = $this->countErrors($bitmap, $glyph, $budget);
                if ($counted !== null) {
                    $best = $glyph;
                    [$bestErrors, $bestPoints] = $counted;
                    if ($bestErrors === 0) {
                        break;
                    }
                }
            }

            if ($best !== null) {
                return [$best, self::confidence($bestErrors, $bestPoints)];
            }
        }

        return null;
    }


    /**
     * @param int|string|array{int} $rule
     */
    private static function budget(int|string|array $rule, int $max): int
    {
        if (is_int($rule)) {
            return $rule;
        }
        if (is_array($rule)) {
            return min($rule[0], $max);
        }

        return $rule === 'max2' ? $max * 2 : $max;
    }


    /**
     * @param array<string, mixed> $pass
     */
    private static function passFilter(Bitmap $bitmap, float $heightToWidth, Glyph $glyph, int $topMargin,
                                       array $pass): bool
    {
        if ($bitmap->width * $bitmap->height < self::SMALL_GLYPH_AREA) {
            $glyphRatio = $glyph->heightToWidthPercent();
            $big = max($heightToWidth, $glyphRatio);
            $small = min($heightToWidth, $glyphRatio);
            if ($small <= 0 || $big / $small > self::SMALL_GLYPH_MAX_ASPECT_RATIO) {
                return false;
            }
        } else {
            $aspect = $glyph->isSensitive() && $pass['sensitiveAspect'] !== null
                ? $pass['sensitiveAspect'] : $pass['aspect'];
            if ($aspect !== null && abs($heightToWidth - $glyph->heightToWidthPercent()) >= $aspect) {
                return false;
            }
        }

        if ($pass['size'] !== null && (abs($bitmap->width - $glyph->width) >= $pass['size'] ||
                                       abs($bitmap->height - $glyph->height) >= $pass['size'])) {
            return false;
        }
        if (abs($glyph->marginTop - $topMargin) >= $pass['margin']) {
            return false;
        }

        return $pass['minLines'] === 0 || $glyph->lineCount() >= $pass['minLines'];
    }


    /**
     * Counts the line points that disagree with the bitmap. Returns [errors, points checked], or null as soon as
     * the errors exceed the budget.
     *
     * @return array{int, int}|null
     */
    private function countErrors(Bitmap $bitmap, Glyph $glyph, int $budget): ?array
    {
        if ($budget < 0 || $glyph->lineCount() < self::MIN_LINES) {
            return null;
        }

        $width = $bitmap->width;
        $height = $bitmap->height;
        $key = spl_object_id($glyph) . ':' . $width . ':' . $height;
        $cached = $this->pointCache[$key] ?? null;
        $pixels = $bitmap->pixels;
        $errors = 0;
        if ($cached !== null) {
            foreach ($cached[0] as $index) {
                if ($pixels[$index] === 0 && ++$errors > $budget) {
                    return null;
                }
            }
            foreach ($cached[1] as $index) {
                if ($pixels[$index] !== 0 && ++$errors > $budget) {
                    return null;
                }
            }

            return [$errors, count($cached[0]) + count($cached[1])];
        }

        // Walks the lines one by one, so a bad candidate stops after a few points. Only a full walk is cached.
        // The walk repeats LinePoints::walkScaled() inline, because this loop dominates the run time.
        // All coordinates are 0 or more here, so rounding half away from zero is (int)($v + 0.5).
        $indexes = [[], []];
        foreach ([$glyph->foregroundLines, $glyph->backgroundLines] as $kind => $lines) {
            $wrong = $kind === 0 ? 0 : 1;
            $list = [];
            foreach ($lines as [$ax, $ay, $bx, $by]) {
                $sx = (int)($ax * $width / $glyph->width + 0.5);
                $sy = (int)($ay * $height / $glyph->height + 0.5);
                $ex = (int)($bx * $width / $glyph->width + 0.5);
                $ey = (int)($by * $height / $glyph->height + 0.5);
                $dx = $ex - $sx;
                $dy = $ey - $sy;
                if (($dx < 0 ? -$dx : $dx) > ($dy < 0 ? -$dy : $dy)) {
                    if ($dx < 0) {
                        [$sx, $sy, $ex, $ey] = [$ex, $ey, $sx, $sy];
                    }
                    $clip = $ex >= $width || $sy >= $height || $ey >= $height;
                    $factor = ($ey - $sy) / ($ex - $sx);
                    for ($x = $sx; $x <= $ex; $x++) {
                        $y = (int)($sy + $factor * ($x - $sx) + 0.5);
                        if ($clip && ($x >= $width || $y >= $height)) {
                            continue;
                        }
                        $index = $y * $width + $x;
                        $list[] = $index;
                        if ($pixels[$index] === $wrong && ++$errors > $budget) {
                            return null;
                        }
                    }
                    continue;
                }
                if ($dy < 0) {
                    [$sx, $sy, $ex, $ey] = [$ex, $ey, $sx, $sy];
                }
                if ($ey === $sy) {
                    // A single point: Subtitle Edit divides 0 by 0 here, and .NET turns the NaN into x = 0.
                    $sx = 0;
                    $factor = 0.0;
                } else {
                    $factor = ($ex - $sx) / ($ey - $sy);
                }
                for ($y = $sy; $y <= $ey; $y++) {
                    $x = (int)($sx + $factor * ($y - $sy) + 0.5);
                    if ($x >= $width || $y >= $height) {
                        continue;
                    }
                    $index = $y * $width + $x;
                    $list[] = $index;
                    if ($pixels[$index] === $wrong && ++$errors > $budget) {
                        return null;
                    }
                }
            }
            $indexes[$kind] = $list;
        }

        if (count($this->pointCache) >= self::POINT_CACHE_LIMIT) {
            $this->pointCache = [];
        }
        $this->pointCache[$key] = $indexes;

        return [$errors, count($indexes[0]) + count($indexes[1])];
    }


    private static function confidence(int $errors, int $points): float
    {
        return $points > 0 ? max(0.0, 1.0 - $errors / $points) : 0.0;
    }


    /**
     * Tries the glyphs that span several cut-out parts, for example '"' or '%'.
     *
     * @param list<SplitItem> $items
     * @return array{Glyph, float, bool}|null
     */
    private function matchExpanded(Bitmap $parent, SplitItem $item, int $index, array $items): ?array
    {
        $width = $item->bitmap->width;
        $expandedGlyphs = $this->database->expandedGlyphs();
        foreach ($expandedGlyphs as $glyph) {
            if ($glyph->expandCount > 1 && $glyph->width > $width && $item->x + $glyph->width < $parent->width &&
                $glyph->lineCount() >= self::MIN_LINES &&
                self::isExpandedMatch($glyph, $item, $parent, $items, $index)) {
                [$sizeX, $sizeY] = self::totalSize($index, $items, $glyph->expandCount);
                if (abs($sizeX - $glyph->width) < 3 && abs($sizeY - $glyph->height) < 3) {
                    return [$glyph, 1.0, false];
                }
            }
        }

        foreach ($expandedGlyphs as $glyph) {
            if ($glyph->expandCount > 1 && $glyph->width > $width && $item->x + $glyph->width < $parent->width &&
                $glyph->lineCount() >= self::MIN_LINES) {
                [$sizeX, $sizeY] = self::totalSize($index, $items, $glyph->expandCount);
                if ($sizeX <= 0 || $sizeY <= 0) {
                    continue;
                }
                $heightToWidth = $sizeY * 100.0 / $sizeX;
                if (abs($heightToWidth - $glyph->heightToWidthPercent()) < 15 && abs($sizeX - $glyph->width) < 25 &&
                    abs($sizeY - $glyph->height) < 20 && self::isScaleRatioSane($sizeX, $glyph->width) &&
                    self::isScaleRatioSane($sizeY, $glyph->height)) {
                    $confidence = self::expandedScaledConfidence($glyph, $item, $parent, $sizeX, $sizeY, $items, $index);
                    if ($confidence !== null) {
                        return [$glyph, $confidence, false];
                    }
                }
            }
        }

        return null;
    }


    private static function isScaleRatioSane(int $target, int $glyph): bool
    {
        if ($target <= 0 || $glyph <= 0) {
            return false;
        }

        return max($target, $glyph) * 2 <= min($target, $glyph) * 5;
    }


    /**
     * @param list<SplitItem> $items
     */
    private static function expandedScaledConfidence(Glyph $glyph, SplitItem $item, Bitmap $parent, int $targetWidth,
                                                     int $targetHeight, array $items, int $index): ?float
    {
        $errors = 0;
        $points = 0;
        $scaledMarginTop = Rounding::awayFromZero($glyph->marginTop * $targetHeight / max(1, $glyph->height));
        $originY = $item->y - $scaledMarginTop;

        foreach ($glyph->foregroundLines as $line) {
            $walk = LinePoints::walkScaled($line, $glyph->width, $glyph->height, $targetWidth, $targetHeight);
            for ($i = 0, $n = count($walk); $i < $n; $i += 2) {
                $points++;
                $x = $walk[$i] + $item->x;
                $y = $walk[$i + 1] + $originY;
                if ($x < 0 || $y < 0 || $x >= $parent->width || $y >= $parent->height ||
                    !self::isGroupInk($items, $index, $glyph->expandCount, $x, $y)) {
                    $errors++;
                }
            }
        }
        foreach ($glyph->backgroundLines as $line) {
            $walk = LinePoints::walkScaled($line, $glyph->width, $glyph->height, $targetWidth, $targetHeight);
            for ($i = 0, $n = count($walk); $i < $n; $i += 2) {
                $points++;
                $x = $walk[$i] + $item->x;
                $y = $walk[$i + 1] + $originY;
                if ($x < 0 || $y < 0 || $x >= $parent->width || $y >= $parent->height) {
                    continue;
                }
                if ($parent->get($x, $y) !== 0) {
                    $errors++;
                }
            }
        }

        if ($errors > max(4, intdiv($points, 20))) {
            return null;
        }

        return self::confidence($errors, $points);
    }


    /**
     * @param list<SplitItem> $items
     */
    private static function isExpandedMatch(Glyph $glyph, SplitItem $item, Bitmap $parent, array $items,
                                            int $index): bool
    {
        foreach ($glyph->foregroundLines as $line) {
            $walk = LinePoints::walk(...$line);
            for ($i = 0, $n = count($walk); $i < $n; $i += 2) {
                $x = $walk[$i] + $item->x;
                $y = $walk[$i + 1] + $item->y - $glyph->marginTop;
                if ($x < 0 || $y < 0 || $x >= $parent->width || $y >= $parent->height ||
                    !self::isGroupInk($items, $index, $glyph->expandCount, $x, $y)) {
                    return false;
                }
            }
        }
        foreach ($glyph->backgroundLines as $line) {
            $walk = LinePoints::walk(...$line);
            for ($i = 0, $n = count($walk); $i < $n; $i += 2) {
                $x = $walk[$i] + $item->x;
                $y = $walk[$i + 1] + $item->y - $glyph->marginTop;
                if ($x < 0 || $y < 0 || $x >= $parent->width || $y >= $parent->height) {
                    continue;
                }
                if ($parent->get($x, $y) !== 0) {
                    return false;
                }
            }
        }

        return true;
    }


    /**
     * Returns true when the pixel is ink of one of the parts that the expanded glyph would claim. Ink of a
     * neighbouring glyph does not count.
     *
     * @param list<SplitItem> $items
     */
    private static function isGroupInk(array $items, int $index, int $count, int $x, int $y): bool
    {
        $end = min(count($items), $index + $count);
        for ($i = $index; $i < $end; $i++) {
            $bitmap = $items[$i]->bitmap;
            if ($bitmap === null) {
                continue;
            }
            $localX = $x - $items[$i]->x;
            $localY = $y - $items[$i]->y;
            if ($localX >= 0 && $localY >= 0 && $localX < $bitmap->width && $localY < $bitmap->height &&
                $bitmap->pixels[$localY * $bitmap->width + $localX] !== 0) {
                return true;
            }
        }

        return false;
    }


    /**
     * @param list<SplitItem> $items
     * @return array{int, int}
     */
    private static function totalSize(int $index, array $items, int $count): array
    {
        if ($index + $count > count($items)) {
            return [-100, -100];
        }
        $minX = PHP_INT_MAX;
        $maxX = PHP_INT_MIN;
        $minY = PHP_INT_MAX;
        $maxY = PHP_INT_MIN;
        for ($i = $index; $i < $index + $count; $i++) {
            $bitmap = $items[$i]->bitmap;
            if ($bitmap === null) {
                return [-100, -100];
            }
            $minY = min($minY, $items[$i]->y);
            $maxY = max($maxY, $items[$i]->y + $bitmap->height);
            $minX = min($minX, $items[$i]->x);
            $maxX = max($maxX, $items[$i]->x + $bitmap->width);
        }

        return [$maxX - $minX, $maxY - $minY];
    }
}
