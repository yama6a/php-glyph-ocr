<?php

namespace GlyphOcr\Tests\Unit;

use GlyphOcr\Internal\Bitmap;
use GlyphOcr\Internal\Splitter;
use PHPUnit\Framework\TestCase;

class SplitterTest extends TestCase
{
    /**
     * Builds a bitmap from text art: '#' is ink, any other character is background.
     */
    private static function bitmap(string ...$rows): Bitmap
    {
        $pixels = [];
        foreach ($rows as $row) {
            foreach (str_split($row) as $char) {
                $pixels[] = $char === '#' ? 1 : 0;
            }
        }

        return new Bitmap(strlen($rows[0]), count($rows), $pixels);
    }


    /**
     * @param list<\GlyphOcr\Internal\SplitItem> $items
     */
    private static function describe(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = $item->bitmap === null
                ? json_encode($item->special)
                : "{$item->x},{$item->y} {$item->bitmap->width}x{$item->bitmap->height} top {$item->top}";
        }

        return implode(' | ', $parts);
    }


    public function testCutsGlyphsAtEmptyColumnsAndAddsASpaceForAWideGap(): void
    {
        $bitmap = self::bitmap(
            '.##..#......###.',
            '.##..#......#.#.',
            '.##..##.....###.',
            '.##..#........#.',
        );

        $items = Splitter::splitToLetters($bitmap, 4, false, 12, -1);

        $this->assertSame('1,0 2x4 top 0 | 5,0 2x4 top 0 | " " | 12,0 3x4 top 0', self::describe($items));
    }


    public function testCropsEmptyRowsOfEachGlyphAndKeepsItsTopMargin(): void
    {
        $bitmap = self::bitmap(
            '.#.......',
            '.#..##...',
            '.#..##...',
            '.#.......',
            '.#.......',
            '.#.......',
            '.........',
            '.........',
        );

        $items = Splitter::splitToLetters($bitmap, 4, false, 12, -1);

        // Subtitle Edit never crops the bottom of a glyph above its fifth row, so the 2x2 glyph keeps 2 empty rows.
        $this->assertSame('1,0 1x6 top 0 | 4,1 2x4 top 1', self::describe($items));
    }


    public function testSeparatesSlantedGlyphsThatShareColumns(): void
    {
        // Two strokes slanted 1 pixel per 5 rows, with no empty column between them.
        $rows = [];
        for ($y = 0; $y < 20; $y++) {
            $shift = 4 - intdiv($y, 5);
            $rows[] = str_repeat('.', $shift + 1) . '###.###' . str_repeat('.', 5 - $shift);
        }
        $bitmap = self::bitmap(...$rows);

        $items = Splitter::splitToLetters($bitmap, 12, false, 12, -1);

        $this->assertCount(2, $items);
        $this->assertSame(60, array_sum($items[0]->bitmap->pixels));
        $this->assertSame(60, array_sum($items[1]->bitmap->pixels));
    }


    public function testSplitsTwoLinesWithALineBreakItem(): void
    {
        $rows = [];
        for ($y = 0; $y < 40; $y++) {
            $ink = ($y >= 2 && $y < 16) || ($y >= 24 && $y < 38);
            $rows[] = $ink ? '..####....####..' : str_repeat('.', 16);
        }

        $items = Splitter::splitToLetters(self::bitmap(...$rows), 3, false, 12, -1);

        // The top of a glyph on a later line counts from the top of its line part, which starts on an empty row.
        $this->assertSame(
            '2,2 4x14 top 2 | " " | 10,2 4x14 top 2 | "\n" | 2,24 4x14 top 0 | " " | 10,24 4x14 top 0',
            self::describe($items),
        );
    }


    public function testReversesGlyphsForRightToLeft(): void
    {
        $bitmap = self::bitmap('.#..#.', '.#..#.', '.#..#.', '.#..#.', '.#..#.');

        $items = Splitter::splitToLetters($bitmap, 12, true, 12, -1);

        $this->assertSame('4,0 1x5 top 0 | 1,0 1x5 top 0', self::describe($items));
    }


    public function testTwoColourStepUsesPremultipliedRgb(): void
    {
        // White at alpha 66 sums to 3 * 66 = 198 after premultiplying, so it is background at threshold 200.
        $rgba = "\xFF\xFF\xFF\x42" . "\xFF\xFF\xFF\x43" . "\x00\x00\x00\xFF" . "\xFF\xFF\x00\xFF";

        $bitmap = Bitmap::fromRgba($rgba, 4, 1, 200);

        $this->assertSame([0, 1, 0, 1], $bitmap->pixels);
    }
}
