<?php

namespace GlyphOcr\Internal;

/**
 * LineHeightTracker averages the glyph heights of the images seen so far, so the splitter gets a minimum line
 * height that fits the font size. Port of OcrLineHeightTracker of Subtitle Edit.
 *
 * @internal
 */
final class LineHeightTracker
{
    private const MAX_SAMPLES = 1000;

    private int $total = 0;
    private int $count = 0;


    /**
     * @param int $minSamples the glyphs needed before the measured heights replace the fallback
     */
    public function __construct(private readonly int $fallbackMinLineHeight, private readonly int $minSamples = 21)
    {
    }


    /**
     * @param list<SplitItem> $items
     */
    public function update(array $items): void
    {
        if ($this->count >= self::MAX_SAMPLES) {
            return;
        }
        foreach ($items as $item) {
            if ($item->bitmap !== null) {
                $this->total += $item->bitmap->height;
                $this->count++;
            }
        }
    }


    public function isWarm(): bool
    {
        return $this->count >= $this->minSamples;
    }


    public function minLineHeight(): int
    {
        return $this->isWarm() ? Rounding::halfToEven($this->total / $this->count * 0.9)
            : $this->fallbackMinLineHeight;
    }


    public function averageLineHeight(): float
    {
        return $this->isWarm() ? $this->total / $this->count : -1.0;
    }
}
