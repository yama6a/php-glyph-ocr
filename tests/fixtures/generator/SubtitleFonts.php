<?php

namespace GlyphOcr\Tests\Fixtures\Generator;

use GlyphOcr\Exceptions\InvalidArgumentException;
use GlyphOcr\Glyph;
use GlyphOcr\GlyphDatabase;
use GlyphOcr\GlyphSample;
use GlyphOcr\Image;
use GlyphOcr\Recognizer;
use GlyphOcr\Trainer;

/**
 * SubtitleFonts trains the glyphs of resources/SubtitleFonts.nocr.
 */
final class SubtitleFonts
{
    public const FONTS = [
        'DejaVuSans.ttf',
        'DejaVuSans-Oblique.ttf',
        'LiberationSans-Regular.ttf',
        'LiberationSans-Italic.ttf',
        'NotoSans-Regular.ttf',
        'NotoSans-Italic.ttf',
    ];

    /** The matcher scales a glyph to the image glyph, so two sizes cover 20 to 60 pixels. */
    public const SIZES = [28, 48];

    /**
     * The inverted question and exclamation marks and the slash are left out, because they match i and italic l
     * more often than themselves.
     */
    public const CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.,!?'-:;\"()&%" .
                         "àáâäçèéêëíîïñòóôöùúûüßÀÉÈÇÄÖÜÑÁÍÓÚ";


    /**
     * Returns the glyphs of one font at one size, in the order of CHARS. Each block has its own trainer seed,
     * so a test can rebuild one block alone.
     *
     * @return list<Glyph>
     */
    public static function block(string $font, int $size): array
    {
        $seed = array_search($font, self::FONTS, true) * count(self::SIZES) + array_search($size, self::SIZES, true);
        $trainer = new Trainer(seed: $seed);
        $italic = preg_match('/Italic|Oblique/', $font) === 1;
        $glyphs = [];
        foreach (mb_str_split(self::CHARS) as $char) {
            $glyphs[] = $trainer->train(self::sample($font, $size, $char), $char, $italic);
        }

        return $glyphs;
    }


    public static function database(): GlyphDatabase
    {
        $glyphs = [];
        foreach (self::FONTS as $font) {
            foreach (self::SIZES as $size) {
                $glyphs = [...$glyphs, ...self::block($font, $size)];
            }
        }

        return new GlyphDatabase($glyphs);
    }


    /**
     * Draws "Hd" and the character with a wide gap, and cuts the character out. "Hd" sets the line top, which
     * the glyph keeps as its top margin.
     */
    private static function sample(string $font, int $size, string $char): GlyphSample
    {
        $png = Fixtures::render(Fixtures::styled('bluray', ["Hd    $char"], $font, $size));
        $lines = (new Recognizer(new GlyphDatabase()))->split(Image::fromPng($png));
        if (count($lines) !== 1) {
            throw new InvalidArgumentException("Cannot train '$char' of $font at $size px - it splits into " .
                                               count($lines) . " lines!");
        }

        $samples = array_values(array_filter($lines[0]));
        usort($samples, fn(GlyphSample $a, GlyphSample $b): int => $a->x <=> $b->x);
        $cut = 0;
        $widestGap = -1;
        $right = PHP_INT_MIN;
        foreach ($samples as $index => $sample) {
            if ($index > 0 && $sample->x - $right > $widestGap) {
                $widestGap = $sample->x - $right;
                $cut = $index;
            }
            $right = max($right, $sample->x + $sample->width);
        }

        return GlyphSample::merge(array_slice($samples, $cut));
    }
}
