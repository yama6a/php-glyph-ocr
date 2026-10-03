<?php

namespace GlyphOcr\Tests\Unit;

use GlyphOcr\Exceptions\InvalidArgumentException;
use GlyphOcr\GlyphDatabase;
use GlyphOcr\GlyphSample;
use GlyphOcr\Image;
use GlyphOcr\RecognizedChar;
use GlyphOcr\Recognizer;
use GlyphOcr\Trainer;
use PHPUnit\Framework\TestCase;

class RecognizerTest extends TestCase
{
    private static function fixture(string $name): Image
    {
        return Image::fromPng((string)file_get_contents(dirname(__DIR__) . "/fixtures/$name.png"));
    }


    public function testMarksEveryGlyphUnknownWithAnEmptyDatabase(): void
    {
        $result = (new Recognizer(new GlyphDatabase()))->recognize(self::fixture('pgs/05'));

        $this->assertSame('*** ** ***', $result->text());
        $this->assertSame(0.0, $result->confidence());
        $this->assertCount(8, $result->unknownChars());
        $this->assertInstanceOf(GlyphSample::class, $result->unknownChars()[0]->sample);
    }


    public function testReadsTheTextAfterTrainingTheConfirmedGlyphs(): void
    {
        $database = new GlyphDatabase();
        $recognizer = new Recognizer($database);
        $image = self::fixture('pgs/04');
        $expected = "Hello, my friend.\nHow are you?";

        $unknown = $recognizer->recognize($image)->unknownChars();
        $letters = preg_split('//u', str_replace([' ', "\n"], '', $expected), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertCount(count($letters), $unknown);
        $trainer = new Trainer();
        foreach ($unknown as $index => $char) {
            $database->add($trainer->train($char->sample, $letters[$index]));
        }
        $result = (new Recognizer($database))->recognize($image);

        $this->assertSame($expected, $result->text());
        $this->assertSame(1.0, $result->confidence());
        $this->assertCount(2, $result->lines);
        $this->assertSame('How are you?', $result->lines[1]->text);
        $this->assertSame(1.0, $result->lines[1]->confidence);
        $first = $result->lines[0]->chars[0];
        $this->assertSame('H', $first->text);
        $this->assertTrue($first->isKnown());
        $this->assertNotNull($first->glyph);
    }


    public function testSplitReturnsGlyphSamplesPerLineWithNullForSpaces(): void
    {
        $lines = (new Recognizer(new GlyphDatabase()))->split(self::fixture('pgs/02'));

        $this->assertCount(2, $lines);
        $this->assertNull($lines[0][1]);
        // "- Are you sure?" has 12 glyphs in 4 words.
        $this->assertSame('# ### ### #####', implode('', array_map(
            fn(?GlyphSample $s): string => $s === null ? ' ' : '#',
            $lines[0],
        )));
    }


    public function testFixedSpaceWidthUsesTheSplitterRule(): void
    {
        $recognizer = new Recognizer(GlyphDatabase::latin(), spaceWidth: 1000);

        $this->assertStringNotContainsString(' ', $recognizer->recognize(self::fixture('pgs/05'))->text());
    }


    public function testReturnsNoLinesForAnEmptyImage(): void
    {
        $image = Image::fromRgba(str_repeat("\0\0\0\0", 100), 10, 10);

        $result = (new Recognizer(GlyphDatabase::latin()))->recognize($image);

        $this->assertSame([], $result->lines);
        $this->assertSame('', $result->text());
        $this->assertSame(0.0, $result->confidence());
    }


    public function testUsesTheUnknownText(): void
    {
        $result = (new Recognizer(new GlyphDatabase(), unknownText: '?'))->recognize(self::fixture('pgs/05'));

        $this->assertSame('??? ?? ???', $result->text());
    }


    public function testSpacesHaveFullConfidenceAndNoSample(): void
    {
        $chars = (new Recognizer(new GlyphDatabase()))->recognize(self::fixture('pgs/05'))->lines[0]->chars;
        $spaces = array_values(array_filter($chars, fn(RecognizedChar $c): bool => $c->isSpace));

        $this->assertCount(2, $spaces);
        $this->assertSame(1.0, $spaces[0]->confidence);
        $this->assertNull($spaces[0]->sample);
        $this->assertTrue($spaces[0]->isKnown());
    }


    public function testItalicSlantRetriesUnknownGlyphsAndMarksThemItalic(): void
    {
        $image = self::fixture('italic/03');
        $upright = (new Recognizer(GlyphDatabase::latin()))->recognize($image);
        $slanted = (new Recognizer(GlyphDatabase::latin(), italicSlant: 0.2))->recognize($image);

        $this->assertLessThan(count($upright->unknownChars()), count($slanted->unknownChars()));
        $italic = array_filter($slanted->lines[0]->chars, fn(RecognizedChar $c): bool => $c->italic && !$c->glyph?->italic);
        $this->assertNotEmpty($italic);
    }


    public function testRejectsAnInvalidInkThreshold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Recognizer(new GlyphDatabase(), inkThreshold: 0);
    }


    public function testRejectsAnInvalidSpaceWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Recognizer(new GlyphDatabase(), spaceWidth: 0);
    }


    public function testLineContextPicksCapitalIOrLowerCaseL(): void
    {
        $image = self::fixture('capital-i/03');

        $this->assertSame('IT IS ALL I HAVE.', (new Recognizer(GlyphDatabase::latin()))->recognize($image)->text());
        $this->assertSame('lT ls ALL l HAVE.', (new Recognizer(GlyphDatabase::latin(), lineContext: false))
            ->recognize($image)->text());
    }
}
