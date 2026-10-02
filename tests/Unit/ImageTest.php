<?php

namespace GlyphOcr\Tests\Unit;

use GlyphOcr\Exceptions\InvalidArgumentException;
use GlyphOcr\Image;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

class ImageTest extends TestCase
{
    public function testWrapsAStringOrAListOfBytes(): void
    {
        $this->assertSame("\x01\x02\x03\x04", Image::fromRgba("\x01\x02\x03\x04", 1, 1)->rgba);
        $this->assertSame("\x01\x02\x03\x04", Image::fromRgba([1, 2, 3, 4], 1, 1)->rgba);
    }


    public function testRejectsAWrongByteCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs 8 bytes');
        Image::fromRgba("\0\0\0\0", 2, 1);
    }


    public function testRejectsValuesOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Image::fromRgba([1, 2, 3, 256], 1, 1);
    }


    #[RequiresPhpExtension('gd')]
    public function testCopiesATrueColourGdImage(): void
    {
        $gd = imagecreatetruecolor(2, 1);
        imagealphablending($gd, false);
        imagesetpixel($gd, 0, 0, imagecolorallocatealpha($gd, 255, 128, 0, 0));
        imagesetpixel($gd, 1, 0, imagecolorallocatealpha($gd, 10, 20, 30, 127));

        $image = Image::fromGd($gd);

        $this->assertSame("\xFF\x80\x00\xFF\x0A\x14\x1E\x00", $image->rgba);
    }


    #[RequiresPhpExtension('gd')]
    public function testCopiesAPaletteGdImage(): void
    {
        $gd = imagecreate(1, 1);
        imagecolorallocatealpha($gd, 1, 2, 3, 0);

        $this->assertSame("\x01\x02\x03\xFF", Image::fromGd($gd)->rgba);
    }
}
