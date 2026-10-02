<?php

namespace GlyphOcr\Internal;

/**
 * Splitter cuts a two-colour bitmap into lines and glyphs. Port of NikseBitmapImageSplitter2 from Subtitle Edit.
 *
 * @internal
 */
final class Splitter
{
    public const LINE_BREAK = "\n";
    public const SPACE = ' ';


    /**
     * Returns the glyphs of all lines in reading order, with a space item between words and a line break
     * item between lines.
     *
     * @return list<SplitItem>
     */
    public static function splitToLetters(Bitmap $bmp, int $spacePixels, bool $rightToLeft, int $minLineHeight,
                                          float $averageLineHeight): array
    {
        $splitOld = self::splitToLines($bmp, $minLineHeight, $averageLineHeight);

        $splitThree = self::splitToLinesByEmptyRows($bmp, $minLineHeight, 3);
        $splitFour = self::splitToLinesByEmptyRows($bmp, $minLineHeight, 4);
        $splitBlank = count($splitThree) === count($splitFour) ? $splitFour : $splitThree;
        $lines = count($splitOld) > count($splitBlank) ? $splitOld : $splitBlank;

        // Subtitle Edit gates this on the ink height, not the bitmap height: VobSub images carry empty rows
        // under the text, and one line plus that margin would otherwise go through the up and down splitter.
        if (count($lines) === 1 && $lines[0]->bitmap !== null &&
            $lines[0]->bitmap->inkHeight() > $minLineHeight * 2.2) {
            $lines = self::splitToLinesNew($lines[0], $minLineHeight);
        }

        $list = [];
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $list[] = SplitItem::special(self::LINE_BREAK);
            }
            $items = self::splitHorizontal($line, $spacePixels);
            if ($rightToLeft) {
                $items = array_reverse($items);
            }
            foreach ($items as $item) {
                $item->top = $index > 0 ? $item->y - $line->y : $item->y;
                $list[] = $item;
            }
        }

        return $list;
    }


    /**
     * @return list<SplitItem>
     */
    private static function splitToLinesEmptyOrBlack(Bitmap $bmp): array
    {
        // Two-colour pixels are either empty or ink, so "transparent or black" is "empty" here.
        $startY = 0;
        $size = 0;
        $parts = [];
        for ($y = 0; $y < $bmp->height; $y++) {
            if ($bmp->isRowEmpty($y)) {
                if ($size > 2 && $size <= 15) {
                    $size++;
                } else {
                    if ($size > 8) {
                        $parts[] = new SplitItem(0, $startY, $bmp->copyRectangle(0, $startY, $bmp->width, $size + 1));
                    }
                    $size = 0;
                    $startY = $y;
                }
            } else {
                $size++;
            }
        }
        if ($size > 2) {
            $parts[] = $size === $bmp->height
                ? new SplitItem(0, $startY, $bmp)
                : new SplitItem(0, $startY, $bmp->copyRectangle(0, $startY, $bmp->width, $size + 1));
        }

        return $parts;
    }


    /**
     * @return list<SplitItem>
     */
    private static function splitToLines(Bitmap $bmp, int $minLineHeight, float $averageLineHeight): array
    {
        $startY = 0;
        $size = 0;
        $parts = [];
        for ($y = 0; $y < $bmp->height; $y++) {
            if ($bmp->isRowEmpty($y)) {
                $appendix = $y >= $bmp->height - $minLineHeight;
                if (!$appendix && $y < $bmp->height - 10 && $size > $minLineHeight && $minLineHeight > 15) {
                    if (!$bmp->isRowEmpty($y + 1) || !$bmp->isRowEmpty($y + 2) || !$bmp->isRowEmpty($y + 3) ||
                        !$bmp->isRowEmpty($y + 4) || !$bmp->isRowEmpty($y + 5)) {
                        if ($bmp->isRowEmpty($y + 6) && $bmp->isRowEmpty($y + 7) && $bmp->isRowEmpty($y + 8) &&
                            $bmp->isRowEmpty($y + 9)) {
                            $appendix = true;
                        }
                    }
                }

                if ($appendix || ($size > 1 && $size <= $minLineHeight)) {
                    $size++;
                } else {
                    if ($size > 1) {
                        $parts[] = new SplitItem(0, $startY, $bmp->copyRectangle(0, $startY, $bmp->width, $size + 1));
                    }
                    $size = 0;
                    $startY = $y;
                }
            } else {
                $size++;
            }
        }
        if ($size > 1) {
            if ($size === $bmp->height) {
                if ($size > 100) {
                    return self::splitToLinesEmptyOrBlack($bmp);
                }
                $parts[] = new SplitItem(0, $startY, $bmp);
            } else {
                $parts[] = new SplitItem(0, $startY, $bmp->copyRectangle(0, $startY, $bmp->width, $size + 1));
            }
        }
        if (count($parts) === 1 && $averageLineHeight > 5 && $bmp->height > $averageLineHeight * 3) {
            return self::splitToLinesAggressive($bmp, $minLineHeight, $averageLineHeight);
        }

        return $parts;
    }


    /**
     * @return list<SplitItem>
     */
    private static function splitToLinesAggressive(Bitmap $bmp, int $minLineHeight, float $averageLineHeight): array
    {
        $startY = 0;
        $size = 0;
        $parts = [];
        for ($y = 0; $y < $bmp->height; $y++) {
            $empty = $bmp->isRowEmpty($y);

            if ($size > 5 && $size >= $minLineHeight && $size > $averageLineHeight && !$empty && $bmp->width > 50 &&
                $y < $bmp->height - 5) {
                $leftX = 0;
                while ($leftX < $bmp->width && $bmp->get($leftX, $y) === 0) {
                    $leftX++;
                }
                $rightX = $bmp->width;
                while ($rightX > 0 && $bmp->get($rightX, $y - 1) === 0) {
                    $rightX--;
                }
                if ($leftX >= $rightX) {
                    $empty = true;
                }

                $leftX = 0;
                while ($leftX < $bmp->width && $bmp->get($leftX, $y - 1) === 0) {
                    $leftX++;
                }
                $rightX = $bmp->width;
                while ($rightX > 0 && $bmp->get($rightX, $y) === 0) {
                    $rightX--;
                }
                if ($leftX >= $rightX) {
                    $empty = true;
                }
            }

            if ($empty) {
                if ($size > 2 && $size <= $minLineHeight) {
                    $size++;
                } else {
                    if ($size > 2) {
                        $parts[] = new SplitItem(0, $startY, $bmp->copyRectangle(0, $startY, $bmp->width, $size + 1));
                    }
                    $size = 0;
                    $startY = $y;
                }
            } else {
                $size++;
            }
        }
        if ($size > 2) {
            if ($size === $bmp->height) {
                if ($size > 100) {
                    return self::splitToLinesEmptyOrBlack($bmp);
                }
                $parts[] = new SplitItem(0, $startY, $bmp);
            } else {
                $parts[] = new SplitItem(0, $startY, $bmp->copyRectangle(0, $startY, $bmp->width, $size + 1));
            }
        }

        return $parts;
    }


    /**
     * @return list<SplitItem>
     */
    private static function splitToLinesByEmptyRows(Bitmap $bmp, int $minLineHeight, int $minEmptyRows): array
    {
        $parts = [];
        $startY = 0;
        $lastEmptyY = -1;
        $emptyInSequence = 0;
        for ($y = $minLineHeight; $y < $bmp->height - $minLineHeight; $y++) {
            $empty = $bmp->isRowEmpty($y);
            if ($startY === $y && $empty) {
                $startY++;
                continue;
            }

            if ($empty) {
                if ($lastEmptyY === $y - 1) {
                    if ($emptyInSequence === 0) {
                        $emptyInSequence++;
                    }
                    $emptyInSequence++;
                }

                if ($emptyInSequence >= $minEmptyRows && $lastEmptyY - $startY > $minLineHeight) {
                    $part = $bmp->copyRectangle(0, $startY, $bmp->width, $lastEmptyY - $startY - 1);
                    if (!$part->isEmpty() && $part->inkHeight() + $emptyInSequence * 0.4 >= $minLineHeight) {
                        $croppedTop = $part->cropTopEmptyRows();
                        $parts[] = new SplitItem(0, $startY + $croppedTop, $part);
                        $startY = $lastEmptyY + 1;
                    }
                }
                $lastEmptyY = $y;
            } else {
                $emptyInSequence = 0;
                $lastEmptyY = -1;
            }
        }

        if ($bmp->height - $startY > 1) {
            $part = $bmp->copyRectangle(0, $startY, $bmp->width, $bmp->height - $startY);
            if (!$part->isEmpty()) {
                $croppedTop = $part->cropTopEmptyRows();
                $parts[] = new SplitItem(0, $startY + $croppedTop, $part);
            }
        }

        return $parts;
    }


    /**
     * Splits lines whose glyphs reach into each other's rows, by walking a path that may step up and down.
     *
     * @return list<SplitItem>
     */
    private static function splitToLinesNew(SplitItem $item, int $minLineHeight): array
    {
        $bmp = clone $item->bitmap;
        $parts = [];
        $splitLines = [];
        $startY = 0;
        $width = $bmp->width;
        $height = $bmp->height;
        $maxUp = min(10, intdiv($minLineHeight, 2));

        // "started" stays set for all later rows once a path is blocked, as in Subtitle Edit.
        $started = false;

        for ($y = $minLineHeight; $y < $height - $minLineHeight; $y++) {
            if ($startY === $y && $bmp->isRowEmpty($y)) {
                $startY++;
                continue;
            }

            $points = [];
            $yChange = 0;
            $completed = false;
            $backJump = 0;
            $x = 0;

            while ($x < $width) {
                $currentY = $y + $yChange;
                if ($currentY < 0 || $currentY >= $height) {
                    $started = true;
                    break;
                }

                $a1 = $bmp->get($x, $currentY);
                $a2 = $currentY + 1 < $height ? $bmp->get($x, $currentY + 1) : 0;

                if ($a1 !== 0 || $a2 !== 0) {
                    if ($x > 1 && $yChange < 8 && $currentY + 3 < $height && self::canMoveDown($bmp, $x, $currentY, 2)) {
                        $yChange += 2;
                    } elseif ($x > 1 && $yChange < 8 && $currentY + 4 < $height &&
                              self::canMoveDown($bmp, $x, $currentY, 3)) {
                        $yChange += 3;
                    } elseif ($x > 1 && $yChange < 7 && $currentY + 5 < $height &&
                              self::canMoveDown($bmp, $x, $currentY, 4)) {
                        $yChange += 4;
                    } elseif ($x > 1 && $yChange > -7 && $currentY - 3 >= 0 && self::canMoveUp($bmp, $x, $currentY, 2)) {
                        $yChange -= 2;
                    } elseif ($x > 1 && $yChange > -7 && $currentY - 4 >= 0 && self::canMoveUp($bmp, $x, $currentY, 3)) {
                        $yChange -= 3;
                    } elseif ($x > 1 && $yChange > -7 && $currentY - 5 >= 0 && self::canMoveUp($bmp, $x, $currentY, 4)) {
                        $yChange -= 4;
                    } elseif ($x > 10 && $backJump < 3 && $yChange > -7) {
                        $done = false;
                        for ($i = 1; $i < $maxUp && !$done; $i++) {
                            for ($k = 1; $k < 9; $k++) {
                                if (self::canGoUpAndRight($bmp, $i, 12, $x - $k, $currentY, $minLineHeight)) {
                                    $backJump++;
                                    $x -= $k;
                                    $points = array_values(array_filter($points, fn(array $p): bool => $p[0] <= $x));
                                    $yChange -= $i + 1;
                                    $done = true;
                                    break;
                                }
                            }
                        }
                        if (!$done) {
                            $started = true;
                            break;
                        }
                    } else {
                        $started = true;
                        break;
                    }
                }

                if ($started) {
                    $points[] = [$x, $currentY];
                }

                $completed = $x === $width - 1;
                $x++;
            }

            if ($completed) {
                $splitLines[$y] = $points;
            }
        }

        foreach ($splitLines as $key => $linePoints) {
            if ($key - $startY > $minLineHeight && count($linePoints) > 0) {
                $minY = $linePoints[0][1];
                $maxY = $linePoints[0][1];
                foreach ($linePoints as [, $py]) {
                    $maxY = max($maxY, $py);
                    $minY = min($minY, $py);
                }

                $part = $bmp->copyRectangle(0, $startY, $width, $maxY - $startY);
                foreach ($linePoints as [$px, $py]) {
                    for ($yy = $py - 1; $yy < $startY + $part->height; $yy++) {
                        $part->set($px, $yy - $startY, 0);
                    }
                }

                if (!$part->isEmpty() && $part->inkHeight() >= $minLineHeight) {
                    foreach ($linePoints as [$px, $py]) {
                        for ($yy = $py; $yy >= $minY; $yy--) {
                            $bmp->set($px, $yy, 0);
                        }
                    }
                    $croppedTop = $part->cropTopEmptyRows();
                    $parts[] = new SplitItem($item->x, $startY + $croppedTop + $item->y, $part);
                    $startY = $key + 1;
                }
            }
        }

        if ($height - $startY > 1 && count($parts) > 0) {
            $part = $bmp->copyRectangle(0, $startY, $width, $height - $startY);
            if (!$part->isEmpty()) {
                $croppedTop = $part->cropTopEmptyRows();
                $parts[] = new SplitItem($item->x, $startY + $croppedTop + $item->y, $part);
            }
        }

        return count($parts) <= 1 ? [$item] : $parts;
    }


    private static function canMoveDown(Bitmap $bmp, int $x, int $y, int $steps): bool
    {
        for ($i = 0; $i <= $steps + 1; $i++) {
            if ($bmp->get($x - 1, $y + $i) !== 0) {
                return false;
            }
        }
        for ($i = $steps; $i <= $steps + 1; $i++) {
            if ($bmp->get($x, $y + $i) !== 0) {
                return false;
            }
        }

        return true;
    }


    private static function canMoveUp(Bitmap $bmp, int $x, int $y, int $steps): bool
    {
        for ($i = 0; $i <= $steps + 1; $i++) {
            if ($bmp->get($x - 1, $y - $i) !== 0) {
                return false;
            }
        }
        for ($i = $steps; $i <= $steps + 1; $i++) {
            if ($bmp->get($x, $y - $i) !== 0) {
                return false;
            }
        }

        return true;
    }


    private static function canGoUpAndRight(Bitmap $bmp, int $up, int $right, int $x, int $y, int $minLineHeight): bool
    {
        if ($y - $up < 0 || $x + $right >= $bmp->width || $y + $minLineHeight > $bmp->height) {
            return false;
        }
        for ($myY = $y; $myY > $y - $up && $myY > 1; $myY--) {
            if ($bmp->get($x, $myY) !== 0) {
                return false;
            }
        }
        for ($myX = $x; $myX < $x + $right && $myX < $bmp->width; $myX++) {
            if ($bmp->get($myX, $y - $up) !== 0) {
                return false;
            }
        }

        return true;
    }


    /**
     * Cuts one line into glyphs along vertical paths of empty pixels. A path may step left or right
     * around a pixel, so italic glyphs that share columns still come apart.
     *
     * @return list<SplitItem>
     */
    private static function splitHorizontal(SplitItem $line, int $spaceMinPixels): array
    {
        $bmp = clone $line->bitmap;
        $bmp->addEmptyColumnRight();
        $parts = [];
        $height = $bmp->height;
        $startX = 0;
        $width = 0;
        $spacePixels = 0;
        $subtractSpacePixels = 0;

        for ($x = 0; $x < $bmp->width; $x++) {
            $points = self::emptyVerticalPath($bmp, $x, $right, $clean);

            if ($points !== null && $clean) {
                $spacePixels++;
            }

            if ($right && $points !== null) {
                $add = self::maxX($points, $x) - $x;
                $width += $add;
                $subtractSpacePixels = $add;
            }

            $newStartX = $points !== null ? self::minX($points, $x) : 0;
            if ($points === null) {
                $width++;
            } elseif ($width > 0 && $newStartX > $startX + 1) {
                $width = self::maxX($points, $x) - $startX - 1;
                $startX++;
                $glyph = $bmp->copyRectangle($startX, 0, $width, $height);
                if ($glyph->width > 0) {
                    foreach ($points as $index => [$px, $py]) {
                        $xStart = $px - $startX;
                        $xEnd = $px + $index - $startX;
                        if ($xEnd < 0 || $xStart >= $glyph->width) {
                            continue;
                        }
                        $glyph->clearRowPart($xStart, $xEnd, $py);
                    }
                }

                [$glyph, $addY] = self::cropTopAndBottom($glyph);

                if ($spacePixels >= $spaceMinPixels && count($parts) > 0) {
                    $parts[] = SplitItem::special(self::SPACE, $addY + $line->y, $spacePixels);
                }
                if ($glyph->width > 0 && $glyph->height > 0) {
                    $parts[] = new SplitItem($startX + $line->x, $addY + $line->y, $glyph);
                }

                foreach ($points as [$px, $py]) {
                    $bmp->clearRowPart(0, $px, $py);
                }
                $width = 1;
                $startX = self::minX($points, $x);
                $spacePixels = -$subtractSpacePixels;
                $subtractSpacePixels = 0;
            } elseif ($clean) {
                $width = 1;
                $startX = $newStartX;
            }
        }

        return $parts;
    }


    /**
     * @param list<array{int, int}> $points
     */
    private static function minX(array $points, int $x): int
    {
        foreach ($points as [$px]) {
            if ($px < $x) {
                $x = $px;
            }
        }

        return $x;
    }


    /**
     * @param list<array{int, int}> $points
     */
    private static function maxX(array $points, int $x): int
    {
        foreach ($points as [$px]) {
            if ($px > $x) {
                $x = $px;
            }
        }

        return $x;
    }


    /**
     * Returns the path of empty pixels from the top to the bottom that starts at column $x, or null when ink blocks it.
     *
     * @param-out bool $right
     * @param-out bool $clean
     * @return list<array{int, int}>|null
     */
    private static function emptyVerticalPath(Bitmap $bmp, int $x, ?bool &$right, ?bool &$clean): ?array
    {
        $right = false;
        $left = false;
        $leftCount = 0;
        $rightCount = 0;
        $clean = true;
        $points = null;
        $y = 0;
        $height = $bmp->height;
        $width = $bmp->width;
        $maxSlide = intdiv($height, 4);

        while ($y < $height) {
            if ($bmp->get($x, $y) !== 0) {
                $clean = false;
                if ($x === 0) {
                    return null;
                }

                if ($x < $width - 1 && $y < $height - 1 && $bmp->get($x + 1, $y) === 0 && $bmp->get($x + 1, $y + 1) === 0) {
                    if ($bmp->get($x - 1, $y) !== 0) {
                        $x++;
                        $right = true;
                    } else {
                        $x--;
                        $left = true;
                    }
                } elseif ($x < $width - 1 && $y === $height - 1 && $bmp->get($x + 1, $y) === 0 &&
                          $bmp->get($x + 1, $y - 1) === 0) {
                    if ($bmp->get($x - 1, $y) !== 0) {
                        $x++;
                        $right = true;
                    } else {
                        return null;
                    }
                } elseif ($bmp->get($x - 1, $y) === 0) {
                    $x--;
                    $left = true;
                } elseif ($y > 5 && $bmp->get($x - 1, $y - 1) === 0) {
                    $x--;
                    $y--;
                    $left = true;
                    while ($points !== null && count($points) > 0 && $points[count($points) - 1][1] > $y) {
                        array_pop($points);
                    }
                } elseif ($y > 5 && $bmp->get($x - 1, $y - 2) === 0) {
                    $x--;
                    $y -= 2;
                    $left = true;
                    while ($points !== null && count($points) > 0 && $points[count($points) - 1][1] > $y) {
                        array_pop($points);
                    }
                } else {
                    return null;
                }

                if ($left) {
                    $leftCount++;
                }
                if ($right) {
                    $rightCount++;
                }
                if ($leftCount > $maxSlide || $rightCount > $maxSlide) {
                    return null;
                }
            } else {
                $points ??= [];
                $points[] = [$x, $y];
                $y++;
            }
        }

        return $points ?? [];
    }


    /**
     * Removes empty rows at the top and the bottom. Returns the cropped bitmap and the rows removed at the top.
     *
     * @return array{Bitmap, int}
     */
    private static function cropTopAndBottom(Bitmap $bmp): array
    {
        $startTop = 0;
        $maxTop = $bmp->height - 2;
        for ($y = 0; $y < $maxTop; $y++) {
            if (!$bmp->isRowEmpty($y)) {
                break;
            }
            $startTop++;
        }

        $h = $bmp->height;
        for ($y = $bmp->height - 1; $y > 3; $y--) {
            $h = $y;
            if (!$bmp->isRowEmpty($y)) {
                break;
            }
        }
        if ($h - $startTop + 1 <= 0) {
            return [Bitmap::blank(0, 0), $startTop];
        }

        return [$bmp->copyRectangle(0, $startTop, $bmp->width, $h - $startTop + 1), $startTop];
    }
}
