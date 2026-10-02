<?php

namespace GlyphOcr;

use GlyphOcr\Exceptions\InvalidArgumentException;

/**
 * GlyphSample is a two-colour glyph cut out of an image, ready to train a new database glyph.
 *
 * Pixels are 1 for ink and 0 for background, indexed by y * width + x. The position is in pixels of the
 * image after the empty rows at its top are removed. The top margin is the distance from the top of the
 * text line, which the matcher compares.
 */
final class GlyphSample
{
    /**
     * @param list<int> $pixels
     */
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly int $marginTop,
        public readonly array $pixels,
        public readonly int $parts = 1,
    ) {
        if ($width < 1 || $height < 1 || count($pixels) !== $width * $height) {
            throw new InvalidArgumentException("Cannot create a glyph sample of {$width}x{$height} pixels from " .
                                               count($pixels) . " pixel values!");
        }
        if ($parts < 1 || $parts > 255) {
            throw new InvalidArgumentException("Cannot create a glyph sample of $parts parts - it needs 1 to 255!");
        }
    }


    /**
     * Joins neighbouring samples into one sample of several parts, for a character that the splitter cuts
     * into pieces, such as '"' or '%'. The first sample must be the leftmost part.
     *
     * @param list<GlyphSample> $samples
     */
    public static function merge(array $samples): self
    {
        if (count($samples) === 0) {
            throw new InvalidArgumentException("Cannot merge glyph samples - the list is empty!");
        }
        if (count($samples) === 1) {
            return $samples[0];
        }

        $minX = min(array_map(fn(self $s): int => $s->x, $samples));
        $minY = min(array_map(fn(self $s): int => $s->y, $samples));
        $maxX = max(array_map(fn(self $s): int => $s->x + $s->width, $samples));
        $maxY = max(array_map(fn(self $s): int => $s->y + $s->height, $samples));
        $minTop = min(array_map(fn(self $s): int => $s->marginTop, $samples));
        $width = $maxX - $minX;
        $height = $maxY - $minY;
        $pixels = array_fill(0, $width * $height, 0);
        foreach ($samples as $sample) {
            for ($y = 0; $y < $sample->height; $y++) {
                for ($x = 0; $x < $sample->width; $x++) {
                    if ($sample->pixels[$y * $sample->width + $x] !== 0) {
                        $pixels[($sample->y - $minY + $y) * $width + $sample->x - $minX + $x] = 1;
                    }
                }
            }
        }

        return new self($minX, $minY, $width, $height, $samples[0]->marginTop - $minTop, $pixels, count($samples));
    }


    /**
     * Returns the sample as text art, one row per line, '#' for ink and '.' for background.
     */
    public function toAscii(): string
    {
        $rows = [];
        foreach (array_chunk($this->pixels, $this->width) as $row) {
            $rows[] = implode('', array_map(fn(int $p): string => $p !== 0 ? '#' : '.', $row));
        }

        return implode("\n", $rows);
    }
}
