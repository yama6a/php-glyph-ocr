<?php

namespace GlyphOcr\Tests\Golden;

use GlyphOcr\GlyphDatabase;
use GlyphOcr\Image;
use GlyphOcr\Recognizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reads every golden image with each shipped database and checks the character accuracy of each set.
 */
class GoldenTest extends TestCase
{
    /**
     * Minimum character accuracy per database and set, about 1.5 points under the measured value. The Latin
     * database of Subtitle Edit has no glyphs of these fonts, so its numbers show how it does on fonts it has
     * not seen. The subtitle fonts database has no glyphs of Open Sans, the font of the unseen set.
     */
    private const MIN_CHARACTER_ACCURACY = [
        'latin'         => [
            'bluray'    => 0.92,
            'capital-i' => 0.88,
            'dvd'       => 0.62,
            'italic'    => 0.90,
            'pgs'       => 0.91,
            'plain'     => 0.78,
            'small'     => 0.48,
            'unseen'    => 0.83,
        ],
        'subtitleFonts' => [
            'bluray'    => 0.97,
            'capital-i' => 0.97,
            'dvd'       => 0.94,
            'italic'    => 0.96,
            'pgs'       => 0.98,
            'plain'     => 0.97,
            'small'     => 0.96,
            'unseen'    => 0.95,
        ],
    ];


    /**
     * @return array<string, array{string, string}>
     */
    public static function sets(): array
    {
        $sets = [];
        foreach (self::MIN_CHARACTER_ACCURACY as $database => $minimums) {
            foreach (array_keys($minimums) as $set) {
                $sets["$database $set"] = [$database, $set];
            }
        }

        return $sets;
    }


    #[DataProvider('sets')]
    public function testCharacterAccuracyOfTheSetReachesItsMinimum(string $database, string $set): void
    {
        $files = glob(dirname(__DIR__) . "/fixtures/$set/*.png");
        $this->assertNotEmpty($files);

        // One recognizer per set, as for the images of one subtitle stream.
        $recognizer = new Recognizer(self::database($database));
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
        $this->assertGreaterThanOrEqual(self::MIN_CHARACTER_ACCURACY[$database][$set], $accuracy,
                                        sprintf("%s: %.1f%%\n%s", $set, $accuracy * 100, implode("\n", $report)));
    }


    public function testEveryImageHasItsTextFile(): void
    {
        foreach (glob(dirname(__DIR__) . '/fixtures/*/*.png') as $file) {
            $this->assertFileExists(substr($file, 0, -4) . '.txt');
        }
    }


    private static function database(string $name): GlyphDatabase
    {
        static $databases = [];

        return $databases[$name] ??= GlyphDatabase::$name();
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
