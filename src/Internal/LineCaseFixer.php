<?php

namespace GlyphOcr\Internal;

use GlyphOcr\RecognizedChar;

/**
 * LineCaseFixer picks upper or lower case for letters whose two cases have the same shape, such as o and O,
 * from the capitals and x-height letters on the same line. CaseFixer decides from the heights of all images
 * seen so far, which fails when the font size changes. This fixer overrides it when the line has a reference.
 *
 * @internal
 */
final class LineCaseFixer
{
    private const UPPER = ['C', 'O', 'S', 'U', 'V', 'W', 'X', 'Z'];
    private const LOWER = ['c', 'o', 's', 'u', 'v', 'w', 'x', 'z'];
    private const CAPITALS = ['B', 'D', 'E', 'F', 'H', 'K', 'L', 'M', 'N', 'P', 'R', 'T'];
    private const X_HEIGHT = ['a', 'e', 'm', 'n', 'r'];

    /**
     * Capitals are 1.25 to 1.5 times as tall as x-height letters in common fonts, when round letters such as o
     * count with their overshoot. This ratio splits the two.
     */
    private const SPLIT_RATIO = 1.15;


    /**
     * @param list<RecognizedChar> $chars the characters of one line
     * @return list<RecognizedChar>
     */
    public static function fixLine(array $chars): array
    {
        $capitals = [];
        $xHeights = [];
        foreach ($chars as $char) {
            if ($char->sample === null || $char->sample->parts > 1) {
                continue;
            }
            if (in_array($char->text, self::CAPITALS, true)) {
                $capitals[] = $char->sample->height;
            } elseif (in_array($char->text, self::X_HEIGHT, true)) {
                $xHeights[] = $char->sample->height;
            }
        }
        if (count($capitals) === 0 && count($xHeights) === 0) {
            return $chars;
        }
        // A misread glyph, such as n read as D, is shorter than a capital, so the tallest capital wins.
        $capital = count($capitals) > 0 ? max($capitals) : null;
        $xHeight = count($xHeights) > 0 ? min($xHeights) : null;
        if ($capital !== null && $xHeight !== null && $capital < $xHeight * self::SPLIT_RATIO) {
            return $chars;
        }

        foreach ($chars as $index => $char) {
            $upperIndex = array_search($char->text, self::UPPER, true);
            $lowerIndex = array_search($char->text, self::LOWER, true);
            if (($upperIndex === false && $lowerIndex === false) || $char->sample === null) {
                continue;
            }
            $height = $char->sample->height;
            $isUpper = match (true) {
                $capital !== null && $xHeight !== null => abs($height - $capital) < abs($height - $xHeight),
                $capital !== null                      => $height * self::SPLIT_RATIO > $capital,
                default                                => $height > $xHeight * self::SPLIT_RATIO,
            };
            $text = $isUpper ? self::UPPER[$upperIndex === false ? $lowerIndex : $upperIndex]
                : self::LOWER[$lowerIndex === false ? $upperIndex : $lowerIndex];
            if ($text !== $char->text) {
                $chars[$index] = new RecognizedChar($text, $char->confidence, $char->italic, false, $char->glyph,
                                                    $char->sample);
            }
        }

        return $chars;
    }
}
