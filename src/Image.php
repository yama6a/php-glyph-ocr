<?php

namespace GlyphOcr;

use GlyphOcr\Exceptions\InvalidArgumentException;
use GlyphOcr\Png\PngDecoder;

/**
 * Image is an RGBA bitmap with straight (not premultiplied) alpha, 4 bytes per pixel, rows top to bottom.
 */
final class Image
{
    /**
     * @param string $rgba width * height * 4 bytes in the order R, G, B, A
     */
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $rgba,
    ) {
    }


    /**
     * Decodes PNG bytes. Supports every PNG colour type, bit depths 1 to 16, transparency chunks and interlacing.
     */
    public static function fromPng(string $png): self
    {
        $decoded = PngDecoder::decode($png);

        return new self($decoded['width'], $decoded['height'], $decoded['rgba']);
    }


    /**
     * Copies the pixels of a GD image. Needs ext-gd.
     */
    public static function fromGd(\GdImage $image): self
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $trueColor = imageistruecolor($image);
        $rgba = '';
        for ($y = 0; $y < $height; $y++) {
            $row = '';
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                if ($trueColor) {
                    $alpha = ($color >> 24) & 0x7F;
                    $row .= chr(($color >> 16) & 0xFF) . chr(($color >> 8) & 0xFF) . chr($color & 0xFF);
                } else {
                    $entry = imagecolorsforindex($image, $color);
                    $alpha = $entry['alpha'];
                    $row .= chr($entry['red']) . chr($entry['green']) . chr($entry['blue']);
                }
                // GD stores alpha as 0 (opaque) to 127 (transparent).
                $row .= chr(intdiv((127 - $alpha) * 255 + 63, 127));
            }
            $rgba .= $row;
        }

        return new self($width, $height, $rgba);
    }


    /**
     * Wraps raw RGBA pixels: a string of width * height * 4 bytes, or a list of as many integers from 0 to 255.
     *
     * @param string|list<int> $pixels
     */
    public static function fromRgba(string|array $pixels, int $width, int $height): self
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("Cannot create an image of {$width}x{$height} pixels - " .
                                               "width and height must be at least 1!");
        }
        if (is_array($pixels)) {
            foreach ($pixels as $value) {
                if (!is_int($value) || $value < 0 || $value > 255) {
                    throw new InvalidArgumentException("Cannot create an image - every pixel value must be an " .
                                                       "integer from 0 to 255!");
                }
            }
            $pixels = pack('C*', ...$pixels);
        }
        $expected = $width * $height * 4;
        if (strlen($pixels) !== $expected) {
            throw new InvalidArgumentException("Cannot create an image of {$width}x{$height} pixels from " .
                                               strlen($pixels) . " bytes - it needs $expected bytes!");
        }

        return new self($width, $height, $pixels);
    }
}
