<?php

namespace GlyphOcr;

/**
 * RecognizedChar is one character of a recognized line, or a space between words.
 *
 * The confidence is the share of the database glyph's line points that agree with the image, from 0 to 1.
 * An unknown glyph has the placeholder text of the recognizer, confidence 0 and no database glyph.
 * A space has confidence 1 and no sample.
 */
final class RecognizedChar
{
    public function __construct(
        public readonly string $text,
        public readonly float $confidence,
        public readonly bool $italic,
        public readonly bool $isSpace,
        public readonly ?Glyph $glyph,
        public readonly ?GlyphSample $sample,
    ) {
    }


    public function isKnown(): bool
    {
        return $this->isSpace || $this->glyph !== null;
    }
}
