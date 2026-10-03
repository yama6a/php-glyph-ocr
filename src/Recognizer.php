<?php

namespace GlyphOcr;

use GlyphOcr\Exceptions\InvalidArgumentException;
use GlyphOcr\Internal\Bitmap;
use GlyphOcr\Internal\CapitalIFixer;
use GlyphOcr\Internal\CaseFixer;
use GlyphOcr\Internal\LineCaseFixer;
use GlyphOcr\Internal\LineHeightTracker;
use GlyphOcr\Internal\MarkFixer;
use GlyphOcr\Internal\Matcher;
use GlyphOcr\Internal\SplitItem;
use GlyphOcr\Internal\Splitter;

/**
 * Recognizer reads the text of clean rendered text bitmaps, such as image subtitles.
 *
 * A recognizer learns the typical glyph height and the Latin letter heights from the images it reads, and
 * uses them for the next images. Use one recognizer per source of images, or call reset() between sources.
 */
final class Recognizer
{
    private Matcher $matcher;
    private LineHeightTracker $lineHeights;
    private CaseFixer $caseFixer;
    private CapitalIFixer $capitalIFixer;


    /**
     * @param int $inkThreshold a pixel is ink when the sum of its premultiplied red, green and blue is at least
     *                          this, from 1 to 765; so a dark outline does not count as ink
     * @param int|null $spaceWidth empty columns between two glyphs that make a space; null derives it from the
     *                             glyph height of each line
     * @param int $maxWrongPixels the error budget of the loose match passes
     * @param bool $fixLatinCase picks upper or lower case for letters such as o and O from their height
     * @param string $unknownText the text of a glyph that matches no database glyph
     * @param float $italicSlant when above 0, a glyph that matches nothing is slanted back by this factor and
     *                           tried again; 0.2 fits most italic fonts
     * @param bool $rightToLeft puts the glyphs of each line in right to left order
     * @param int $minLineHeight the minimum line height in pixels until the recognizer has learned the glyph heights
     * @param bool $lineContext compares each glyph with the other glyphs of its line to pick capital I or lower
     *                          case l, upper or lower case for letters such as o and O, and comma or apostrophe;
     *                          false keeps the database text, as Subtitle Edit does
     */
    public function __construct(
        private readonly GlyphDatabase $database,
        private readonly int $inkThreshold = 200,
        private readonly ?int $spaceWidth = null,
        private readonly int $maxWrongPixels = 25,
        private readonly bool $fixLatinCase = true,
        private readonly string $unknownText = '*',
        private readonly float $italicSlant = 0.0,
        private readonly bool $rightToLeft = false,
        private readonly int $minLineHeight = 12,
        private readonly bool $lineContext = true,
    ) {
        if ($inkThreshold < 1 || $inkThreshold > 765) {
            throw new InvalidArgumentException("Cannot create a recognizer with ink threshold $inkThreshold - " .
                                               "it must be from 1 to 765!");
        }
        if ($spaceWidth !== null && $spaceWidth < 1) {
            throw new InvalidArgumentException("Cannot create a recognizer with space width $spaceWidth - " .
                                               "it must be at least 1!");
        }
        if ($maxWrongPixels < 0 || $italicSlant < 0 || $italicSlant > 1 || $minLineHeight < 1) {
            throw new InvalidArgumentException("Cannot create a recognizer - the wrong pixel budget must be at " .
                                               "least 0, the italic slant from 0 to 1 and the minimum line " .
                                               "height at least 1!");
        }
        $this->matcher = new Matcher($database, $maxWrongPixels, true, true, $italicSlant);
        $this->reset();
    }


    /**
     * Forgets the glyph heights learned from earlier images.
     */
    public function reset(): void
    {
        $this->lineHeights = new LineHeightTracker($this->minLineHeight);
        $this->caseFixer = new CaseFixer();
        $this->capitalIFixer = new CapitalIFixer();
    }


    public function recognize(Image $image): RecognitionResult
    {
        [$bitmap, $items] = $this->splitImage($image);

        $lines = [];
        $chars = [];
        $count = count($items);
        for ($i = 0; $i < $count; $i++) {
            $item = $items[$i];
            if ($item->bitmap === null) {
                if ($item->special === Splitter::LINE_BREAK) {
                    if (count($chars) > 0) {
                        $lines[] = $this->line($chars);
                    }
                    $chars = [];
                } else {
                    $chars[] = new RecognizedChar(' ', 1.0, false, true, null, null);
                }
                continue;
            }

            $match = $this->matcher->match($bitmap, $items, $i);
            if ($match === null) {
                $chars[] = new RecognizedChar($this->unknownText, 0.0, false, false, null, self::sample($item));
                continue;
            }

            [$glyph, $confidence, $slanted] = $match;
            $parts = max(1, $glyph->expandCount);
            $sample = $parts > 1 && $i + $parts <= $count
                ? GlyphSample::merge(array_map(self::sample(...), array_slice($items, $i, $parts)))
                : self::sample($item);
            $text = $this->fixLatinCase ? $this->caseFixer->fix($glyph->text, $item->bitmap->height) : $glyph->text;
            $chars[] = new RecognizedChar($text, $confidence, $glyph->italic || $slanted, false, $glyph, $sample);
            $i += $parts - 1;
        }
        if (count($chars) > 0) {
            $lines[] = $this->line($chars);
        }

        return new RecognitionResult($lines);
    }


    /**
     * @param list<RecognizedChar> $chars
     */
    private function line(array $chars): RecognizedLine
    {
        if ($this->lineContext) {
            $chars = $this->capitalIFixer->fixLine(LineCaseFixer::fixLine(MarkFixer::fixLine($chars)));
        }

        return new RecognizedLine($chars);
    }


    /**
     * Cuts the image into lines of glyph samples without matching them, for example to train new glyphs.
     *
     * @return list<list<GlyphSample|null>> one list per line, with null for a space
     */
    public function split(Image $image): array
    {
        [, $items] = $this->splitImage($image);
        $lines = [[]];
        foreach ($items as $item) {
            if ($item->special === Splitter::LINE_BREAK) {
                $lines[] = [];
            } else {
                $lines[count($lines) - 1][] = $item->bitmap === null ? null : self::sample($item);
            }
        }

        return array_values(array_filter($lines, fn(array $line): bool => count($line) > 0));
    }


    /**
     * @return array{Bitmap, list<SplitItem>}
     */
    private function splitImage(Image $image): array
    {
        $bitmap = Bitmap::fromRgba($image->rgba, $image->width, $image->height, $this->inkThreshold);
        $bitmap->cropTopEmptyRows();
        if ($bitmap->isEmpty()) {
            return [$bitmap, []];
        }

        $spaceWidth = $this->spaceWidth ?? PHP_INT_MAX;
        $lineHeights = $this->lineHeights;
        if (!$lineHeights->isWarm()) {
            // Without learned heights, a split with the fallback minimum line height first measures this image.
            $lineHeights = new LineHeightTracker($this->minLineHeight, 3);
            $lineHeights->update(Splitter::splitToLetters($bitmap, $spaceWidth, $this->rightToLeft,
                                                          $this->minLineHeight, -1.0));
        }
        $items = Splitter::splitToLetters($bitmap, $spaceWidth, $this->rightToLeft, $lineHeights->minLineHeight(),
                                          $lineHeights->averageLineHeight());
        if ($this->spaceWidth === null) {
            $items = self::insertSpaces($items);
        }
        $this->lineHeights->update($items);

        return [$bitmap, $items];
    }


    /**
     * Puts a space between two glyphs whose gap is wide compared with the glyph height of their line.
     *
     * @param list<SplitItem> $items
     * @return list<SplitItem>
     */
    private static function insertSpaces(array $items): array
    {
        $result = [];
        $line = [];
        foreach ([...$items, SplitItem::special(Splitter::LINE_BREAK)] as $index => $item) {
            if ($item->special !== Splitter::LINE_BREAK) {
                $line[] = $item;
                continue;
            }
            $heights = array_map(fn(SplitItem $i): int => $i->bitmap->height, $line);
            sort($heights);
            $typical = count($heights) > 0 ? $heights[intdiv(count($heights) * 3, 4)] : 0;
            $minGap = max(3, (int)round($typical * 0.33));
            $previousRight = null;
            foreach ($line as $glyph) {
                if ($previousRight !== null && $glyph->x - $previousRight >= $minGap) {
                    $result[] = SplitItem::special(Splitter::SPACE, $glyph->y, $glyph->x - $previousRight);
                }
                $result[] = $glyph;
                $previousRight = max($previousRight ?? PHP_INT_MIN, $glyph->x + $glyph->bitmap->width);
            }
            if ($index < count($items)) {
                $result[] = $item;
            }
            $line = [];
        }

        return $result;
    }


    private static function sample(SplitItem $item): GlyphSample
    {
        $bitmap = $item->bitmap;

        return new GlyphSample($item->x, $item->y, $bitmap->width, $bitmap->height, $item->top, $bitmap->pixels);
    }
}
