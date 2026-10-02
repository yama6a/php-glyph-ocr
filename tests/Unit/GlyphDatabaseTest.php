<?php

namespace GlyphOcr\Tests\Unit;

use GlyphOcr\Exceptions\GlyphOcrException;
use GlyphOcr\Exceptions\InvalidArgumentException;
use GlyphOcr\Exceptions\InvalidDatabaseException;
use GlyphOcr\Glyph;
use GlyphOcr\GlyphDatabase;
use PHPUnit\Framework\TestCase;

class GlyphDatabaseTest extends TestCase
{
    public function testLoadsTheShippedLatinDatabase(): void
    {
        $database = GlyphDatabase::latin();

        $this->assertSame(699, $database->count());
        $this->assertCount(680, $database->singleGlyphs());
        $this->assertCount(19, $database->expandedGlyphs());
        $texts = array_map(fn(Glyph $g): string => $g->text, $database->glyphs());
        $this->assertContains('a', $texts);
        $this->assertContains('♪', $texts);
        $this->assertContains('"', array_map(fn(Glyph $g): string => $g->text, $database->expandedGlyphs()));
    }


    public function testWritesTheSameBytesItRead(): void
    {
        $path = dirname(__DIR__, 2) . '/resources/Latin.nocr';
        $original = gzdecode((string)file_get_contents($path));

        $written = gzdecode(GlyphDatabase::latin()->toBytes());

        $this->assertSame(md5($original), md5($written));
    }


    public function testReadsVersion1Records(): void
    {
        $record = pack('n3', 10, 20, 3) . "\x01" . "\x00" . "\x01" . 'x' .
                  pack('n', 1) . pack('n4', 1, 2, 3, 4) . pack('n', 0);
        $database = GlyphDatabase::fromBytes((string)gzencode($record));

        $this->assertSame(1, $database->count());
        $glyph = $database->glyphs()[0];
        $this->assertSame(['x', 10, 20, 3, true, 0], [$glyph->text, $glyph->width, $glyph->height, $glyph->marginTop,
                                                     $glyph->italic, $glyph->expandCount]);
        $this->assertSame([[1, 2, 3, 4]], $glyph->foregroundLines);
        $this->assertSame([], $glyph->backgroundLines);
    }


    public function testWritesLongRecordsForLargeValues(): void
    {
        $glyph = new Glyph('W', 300, 20, 0, false, 0, [[0, 0, 299, 19]], [[1, 1, 2, 2]]);
        $database = new GlyphDatabase([$glyph]);

        $copy = GlyphDatabase::fromBytes($database->toBytes())->glyphs()[0];

        $this->assertEquals($glyph, $copy);
    }


    public function testStopsAtTheFirstBrokenRecord(): void
    {
        $good = "\x10\x05\x05\x00\x01a\x01\x00\x00\x04\x04\x00";
        $broken = "\x10\x00\x05\x00\x01b\x00\x00";

        $database = GlyphDatabase::fromBytes((string)gzencode('V2' . $good . $broken . $good));

        $this->assertSame(1, $database->count());
    }


    public function testAddPutsTheNewGlyphFirstAndRemoveDropsIt(): void
    {
        $old = new Glyph('a', 5, 5, 0, false, 0, [[0, 0, 4, 4]], []);
        $new = new Glyph('b', 5, 5, 0, false, 0, [[0, 0, 4, 4]], []);
        $expanded = new Glyph('"', 9, 5, 0, false, 2, [[0, 0, 0, 4]], []);
        $database = new GlyphDatabase([$old]);
        $revision = $database->revision();

        $database->add($new);
        $database->add($expanded);

        $this->assertSame([$new, $old, $expanded], $database->glyphs());
        $this->assertGreaterThan($revision, $database->revision());

        $database->remove($new);
        $this->assertSame([$old, $expanded], $database->glyphs());
    }


    public function testSavesAndLoadsAFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'glyph');
        $database = new GlyphDatabase([new Glyph('ä', 7, 9, 2, true, 0, [[1, 1, 5, 7]], [[0, 0, 0, 8]])]);

        $database->save($path);
        $loaded = GlyphDatabase::fromFile($path);
        unlink($path);

        $this->assertEquals($database->glyphs(), $loaded->glyphs());
    }


    public function testRejectsDataThatIsNotGzip(): void
    {
        $this->expectException(InvalidDatabaseException::class);
        GlyphDatabase::fromBytes('plain text');
    }


    public function testRejectsAMissingFile(): void
    {
        $this->expectException(GlyphOcrException::class);
        GlyphDatabase::fromFile('/does/not/exist.nocr');
    }


    public function testGlyphRejectsInvalidLines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Glyph('a', 5, 5, 0, false, 0, [[0, 0, 4]], []);
    }


    public function testGlyphRejectsAnEmptySize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Glyph('a', 0, 5, 0, false, 0, [], []);
    }
}
