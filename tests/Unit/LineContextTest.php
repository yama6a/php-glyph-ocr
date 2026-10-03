<?php

namespace GlyphOcr\Tests\Unit;

use GlyphOcr\GlyphSample;
use GlyphOcr\Internal\CapitalIFixer;
use GlyphOcr\Internal\LineCaseFixer;
use GlyphOcr\Internal\MarkFixer;
use GlyphOcr\RecognizedChar;
use PHPUnit\Framework\TestCase;

class LineContextTest extends TestCase
{
    /**
     * Builds a line from "text:top:height" entries, with " " for a space.
     *
     * @return list<RecognizedChar>
     */
    private static function line(string ...$entries): array
    {
        return array_map(function (string $entry): RecognizedChar {
            if ($entry === ' ') {
                return new RecognizedChar(' ', 1.0, false, true, null, null);
            }
            [$text, $top, $height] = explode(':', $entry);
            $sample = new GlyphSample(0, (int)$top, 1, (int)$height, (int)$top, array_fill(0, (int)$height, 1));

            return new RecognizedChar($text, 1.0, false, false, null, $sample);
        }, $entries);
    }


    /**
     * @param list<RecognizedChar> $chars
     */
    private static function text(array $chars): string
    {
        return implode('', array_map(fn(RecognizedChar $c): string => $c->text, $chars));
    }


    public function testABarAtTheTopOfTheCapitalsIsCapitalI(): void
    {
        // "Hl lid" where the first bar is as tall as H and the second as tall as d.
        $line = self::line('H:2:30', 'l:2:30', ' ', 'l:0:32', 'i:0:32', 'd:0:32');

        $this->assertSame('HI lid', self::text((new CapitalIFixer())->fixLine($line)));
    }


    public function testBarsOfTwoHeightsOnALineSplitIntoIAndL(): void
    {
        // "Il est" without capitals or ascenders to compare with.
        $line = self::line('l:4:39', 'l:2:41', ' ', 'e:13:30', 's:13:30', 't:5:38');

        $this->assertSame('Il est', self::text((new CapitalIFixer())->fixLine($line)));
    }


    public function testTheMeanHeightsOfEarlierLinesDecideALineWithoutReferences(): void
    {
        $fixer = new CapitalIFixer();
        $fixer->fixLine(self::line('H:2:30', 'E:2:30', 'T:2:30', 'd:0:32', 'b:0:32', 'h:0:32'));

        $this->assertSame('I', self::text($fixer->fixLine(self::line('l:5:30'))));
        $this->assertSame('l', self::text($fixer->fixLine(self::line('I:5:32'))));
    }


    public function testTheNeighboursDecideWhenTheHeightsCannot(): void
    {
        $fixer = new CapitalIFixer();

        $this->assertSame('ol', self::text($fixer->fixLine(self::line('o:10:20', 'I:0:30'))));
        $this->assertSame('I', self::text($fixer->fixLine(self::line('l:0:30'))));
        $this->assertSame("l'", self::text($fixer->fixLine(self::line('l:0:30', "':0:10"))));
    }


    public function testPicksTheCaseOfRoundLettersFromTheLine(): void
    {
        // "Do not GO", where the database read o as O and O as o.
        $line = self::line('D:0:30', 'O:9:22', ' ', 'n:9:21', 'O:9:22', 't:3:28', ' ', 'G:0:31', 'o:0:31');

        $this->assertSame('Do not GO', self::text(LineCaseFixer::fixLine($line)));
    }


    public function testKeepsTheCaseWithoutReferenceLetters(): void
    {
        $line = self::line('O:9:22', 'o:0:31');

        $this->assertSame('Oo', self::text(LineCaseFixer::fixLine($line)));
    }


    public function testPlacesCommasApostrophesAndBarsByTheirPlaceOnTheLine(): void
    {
        // "Bill, it's" where the database read the bars as apostrophes, the comma as an apostrophe and the
        // apostrophe as a comma.
        $line = self::line('B:2:30', 'i:0:32', "':0:32", "':0:32", "':28:8", ' ', 'i:0:32', 't:3:29', ',:0:10',
                           's:9:23');

        $this->assertSame("Bill, it's", self::text(MarkFixer::fixLine($line)));
    }
}
