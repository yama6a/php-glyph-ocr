<?php

namespace GlyphOcr\Tests\Unit;

use GlyphOcr\Exceptions\InvalidArgumentException;
use GlyphOcr\GlyphSample;
use GlyphOcr\Trainer;
use PHPUnit\Framework\TestCase;

class TrainerTest extends TestCase
{
    private static function ring(): GlyphSample
    {
        $pixels = [];
        for ($y = 0; $y < 20; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $distance = sqrt(($x - 7.5) ** 2 + ($y - 9.5) ** 2);
                $pixels[] = $distance >= 4 && $distance <= 7.5 ? 1 : 0;
            }
        }

        return new GlyphSample(3, 4, 16, 20, 2, $pixels);
    }


    public function testFindsLinesOverInkAndOverBackground(): void
    {
        $sample = self::ring();

        $glyph = (new Trainer())->train($sample, 'o', true);

        $this->assertSame(['o', 16, 20, 2, true, 0], [$glyph->text, $glyph->width, $glyph->height, $glyph->marginTop,
                                                      $glyph->italic, $glyph->expandCount]);
        $this->assertGreaterThan(10, count($glyph->foregroundLines));
        $this->assertGreaterThan(10, count($glyph->backgroundLines));
        foreach ($glyph->foregroundLines as [$x1, $y1, $x2, $y2]) {
            foreach ([[$x1, $y1], [$x2, $y2]] as [$x, $y]) {
                $this->assertSame(1, $sample->pixels[$y * 16 + $x], "foreground end point $x,$y is not ink");
            }
        }
    }


    public function testTheSameSeedGivesTheSameGlyph(): void
    {
        $this->assertEquals((new Trainer(seed: 7))->train(self::ring(), 'o'),
                            (new Trainer(seed: 7))->train(self::ring(), 'o'));
        $this->assertNotEquals((new Trainer(seed: 7))->train(self::ring(), 'o'),
                               (new Trainer(seed: 8))->train(self::ring(), 'o'));
    }


    public function testMergedSamplesBecomeAnExpandedGlyph(): void
    {
        $mark = new GlyphSample(0, 0, 3, 6, 0, array_fill(0, 18, 1));
        $other = new GlyphSample(6, 0, 3, 6, 0, array_fill(0, 18, 1));
        $merged = GlyphSample::merge([$mark, $other]);

        $glyph = (new Trainer())->train($merged, '"');

        $this->assertSame([9, 6, 2], [$glyph->width, $glyph->height, $glyph->expandCount]);
        $this->assertSame("###...###\n###...###\n###...###\n###...###\n###...###\n###...###", $merged->toAscii());
    }


    public function testRejectsEmptyText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Trainer())->train(self::ring(), '');
    }
}
