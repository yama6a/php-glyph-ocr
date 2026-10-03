<?php

namespace GlyphOcr\Tests\Golden;

use GlyphOcr\Glyph;
use GlyphOcr\GlyphDatabase;
use GlyphOcr\Tests\Fixtures\Generator\SubtitleFonts;
use PHPUnit\Framework\TestCase;

class SubtitleFontsTest extends TestCase
{
    /**
     * Rebuilding the whole database takes 2 minutes, so the test rebuilds the Liberation Sans block at 28 px.
     * The database keeps the glyphs of one part before the glyphs of several parts, so each kind is checked
     * as one run.
     */
    public function testTheGeneratorRebuildsTheShippedGlyphs(): void
    {
        $shipped = GlyphDatabase::fromFile(dirname(__DIR__, 2) . '/resources/SubtitleFonts.nocr');
        $this->assertSame(count(SubtitleFonts::FONTS) * count(SubtitleFonts::SIZES) * mb_strlen(SubtitleFonts::CHARS),
                          $shipped->count());

        $rebuilt = new GlyphDatabase(SubtitleFonts::block('LiberationSans-Regular.ttf', SubtitleFonts::SIZES[0]));
        foreach (['singleGlyphs', 'expandedGlyphs'] as $kind) {
            $expected = array_map(self::describe(...), $rebuilt->{$kind}());
            $all = array_map(self::describe(...), $shipped->{$kind}());
            $start = array_search($expected[0], $all, true);
            $this->assertNotFalse($start, "The first rebuilt glyph '{$rebuilt->{$kind}()[0]->text}' is missing");
            $this->assertSame($expected, array_slice($all, $start, count($expected)));
        }
    }


    public function testTheDatabaseHasTheFontGlyphsBeforeTheLatinGlyphs(): void
    {
        $database = GlyphDatabase::subtitleFonts();
        $fonts = GlyphDatabase::fromFile(dirname(__DIR__, 2) . '/resources/SubtitleFonts.nocr');

        $this->assertSame($fonts->count() + GlyphDatabase::latin()->count(), $database->count());
        $this->assertSame(self::describe($fonts->glyphs()[0]), self::describe($database->glyphs()[0]));
    }


    private static function describe(Glyph $glyph): string
    {
        return json_encode(get_object_vars($glyph), JSON_THROW_ON_ERROR);
    }
}
