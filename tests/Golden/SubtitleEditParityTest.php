<?php

namespace GlyphOcr\Tests\Golden;

use GlyphOcr\GlyphDatabase;
use GlyphOcr\Image;
use GlyphOcr\Internal\Bitmap;
use GlyphOcr\Internal\CaseFixer;
use GlyphOcr\Internal\LineHeightTracker;
use GlyphOcr\Internal\Matcher;
use GlyphOcr\Internal\Splitter;
use PHPUnit\Framework\TestCase;

/**
 * Runs the port the way the nOCR engine of Subtitle Edit runs, and compares with what Subtitle Edit itself
 * read from the golden images. tests/fixtures/SOURCES.md says how to produce the expected file.
 */
class SubtitleEditParityTest extends TestCase
{
    public function testReadsTheGoldenImagesExactlyLikeSubtitleEdit(): void
    {
        $fixtures = dirname(__DIR__) . '/fixtures';
        $expected = file("$fixtures/subtitle-edit/expected.txt", FILE_IGNORE_NEW_LINES);
        $database = GlyphDatabase::latin();

        $actual = [];
        foreach (glob("$fixtures/*/*.png") as $file) {
            $actual[] = basename(dirname($file)) . '/' . basename($file) . ' | ' . self::read($database, $file);
        }

        $this->assertSame($expected, $actual);
    }


    /**
     * Mirrors NOcrOcrEngine.Recognize of Subtitle Edit: a fresh engine, a fixed space width of 12 pixels and
     * no italic tags. A line break shows as " / ".
     */
    private static function read(GlyphDatabase $database, string $file): string
    {
        $image = Image::fromPng((string)file_get_contents($file));
        $bitmap = Bitmap::fromRgba($image->rgba, $image->width, $image->height, 200);
        $bitmap->cropTopEmptyRows();
        $heights = new LineHeightTracker(12);
        $items = Splitter::splitToLetters($bitmap, 12, false, $heights->minLineHeight(), $heights->averageLineHeight());
        $matcher = new Matcher($database, 25, true, true, 0.0);
        $caseFixer = new CaseFixer();

        $text = '';
        for ($i = 0; $i < count($items); $i++) {
            $item = $items[$i];
            if ($item->bitmap === null) {
                $text .= $item->special === Splitter::LINE_BREAK ? ' / ' : $item->special;
                continue;
            }
            $match = $matcher->match($bitmap, $items, $i);
            if ($match === null) {
                $text .= '*';
                continue;
            }
            $i += max(1, $match[0]->expandCount) - 1;
            $text .= $caseFixer->fix($match[0]->text, $item->bitmap->height);
        }

        return trim($text);
    }
}
