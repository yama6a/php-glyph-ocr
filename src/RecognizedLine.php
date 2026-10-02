<?php

namespace GlyphOcr;

/**
 * RecognizedLine is one text line of an image.
 */
final class RecognizedLine
{
    public readonly string $text;

    /** The mean confidence of the glyphs on the line, from 0 to 1. Spaces do not count. */
    public readonly float $confidence;


    /**
     * @param list<RecognizedChar> $chars
     */
    public function __construct(public readonly array $chars)
    {
        $this->text = implode('', array_map(fn(RecognizedChar $c): string => $c->text, $chars));
        $glyphs = array_filter($chars, fn(RecognizedChar $c): bool => !$c->isSpace);
        $this->confidence = count($glyphs) > 0
            ? array_sum(array_map(fn(RecognizedChar $c): float => $c->confidence, $glyphs)) / count($glyphs)
            : 0.0;
    }
}
