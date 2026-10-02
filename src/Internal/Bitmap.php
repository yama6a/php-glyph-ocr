<?php

namespace GlyphOcr\Internal;

/**
 * Bitmap is a two-colour bitmap: 1 is ink, 0 is background. Port of NikseBitmap2 from Subtitle Edit,
 * reduced to the operations that the splitter and the matcher run after the two-colour step.
 *
 * Pixels are a flat list indexed by y * width + x. An out-of-row x reads into the next or previous
 * row, as the original byte buffer does, because the splitter depends on it.
 *
 * @internal
 */
final class Bitmap
{
    /**
     * @param list<int> $pixels
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $pixels,
    ) {
    }


    public static function blank(int $width, int $height): self
    {
        $count = max(0, $width) * max(0, $height);

        return new self(max(0, $width), max(0, $height), $count > 0 ? array_fill(0, $count, 0) : []);
    }


    /**
     * Applies the two-colour step of Subtitle Edit: a pixel is ink when its alpha is at least 1 and the
     * sum of its premultiplied red, green and blue is at least $minRgb.
     */
    public static function fromRgba(string $rgba, int $width, int $height, int $minRgb): self
    {
        $pixels = [];
        $rowBytes = $width * 4;
        for ($y = 0; $y < $height; $y++) {
            $row = substr($rgba, $y * $rowBytes, $rowBytes);
            if (trim($row, "\0") === '') {
                array_push($pixels, ...array_fill(0, $width, 0));
                continue;
            }
            $bytes = unpack('C*', $row);
            for ($b = 1; $b < $rowBytes; $b += 4) {
                $alpha = $bytes[$b + 3];
                if ($alpha === 0) {
                    $pixels[] = 0;
                    continue;
                }
                if ($alpha === 255) {
                    $sum = $bytes[$b] + $bytes[$b + 1] + $bytes[$b + 2];
                } else {
                    $sum = self::premultiply($bytes[$b], $alpha) + self::premultiply($bytes[$b + 1], $alpha) +
                           self::premultiply($bytes[$b + 2], $alpha);
                }
                $pixels[] = $sum < $minRgb ? 0 : 1;
            }
        }

        return new self($width, $height, $pixels);
    }


    /**
     * Rounds like Skia, which decodes the images that Subtitle Edit reads to premultiplied alpha.
     */
    private static function premultiply(int $value, int $alpha): int
    {
        $product = $value * $alpha + 128;

        return ($product + ($product >> 8)) >> 8;
    }


    public function get(int $x, int $y): int
    {
        return $this->pixels[$y * $this->width + $x] ?? 0;
    }


    public function set(int $x, int $y, int $value): void
    {
        $index = $y * $this->width + $x;
        if ($index >= 0 && $index < $this->width * $this->height) {
            $this->pixels[$index] = $value;
        }
    }


    public function isRowEmpty(int $y): bool
    {
        if ($y < 0 || $y >= $this->height) {
            return true;
        }
        $start = $y * $this->width;
        $end = $start + $this->width;
        $pixels = $this->pixels;
        for ($i = $start; $i < $end; $i++) {
            if ($pixels[$i] !== 0) {
                return false;
            }
        }

        return true;
    }


    public function isEmpty(): bool
    {
        return !in_array(1, $this->pixels, true);
    }


    public function copyRectangle(int $left, int $top, int $width, int $height): self
    {
        if ($top + $height > $this->height) {
            $height = $this->height - $top;
        }
        if ($left + $width > $this->width) {
            $width = $this->width - $left;
        }
        if ($width <= 0 || $height <= 0) {
            return self::blank(0, 0);
        }

        $pixels = [];
        for ($y = $top; $y < $top + $height; $y++) {
            array_push($pixels, ...array_slice($this->pixels, $y * $this->width + $left, $width));
        }

        return new self($width, $height, $pixels);
    }


    /**
     * Removes the empty rows at the top and returns how many it removed.
     */
    public function cropTopEmptyRows(): int
    {
        $newTop = 0;
        for ($y = 0; $y < $this->height; $y++) {
            if (!$this->isRowEmpty($y)) {
                $newTop = $y;
                break;
            }
        }
        if ($newTop === 0) {
            return 0;
        }

        $this->pixels = array_slice($this->pixels, $newTop * $this->width);
        $this->height -= $newTop;

        return $newTop;
    }


    /**
     * Returns the height between the first and the last row with ink.
     */
    public function inkHeight(): int
    {
        $startY = 0;
        $emptyBottom = 0;
        for ($y = 0; $y < $this->height; $y++) {
            $empty = $this->isRowEmpty($y);
            if ($startY === $y && $empty) {
                $startY++;
                continue;
            }
            $emptyBottom = $empty ? $emptyBottom + 1 : 0;
        }

        return $this->height - $startY - $emptyBottom;
    }


    public function clearRowPart(int $xStart, int $xEnd, int $y): void
    {
        $xEnd = min($xEnd, $this->width - 1);
        $xStart = max($xStart, 0);
        $base = $y * $this->width;
        $count = $this->width * $this->height;
        for ($x = $xStart; $x <= $xEnd; $x++) {
            $index = $base + $x;
            if ($index >= 0 && $index < $count) {
                $this->pixels[$index] = 0;
            }
        }
    }


    public function addEmptyColumnRight(): void
    {
        $pixels = [];
        for ($y = 0; $y < $this->height; $y++) {
            array_push($pixels, ...array_slice($this->pixels, $y * $this->width, $this->width));
            $pixels[] = 0;
        }
        $this->pixels = $pixels;
        $this->width++;
    }


    /**
     * Returns a copy with each row y shifted right by round(y * $factor) pixels, then cropped to its ink.
     */
    public function unItalic(float $factor): self
    {
        $newWidth = $this->width + (int)($this->height * $factor) + 4;
        $result = self::blank($newWidth, $this->height);
        for ($y = 0; $y < $this->height; $y++) {
            $shift = Rounding::awayFromZero($y * $factor);
            for ($x = 0; $x < $this->width; $x++) {
                $result->pixels[$y * $newWidth + $x + $shift] = $this->pixels[$y * $this->width + $x];
            }
        }

        return $result->cropEmptySides();
    }


    public function cropEmptySides(): self
    {
        $left = $this->width;
        $right = -1;
        for ($y = 0; $y < $this->height; $y++) {
            $base = $y * $this->width;
            for ($x = 0; $x < $this->width; $x++) {
                if ($this->pixels[$base + $x] !== 0) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                }
            }
        }
        if ($right < 0 || ($left === 0 && $right === $this->width - 1)) {
            return clone $this;
        }

        return $this->copyRectangle($left, 0, $right - $left + 1, $this->height);
    }
}
