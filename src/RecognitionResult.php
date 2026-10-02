<?php

namespace GlyphOcr;

/**
 * RecognitionResult holds the text lines of one image, top to bottom.
 */
final class RecognitionResult
{
    /**
     * @param list<RecognizedLine> $lines
     */
    public function __construct(public readonly array $lines)
    {
    }


    /**
     * Returns the lines joined with "\n".
     */
    public function text(): string
    {
        return implode("\n", array_map(fn(RecognizedLine $l): string => $l->text, $this->lines));
    }


    /**
     * Returns the mean confidence of all glyphs, from 0 to 1, or 0 when the image has no glyphs.
     */
    public function confidence(): float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($this->lines as $line) {
            foreach ($line->chars as $char) {
                if (!$char->isSpace) {
                    $sum += $char->confidence;
                    $count++;
                }
            }
        }

        return $count > 0 ? $sum / $count : 0.0;
    }


    /**
     * Returns the characters that matched no database glyph.
     *
     * @return list<RecognizedChar>
     */
    public function unknownChars(): array
    {
        $unknown = [];
        foreach ($this->lines as $line) {
            foreach ($line->chars as $char) {
                if (!$char->isKnown()) {
                    $unknown[] = $char;
                }
            }
        }

        return $unknown;
    }
}
