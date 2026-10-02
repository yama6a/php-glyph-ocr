<?php

namespace GlyphOcr;

use GlyphOcr\Exceptions\InvalidArgumentException;
use GlyphOcr\Exceptions\InvalidDatabaseException;

/**
 * GlyphDatabase holds the glyphs that the recognizer compares against. It reads and writes the `.nocr` file
 * format of Subtitle Edit, version 1 and version 2.
 *
 * The matcher prefers earlier glyphs when two match equally well, so add() puts a new glyph first.
 */
final class GlyphDatabase
{
    private const VERSION_2 = 'V2';

    /** @var list<Glyph> */
    private array $singles = [];

    /** @var list<Glyph> */
    private array $expanded = [];

    private int $revision = 0;


    /**
     * @param list<Glyph> $glyphs
     */
    public function __construct(array $glyphs = [])
    {
        foreach ($glyphs as $glyph) {
            if (!$glyph instanceof Glyph) {
                throw new InvalidArgumentException("Cannot create a glyph database - every entry must be a Glyph!");
            }
            if ($glyph->expandCount > 0) {
                $this->expanded[] = $glyph;
            } else {
                $this->singles[] = $glyph;
            }
        }
    }


    /**
     * Loads the Latin database that ships with this package. It comes from Subtitle Edit.
     */
    public static function latin(): self
    {
        return self::fromFile(dirname(__DIR__) . '/resources/Latin.nocr');
    }


    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidDatabaseException("Cannot read the glyph database '$path' - the file does not exist " .
                                               "or is not readable!");
        }

        return self::fromBytes((string)file_get_contents($path));
    }


    /**
     * Reads the bytes of a `.nocr` file: gzip-compressed glyph records, with a "V2" prefix for version 2.
     */
    public static function fromBytes(string $bytes): self
    {
        $data = @gzdecode($bytes);
        if ($data === false) {
            throw new InvalidDatabaseException("Cannot read the glyph database - the data is not gzip-compressed!");
        }

        $isVersion2 = str_starts_with($data, self::VERSION_2);
        $position = $isVersion2 ? 2 : 0;
        $glyphs = [];
        $length = strlen($data);
        // Subtitle Edit stops at the first record that does not load, and so does this reader.
        while (true) {
            $glyph = $isVersion2 ? self::readVersion2($data, $position, $length)
                : self::readVersion1($data, $position, $length);
            if ($glyph === null) {
                break;
            }
            $glyphs[] = $glyph;
        }

        return new self($glyphs);
    }


    /**
     * Returns the bytes of a version 2 `.nocr` file.
     */
    public function toBytes(): string
    {
        $out = self::VERSION_2;
        foreach ([...$this->singles, ...$this->expanded] as $glyph) {
            $out .= self::encode($glyph);
        }

        return (string)gzencode($out, 9);
    }


    public function save(string $path): void
    {
        $temporary = $path . '.tmp';
        if (@file_put_contents($temporary, $this->toBytes()) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new InvalidDatabaseException("Cannot write the glyph database to '$path'!");
        }
    }


    /**
     * Puts the glyph first, so it wins over older glyphs that match equally well.
     */
    public function add(Glyph $glyph): void
    {
        if ($glyph->expandCount > 0) {
            array_unshift($this->expanded, $glyph);
        } else {
            array_unshift($this->singles, $glyph);
        }
        $this->revision++;
    }


    public function remove(Glyph $glyph): void
    {
        $this->singles = array_values(array_filter($this->singles, fn(Glyph $g): bool => $g !== $glyph));
        $this->expanded = array_values(array_filter($this->expanded, fn(Glyph $g): bool => $g !== $glyph));
        $this->revision++;
    }


    /**
     * Returns the glyphs of one part first, then the glyphs that span several parts.
     *
     * @return list<Glyph>
     */
    public function glyphs(): array
    {
        return [...$this->singles, ...$this->expanded];
    }


    /**
     * @return list<Glyph>
     *
     * @internal
     */
    public function singleGlyphs(): array
    {
        return $this->singles;
    }


    /**
     * @return list<Glyph>
     *
     * @internal
     */
    public function expandedGlyphs(): array
    {
        return $this->expanded;
    }


    /**
     * Changes on every add() and remove(), so the recognizer knows when to drop its caches.
     *
     * @internal
     */
    public function revision(): int
    {
        return $this->revision;
    }


    public function count(): int
    {
        return count($this->singles) + count($this->expanded);
    }


    private static function readVersion2(string $data, int &$position, int $length): ?Glyph
    {
        if ($position + 4 >= $length) {
            return null;
        }

        $flags = ord($data[$position]);
        $isShort = ($flags & 0x10) !== 0;
        $italic = ($flags & 0x20) !== 0;
        if ($isShort) {
            $header = 4;
            if ($position + $header + 1 > $length) {
                return null;
            }
            $expandCount = $flags & 0x0F;
            $width = ord($data[$position + 1]);
            $height = ord($data[$position + 2]);
            $marginTop = ord($data[$position + 3]);
        } else {
            $header = 8;
            if ($position + $header + 1 > $length) {
                return null;
            }
            $expandCount = ord($data[$position + 1]);
            [, $width, $height, $marginTop] = unpack('n3', $data, $position + 2);
        }
        $cursor = $position + $header;
        $textLength = ord($data[$cursor++]);
        $text = substr($data, $cursor, $textLength);
        $cursor += $textLength;

        $foreground = self::readLines($data, $cursor, $length, $isShort);
        $background = $foreground === null ? null : self::readLines($data, $cursor, $length, $isShort);
        if ($background === null || !self::isValid($width, $height, $text)) {
            return null;
        }
        $position = $cursor;

        return Glyph::trusted($text, $width, $height, $marginTop, $italic, $expandCount, $foreground, $background);
    }


    private static function readVersion1(string $data, int &$position, int $length): ?Glyph
    {
        if ($position + 9 > $length) {
            return null;
        }

        [, $width, $height, $marginTop] = unpack('n3', $data, $position);
        $italic = ord($data[$position + 6]) !== 0;
        $expandCount = ord($data[$position + 7]);
        $textLength = ord($data[$position + 8]);
        $cursor = $position + 9;
        $text = substr($data, $cursor, $textLength);
        $cursor += $textLength;

        $foreground = self::readLines($data, $cursor, $length, false);
        $background = $foreground === null ? null : self::readLines($data, $cursor, $length, false);
        if ($background === null || !self::isValid($width, $height, $text)) {
            return null;
        }
        $position = $cursor;

        return Glyph::trusted($text, $width, $height, $marginTop, $italic, $expandCount, $foreground, $background);
    }


    /**
     * @return list<array{int, int, int, int}>|null
     */
    private static function readLines(string $data, int &$cursor, int $length, bool $isShort): ?array
    {
        if ($isShort) {
            if ($cursor + 1 > $length) {
                return null;
            }
            $count = ord($data[$cursor]);
            $cursor++;
            if ($cursor + $count * 4 > $length) {
                return null;
            }
            $values = $count > 0 ? array_values(unpack("C" . ($count * 4), $data, $cursor)) : [];
            $cursor += $count * 4;
        } else {
            if ($cursor + 2 > $length) {
                return null;
            }
            $count = unpack('n', $data, $cursor)[1];
            $cursor += 2;
            if ($cursor + $count * 8 > $length) {
                return null;
            }
            $values = $count > 0 ? array_values(unpack("n" . ($count * 4), $data, $cursor)) : [];
            $cursor += $count * 8;
        }

        return array_chunk($values, 4);
    }


    private static function isValid(int $width, int $height, string $text): bool
    {
        return $width > 0 && $height > 0 && $width <= 1920 && $height <= 1080 && !str_contains($text, "\0");
    }


    private static function encode(Glyph $glyph): string
    {
        $lines = [...$glyph->foregroundLines, ...$glyph->backgroundLines];
        $short = $glyph->width <= 255 && $glyph->height <= 255 && $glyph->marginTop <= 255 &&
                 $glyph->expandCount < 16 && count($glyph->foregroundLines) <= 255 &&
                 count($glyph->backgroundLines) <= 255 && max([0, ...array_merge(...$lines ?: [[]])]) <= 255;

        $flags = $glyph->italic ? 0x20 : 0;
        if ($short) {
            $out = chr($flags | 0x10 | $glyph->expandCount) . chr($glyph->width) . chr($glyph->height) .
                   chr($glyph->marginTop);
        } else {
            $out = chr($flags) . chr($glyph->expandCount) . pack('n3', $glyph->width, $glyph->height, $glyph->marginTop);
        }
        $out .= chr(strlen($glyph->text)) . $glyph->text;
        foreach ([$glyph->foregroundLines, $glyph->backgroundLines] as $group) {
            $out .= $short ? chr(count($group)) : pack('n', count($group));
            foreach ($group as $line) {
                $out .= pack($short ? 'C4' : 'n4', ...$line);
            }
        }

        return $out;
    }
}
