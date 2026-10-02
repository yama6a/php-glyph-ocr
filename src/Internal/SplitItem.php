<?php

namespace GlyphOcr\Internal;

/**
 * SplitItem is one glyph cut out of the image, or a space or line break marker. Port of ImageSplitterItem2.
 *
 * @internal
 */
final class SplitItem
{
    public int $top = 0;
    public int $spacePixels = 0;


    public function __construct(
        public int $x,
        public int $y,
        public ?Bitmap $bitmap,
        public ?string $special = null,
    ) {
    }


    public static function special(string $text, int $y = 0, int $spacePixels = 0): self
    {
        $item = new self(0, $y, null, $text);
        $item->spacePixels = $spacePixels;

        return $item;
    }
}
