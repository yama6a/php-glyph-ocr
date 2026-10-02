<?php

namespace GlyphOcr;

use GlyphOcr\Exceptions\InvalidArgumentException;

/**
 * Glyph is one database entry: the text of a character and the line segments that describe its shape.
 *
 * A foreground line must run over ink and a background line must run over empty pixels. Coordinates are
 * in pixels of the glyph as it was trained, and the matcher scales them to the size of the image glyph.
 * A glyph with an expand count of 2 or more spans that many cut-out parts, for example the two marks of '"'.
 */
final class Glyph
{
    /**
     * @param list<array{int, int, int, int}> $foregroundLines each line as [x1, y1, x2, y2]
     * @param list<array{int, int, int, int}> $backgroundLines each line as [x1, y1, x2, y2]
     */
    public function __construct(
        public readonly string $text,
        public readonly int $width,
        public readonly int $height,
        public readonly int $marginTop,
        public readonly bool $italic,
        public readonly int $expandCount,
        public readonly array $foregroundLines,
        public readonly array $backgroundLines,
    ) {
        if ($width < 1 || $height < 1 || $width > 0xFFFF || $height > 0xFFFF) {
            throw new InvalidArgumentException("Cannot create a glyph of {$width}x{$height} pixels - width and " .
                                               "height must be from 1 to 65535!");
        }
        if ($marginTop < 0 || $marginTop > 0xFFFF || $expandCount < 0 || $expandCount > 255) {
            throw new InvalidArgumentException("Cannot create a glyph with margin $marginTop and expand count " .
                                               "$expandCount - the margin must be from 0 to 65535 and the " .
                                               "expand count from 0 to 255!");
        }
        if (strlen($text) > 255 || str_contains($text, "\0")) {
            throw new InvalidArgumentException("Cannot create a glyph with text '$text' - the text must have at " .
                                               "most 255 UTF-8 bytes and no NUL character!");
        }
        foreach ([$foregroundLines, $backgroundLines] as $lines) {
            if (count($lines) > 0xFFFF) {
                throw new InvalidArgumentException("Cannot create a glyph with more than 65535 lines of one kind!");
            }
            foreach ($lines as $line) {
                if (!is_array($line) || count($line) !== 4 || !array_is_list($line)) {
                    throw new InvalidArgumentException("Cannot create a glyph - every line must be [x1, y1, x2, y2]!");
                }
                foreach ($line as $value) {
                    if (!is_int($value) || $value < 0 || $value > 0xFFFF) {
                        throw new InvalidArgumentException("Cannot create a glyph - every line coordinate must be " .
                                                           "an integer from 0 to 65535!");
                    }
                }
            }
        }
    }


    /**
     * Creates a glyph without the argument checks, for the database reader, which checks its input itself.
     *
     * @param list<array{int, int, int, int}> $foregroundLines
     * @param list<array{int, int, int, int}> $backgroundLines
     *
     * @internal
     */
    public static function trusted(string $text, int $width, int $height, int $marginTop, bool $italic,
                                   int $expandCount, array $foregroundLines, array $backgroundLines): self
    {
        $glyph = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $glyph->text = $text;
        $glyph->width = $width;
        $glyph->height = $height;
        $glyph->marginTop = $marginTop;
        $glyph->italic = $italic;
        $glyph->expandCount = $expandCount;
        $glyph->foregroundLines = $foregroundLines;
        $glyph->backgroundLines = $backgroundLines;

        return $glyph;
    }


    /**
     * Returns the height as a percentage of the width, which the matcher uses as a coarse shape filter.
     */
    public function heightToWidthPercent(): float
    {
        return $this->height * 100.0 / $this->width;
    }


    /**
     * Returns true for characters that look alike in many fonts, which the matcher checks with a tighter budget.
     */
    public function isSensitive(): bool
    {
        return in_array($this->text, ['O', 'o', '0', "'", '-', ':', '"'], true);
    }


    public function lineCount(): int
    {
        return count($this->foregroundLines) + count($this->backgroundLines);
    }
}
