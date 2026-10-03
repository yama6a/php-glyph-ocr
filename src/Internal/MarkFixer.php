<?php

namespace GlyphOcr\Internal;

use GlyphOcr\RecognizedChar;

/**
 * MarkFixer corrects marks that have the same shape and differ only in their place on the line: a comma hangs
 * below the baseline, an apostrophe sits at the top, and a bar from the top to the baseline is I or l.
 *
 * @internal
 */
final class MarkFixer
{
    private const BASELINE_LETTERS = ['B', 'D', 'E', 'F', 'H', 'K', 'L', 'M', 'N', 'P', 'R', 'T', 'Z', 'a', 'b',
                                      'd', 'h', 'k', 'm', 'n', 'r', 'x', 'z'];


    /**
     * @param list<RecognizedChar> $chars the characters of one line
     * @return list<RecognizedChar>
     */
    public static function fixLine(array $chars): array
    {
        $bottoms = [];
        $tops = [];
        foreach ($chars as $char) {
            if ($char->sample !== null && $char->sample->parts === 1 &&
                in_array($char->text, self::BASELINE_LETTERS, true)) {
                $bottoms[] = $char->sample->marginTop + $char->sample->height;
                $tops[] = $char->sample->marginTop;
            }
        }
        if (count($bottoms) === 0) {
            return $chars;
        }
        sort($bottoms);
        $baseline = $bottoms[intdiv(count($bottoms), 2)];
        $letterHeight = $baseline - min($tops);

        foreach ($chars as $index => $char) {
            if ($char->sample === null || $char->sample->parts !== 1 || !in_array($char->text, ["'", ','], true)) {
                continue;
            }
            $top = $char->sample->marginTop;
            $bottom = $top + $char->sample->height;
            $text = match (true) {
                abs($bottom - $baseline) <= 1 && $char->sample->height > $letterHeight * 0.6 => 'l',
                $bottom > $baseline + 1                                                     => ',',
                $bottom < $baseline - $letterHeight * 0.4                                   => "'",
                default                                                                     => $char->text,
            };
            if ($text !== $char->text) {
                $chars[$index] = new RecognizedChar($text, $char->confidence, $char->italic, false, $char->glyph,
                                                    $char->sample);
            }
        }

        return $chars;
    }
}
