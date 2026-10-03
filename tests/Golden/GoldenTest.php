<?php

namespace GlyphOcr\Tests\Golden;

use GlyphOcr\GlyphDatabase;
use GlyphOcr\Image;
use GlyphOcr\Recognizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reads every golden image with the shipped Latin database and checks the character accuracy of each set.
 */
class GoldenTest extends TestCase
{
    /**
     * Minimum character accuracy per set, about 1.5 points under the measured value. The Latin database of
     * Subtitle Edit has no glyphs of these fonts, so the numbers show how it does on fonts it has not seen.
     */
    private const MIN_CHARACTER_ACCURACY = [
        'bluray'    => 0.88,
        'capital-i' => 0.80,
        'dvd'       => 0.60,
        'italic'    => 0.80,
        'pgs'       => 0.88,
        'plain'     => 0.75,
        'small'     => 0.44,
        'unseen'    => 0.80,
    ];


    /**
     * @return array<string, array{string}>
     */
    public static function sets(): array
    {
        $sets = [];
        foreach (array_keys(self::MIN_CHARACTER_ACCURACY) as $set) {
            $sets[$set] = [$set];
        }

        return $sets;
    }


    #[DataProvider('sets')]
    public function testCharacterAccuracyOfTheSetReachesItsMinimum(string $set): void
    {
        $files = glob(dirname(__DIR__) . "/fixtures/$set/*.png");
        $this->assertNotEmpty($files);

        // One recognizer per set, as for the images of one subtitle stream.
        $recognizer = new Recognizer(self::latin());
        $characters = 0;
        $errors = 0;
        $report = [];
        foreach ($files as $file) {
            $expected = rtrim((string)file_get_contents(substr($file, 0, -4) . '.txt'), "\n");
            $actual = $recognizer->recognize(Image::fromPng((string)file_get_contents($file)))->text();
            $distance = self::distance($expected, $actual);
            $characters += mb_strlen($expected);
            $errors += $distance;
            $report[] = basename($file) . ": $distance wrong, read '" . str_replace("\n", ' / ', $actual) . "'";
        }

        $accuracy = 1 - $errors / $characters;
        $this->assertGreaterThanOrEqual(self::MIN_CHARACTER_ACCURACY[$set], $accuracy,
                                        sprintf("%s: %.1f%%\n%s", $set, $accuracy * 100, implode("\n", $report)));
    }


    public function testEveryImageHasItsTextFile(): void
    {
        foreach (glob(dirname(__DIR__) . '/fixtures/*/*.png') as $file) {
            $this->assertFileExists(substr($file, 0, -4) . '.txt');
        }
    }


    private static function latin(): GlyphDatabase
    {
        static $database = null;

        return $database ??= GlyphDatabase::latin();
    }


    /**
     * Returns the Levenshtein distance in characters.
     */
    private static function distance(string $a, string $b): int
    {
        $left = mb_str_split($a);
        $right = mb_str_split($b);
        $previous = range(0, count($right));
        foreach ($left as $i => $l) {
            $current = [$i + 1];
            foreach ($right as $j => $r) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($l === $r ? 0 : 1));
            }
            $previous = $current;
        }

        return $previous[count($right)];
    }
}
