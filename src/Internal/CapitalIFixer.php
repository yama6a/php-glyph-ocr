<?php

namespace GlyphOcr\Internal;

use GlyphOcr\RecognizedChar;

/**
 * CapitalIFixer picks capital I or lower case l for a glyph that the database read as either one. Most
 * sans-serif fonts draw both as the same bar, and l is about 5% taller than I.
 *
 * Glyphs on one line share the baseline, so equal tops mean equal heights. The fixer tries in this order:
 * - the top of the bar against the tops of flat-topped capitals such as H and of ascenders such as d on the line
 * - the tops of the bars on the line against each other, when they differ by a few pixels
 * - the bar height against the mean heights of capitals and ascenders seen so far
 * - the neighbouring letters
 *
 * @internal
 */
final class CapitalIFixer
{
    private const BARS = ['I', 'l'];
    private const CAPITALS = ['B', 'D', 'E', 'F', 'H', 'K', 'L', 'M', 'N', 'P', 'R', 'T', 'Z'];
    private const ASCENDERS = ['b', 'd', 'f', 'h', 'k'];

    /** Below this difference in pixels, the mean heights of capitals and ascenders do not tell I from l. */
    private const MIN_HEIGHT_GAP = 0.75;

    private int $capitalTotal = 0;
    private int $capitalCount = 0;
    private int $ascenderTotal = 0;
    private int $ascenderCount = 0;


    /**
     * @param list<RecognizedChar> $chars the characters of one line
     * @return list<RecognizedChar>
     */
    public function fixLine(array $chars): array
    {
        $capitalTops = [];
        $ascenderTops = [];
        foreach ($chars as $char) {
            if ($char->sample === null || $char->sample->parts > 1) {
                continue;
            }
            if (in_array($char->text, self::CAPITALS, true)) {
                $capitalTops[] = $char->sample->marginTop;
                $this->capitalTotal += $char->sample->height;
                $this->capitalCount++;
            } elseif (in_array($char->text, self::ASCENDERS, true)) {
                $ascenderTops[] = $char->sample->marginTop;
                $this->ascenderTotal += $char->sample->height;
                $this->ascenderCount++;
            }
        }
        // The topmost reference wins, because a misread x-height letter, such as n read as D, sits lower.
        $capitalTop = count($capitalTops) > 0 ? min($capitalTops) : null;
        $ascenderTop = count($ascenderTops) > 0 ? min($ascenderTops) : null;
        $barTops = [];
        $barHeight = 0;
        foreach ($chars as $char) {
            if (in_array($char->text, self::BARS, true) && $char->sample !== null && $char->sample->parts === 1) {
                $barTops[] = $char->sample->marginTop;
                $barHeight = max($barHeight, $char->sample->height);
            }
        }
        if (count($barTops) > 0 && max($barTops) - min($barTops) > max(1, 0.12 * $barHeight)) {
            $barTops = [];
        }

        foreach ($chars as $index => $char) {
            if (!in_array($char->text, self::BARS, true) || $char->sample === null || $char->sample->parts > 1) {
                continue;
            }
            $text = self::byLineTops($char->sample->marginTop, $capitalTop, $ascenderTop)
                ?? self::byBarTops($char->sample->marginTop, $barTops)
                ?? $this->byMeanHeights($char->sample->height)
                ?? self::byNeighbours($chars, $index)
                ?? $char->text;
            if ($text !== $char->text) {
                $chars[$index] = new RecognizedChar($text, $char->confidence, $char->italic, false, $char->glyph,
                                                    $char->sample);
            }
        }

        return $chars;
    }


    private static function byLineTops(int $top, ?int $capitalTop, ?int $ascenderTop): ?string
    {
        if ($capitalTop !== null && $ascenderTop !== null) {
            if ($capitalTop - $ascenderTop < 1) {
                return null;
            }

            return abs($top - $capitalTop) <= abs($top - $ascenderTop) ? 'I' : 'l';
        }
        if ($capitalTop !== null) {
            return $top < $capitalTop ? 'l' : 'I';
        }
        if ($ascenderTop !== null) {
            return $top > $ascenderTop ? 'I' : 'l';
        }

        return null;
    }


    /**
     * @param list<int> $barTops
     */
    private static function byBarTops(int $top, array $barTops): ?string
    {
        if (count($barTops) === 0 || max($barTops) === min($barTops)) {
            return null;
        }

        return $top - min($barTops) < max($barTops) - $top ? 'l' : 'I';
    }


    private function byMeanHeights(int $height): ?string
    {
        if ($this->capitalCount < 3 || $this->ascenderCount < 3) {
            return null;
        }
        $capital = $this->capitalTotal / $this->capitalCount;
        $ascender = $this->ascenderTotal / $this->ascenderCount;
        $gap = $ascender - $capital;
        if ($gap < self::MIN_HEIGHT_GAP || $height < $capital - $gap || $height > $ascender + $gap) {
            return null;
        }

        return abs($height - $capital) <= abs($height - $ascender) ? 'I' : 'l';
    }


    /**
     * A bar after a lower case letter is l. A bar next to capitals, or alone, is I. Before an apostrophe, it can
     * be I as in I'm or l as in l'eau.
     *
     * @param list<RecognizedChar> $chars
     */
    private static function byNeighbours(array $chars, int $index): ?string
    {
        $previous = $chars[$index - 1] ?? null;
        $next = $chars[$index + 1] ?? null;
        $previousLetter = $previous !== null && !$previous->isSpace ? $previous->text : '';
        $nextLetter = $next !== null && !$next->isSpace ? $next->text : '';

        if (preg_match('/^\p{Ll}$/u', $previousLetter) === 1) {
            return 'l';
        }
        if (preg_match('/^\p{Lu}$/u', $previousLetter) === 1 || preg_match('/^\p{Lu}$/u', $nextLetter) === 1) {
            return 'I';
        }
        if (preg_match('/^\p{L}$/u', $previousLetter) !== 1 && preg_match('/^[\p{L}\'\x{2019}]$/u', $nextLetter) !== 1) {
            return 'I';
        }

        return null;
    }
}
