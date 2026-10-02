<?php

namespace GlyphOcr\Internal;

/**
 * CaseFixer picks upper or lower case for Latin letters whose two cases have the same shape, such as o and O,
 * from the glyph height compared with the heights seen so far. Port of NOcrCaseFixer of Subtitle Edit.
 *
 * @internal
 */
final class CaseFixer
{
    private const UPPER_LIKE_LOWER = ['V', 'W', 'U', 'S', 'Z', 'O', 'X', 'Ø', 'C'];
    private const LOWER_LIKE_UPPER = ['v', 'w', 'u', 's', 'z', 'o', 'x', 'ø', 'c'];
    private const UPPER_WITH_ACCENT = ['Č', 'Š', 'Ž', 'Ś', 'Ż', 'Ö', 'Ü', 'Ú', 'Ï', 'Í', 'Ç', 'Ì', 'Ò', 'Ù', 'Ó'];
    private const LOWER_WITH_ACCENT = ['č', 'š', 'ž', 'ś', 'ż', 'ö', 'ü', 'ú', 'ï', 'í', 'ç', 'ì', 'ò', 'ù', 'ó'];

    private int $lowerTotal = 0;
    private int $lowerCount = 0;
    private int $upperTotal = 0;
    private int $upperCount = 0;


    public function fix(string $text, int $height): string
    {
        if (in_array($text, ['e', 'a', 'd', 't'], true)) {
            $this->lowerCount++;
            $this->lowerTotal += $height;
            if ($this->upperCount < 3) {
                $this->upperCount++;
                $this->upperTotal += $height + 10;
            }
        }
        if (in_array($text, ['E', 'H', 'R', 'D', 'T', 'M'], true)) {
            $this->upperCount++;
            $this->upperTotal += $height;
            if ($this->lowerCount < 3 && $height > 20) {
                $this->lowerCount++;
                $this->lowerTotal += $height - 10;
            }
        }

        if ($this->lowerCount <= 2 || $this->upperCount <= 2) {
            return $text;
        }

        if (in_array($text, self::UPPER_LIKE_LOWER, true)) {
            $lower = intdiv($this->lowerTotal, $this->lowerCount);
            $upper = intdiv($this->upperTotal, $this->upperCount);

            return abs($lower - $height) < abs($upper - $height) ? self::lower($text) : $text;
        }
        if (in_array($text, self::LOWER_LIKE_UPPER, true)) {
            $lower = intdiv($this->lowerTotal, $this->lowerCount);
            $upper = intdiv($this->upperTotal, $this->upperCount);

            return abs($lower - $height) > abs($upper - $height) ? self::upper($text) : $text;
        }
        if (in_array($text, self::UPPER_WITH_ACCENT, true)) {
            return $height < $this->upperTotal / $this->upperCount + 3 ? self::lower($text) : $text;
        }
        if (in_array($text, self::LOWER_WITH_ACCENT, true)) {
            return $height > $this->upperTotal / $this->upperCount + 4 ? self::upper($text) : $text;
        }

        return $text;
    }


    private static function lower(string $text): string
    {
        $index = array_search($text, [...self::UPPER_LIKE_LOWER, ...self::UPPER_WITH_ACCENT], true);

        return [...self::LOWER_LIKE_UPPER, ...self::LOWER_WITH_ACCENT][$index];
    }


    private static function upper(string $text): string
    {
        $index = array_search($text, [...self::LOWER_LIKE_UPPER, ...self::LOWER_WITH_ACCENT], true);

        return [...self::UPPER_LIKE_LOWER, ...self::UPPER_WITH_ACCENT][$index];
    }
}
