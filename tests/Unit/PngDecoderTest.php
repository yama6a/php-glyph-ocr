<?php

namespace GlyphOcr\Tests\Unit;

use GlyphOcr\Exceptions\GlyphOcrException;
use GlyphOcr\Exceptions\InvalidImageException;
use GlyphOcr\Image;
use GlyphOcr\Png\PngDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

class PngDecoderTest extends TestCase
{
    private const WIDTH = 5;
    private const HEIGHT = 4;


    /**
     * Returns test pixels as [r, g, b, a] at 16 bits per channel, so every bit depth can be derived from them.
     *
     * @return list<array{int, int, int, int}>
     */
    private static function pixels16(): array
    {
        $pixels = [];
        for ($i = 0; $i < self::WIDTH * self::HEIGHT; $i++) {
            $pixels[] = [($i * 3301) % 65536, ($i * 7919 + 100) % 65536, ($i * 104729 + 7) % 65536,
                         $i % 3 === 0 ? 65535 : ($i * 4099) % 65536];
        }

        return $pixels;
    }


    /**
     * @return array<string, array{int, int, bool, int}>
     */
    public static function formats(): array
    {
        $cases = [];
        foreach ([[0, [1, 2, 4, 8, 16]], [2, [8, 16]], [3, [1, 2, 4, 8]], [4, [8, 16]], [6, [8, 16]]] as [$type, $depths]) {
            foreach ($depths as $depth) {
                foreach ([false, true] as $interlaced) {
                    foreach ([0, 1, 2, 3, 4] as $filter) {
                        $name = "type $type depth $depth" . ($interlaced ? ' interlaced' : '') . " filter $filter";
                        $cases[$name] = [$type, $depth, $interlaced, $filter];
                    }
                }
            }
        }

        return $cases;
    }


    #[DataProvider('formats')]
    public function testDecodesEveryColourTypeBitDepthFilterAndInterlace(int $type, int $depth, bool $interlaced,
                                                                          int $filter): void
    {
        [$png, $expected] = self::buildPng($type, $depth, $interlaced, $filter, false);

        $image = Image::fromPng($png);

        $this->assertSame(self::WIDTH, $image->width);
        $this->assertSame(self::HEIGHT, $image->height);
        $this->assertSame(bin2hex($expected), bin2hex($image->rgba));
    }


    /**
     * @return array<string, array{int, int}>
     */
    public static function transparencyFormats(): array
    {
        return [
            'grey 8'     => [0, 8],
            'grey 16'    => [0, 16],
            'rgb 8'      => [2, 8],
            'rgb 16'     => [2, 16],
            'palette 8'  => [3, 8],
            'palette 2'  => [3, 2],
        ];
    }


    #[DataProvider('transparencyFormats')]
    public function testAppliesTheTransparencyChunk(int $type, int $depth): void
    {
        [$png, $expected] = self::buildPng($type, $depth, false, 0, true);

        $this->assertSame(bin2hex($expected), bin2hex(Image::fromPng($png)->rgba));
    }


    public function testRejectsAMissingSignature(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('signature');
        PngDecoder::decode('not a png');
    }


    public function testRejectsAWrongCrc(): void
    {
        [$png] = self::buildPng(6, 8, false, 0, false);
        $png[29] = chr(ord($png[29]) ^ 0xFF);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('CRC');
        PngDecoder::decode($png);
    }


    public function testRejectsTruncatedData(): void
    {
        [$png] = self::buildPng(6, 8, false, 0, false);

        $this->expectException(GlyphOcrException::class);
        PngDecoder::decode(substr($png, 0, 50));
    }


    public function testRejectsAPaletteImageWithoutPalette(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NNC5', 1, 1, 8, 3, 0, 0, 0)) .
               self::chunk('IDAT', (string)gzcompress("\0\0")) . self::chunk('IEND', '');

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('PLTE');
        PngDecoder::decode($png);
    }


    public function testRejectsAnInvalidBitDepth(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NNC5', 1, 1, 4, 2, 0, 0, 0)) .
               self::chunk('IEND', '');

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('bit depth 4');
        PngDecoder::decode($png);
    }


    public function testRejectsAnUnknownFilter(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NNC5', 1, 1, 8, 0, 0, 0, 0)) .
               self::chunk('IDAT', (string)gzcompress("\x07\x00")) . self::chunk('IEND', '');

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('unknown filter 7');
        PngDecoder::decode($png);
    }


    public function testSkipsAncillaryChunks(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NNC5', 1, 1, 8, 6, 0, 0, 0)) .
               self::chunk('tEXt', "Comment\0hello") . self::chunk('IDAT', (string)gzcompress("\0\x01\x02\x03\x04")) .
               self::chunk('IEND', '');

        $this->assertSame("\x01\x02\x03\x04", PngDecoder::decode($png)['rgba']);
    }


    #[RequiresPhpExtension('gd')]
    public function testMatchesGdOnTheGoldenImages(): void
    {
        $files = glob(dirname(__DIR__) . '/fixtures/*/*.png');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $png = (string)file_get_contents($file);
            $ours = Image::fromPng($png);
            $gd = Image::fromGd(imagecreatefromstring($png));
            for ($i = 0; $i < strlen($ours->rgba); $i += 4) {
                // GD keeps 7 bits of alpha, so it can be off by up to 2 and has no colour for alpha 0.
                $alpha = ord($ours->rgba[$i + 3]);
                $this->assertEqualsWithDelta($alpha, ord($gd->rgba[$i + 3]), 2, basename($file));
                if ($alpha > 0) {
                    $this->assertSame(substr($ours->rgba, $i, 3), substr($gd->rgba, $i, 3), basename($file));
                }
            }
        }
    }


    /**
     * Builds a PNG from the test pixels and returns it with the RGBA bytes a decoder must produce.
     *
     * @return array{string, string}
     */
    private static function buildPng(int $type, int $depth, bool $interlaced, int $filter, bool $transparency): array
    {
        $max = (1 << $depth) - 1;
        $palette = [];
        $samples = [];
        $expected = [];
        foreach (self::pixels16() as $i => [$r, $g, $b, $a]) {
            $scale = fn(int $v): int => intdiv($v * $max + 32767, 65535);
            $to8 = fn(int $v): int => $depth === 16 ? $v >> 8 : intdiv($v * 255, $max);
            switch ($type) {
                case 0:
                    $grey = $scale($r);
                    $samples[] = [$grey];
                    $expected[] = [$to8($grey), $to8($grey), $to8($grey), 255];
                    break;
                case 2:
                    $samples[] = [$scale($r), $scale($g), $scale($b)];
                    $expected[] = [$to8($scale($r)), $to8($scale($g)), $to8($scale($b)), 255];
                    break;
                case 3:
                    $index = $i % ($max + 1);
                    $palette[$index] = [$index * 40 % 256, $index * 90 % 256, $index * 13 % 256, 255 - $index * 7];
                    $samples[] = [$index];
                    $expected[] = $index;
                    break;
                case 4:
                    $samples[] = [$scale($r), $scale($a)];
                    $expected[] = [$to8($scale($r)), $to8($scale($r)), $to8($scale($r)), $to8($scale($a))];
                    break;
                default:
                    $samples[] = [$scale($r), $scale($g), $scale($b), $scale($a)];
                    $expected[] = [$to8($scale($r)), $to8($scale($g)), $to8($scale($b)), $to8($scale($a))];
            }
        }

        $chunks = '';
        $trns = null;
        if ($type === 3) {
            ksort($palette);
            $plte = '';
            $alphas = '';
            foreach ($palette as [$pr, $pg, $pb, $pa]) {
                $plte .= pack('C3', $pr, $pg, $pb);
                $alphas .= chr($pa);
            }
            $chunks .= self::chunk('PLTE', $plte);
            if ($transparency) {
                $trns = $alphas;
            }
            $expected = array_map(fn(int $index): array => [
                $palette[$index][0], $palette[$index][1], $palette[$index][2],
                $transparency ? $palette[$index][3] : 255,
            ], $expected);
        } elseif ($transparency) {
            $key = $samples[4];
            $trns = $depth === 16 || $type === 0 || $type === 2 ? pack('n*', ...$key) : null;
            foreach ($samples as $i => $sample) {
                if ($sample === $key) {
                    $expected[$i][3] = 0;
                }
            }
        }
        if ($trns !== null) {
            $chunks .= self::chunk('tRNS', $trns);
        }

        $raw = '';
        $passes = $interlaced
            ? [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]]
            : [[0, 0, 1, 1]];
        $channels = count($samples[0]);
        $bytesPerPixel = max(1, intdiv($channels * $depth, 8));
        foreach ($passes as [$x0, $y0, $dx, $dy]) {
            $previous = null;
            for ($y = $y0; $y < self::HEIGHT; $y += $dy) {
                $bits = '';
                $row = [];
                for ($x = $x0; $x < self::WIDTH; $x += $dx) {
                    foreach ($samples[$y * self::WIDTH + $x] as $value) {
                        if ($depth === 16) {
                            $row[] = $value >> 8;
                            $row[] = $value & 0xFF;
                        } elseif ($depth === 8) {
                            $row[] = $value;
                        } else {
                            $bits .= str_pad(decbin($value), $depth, '0', STR_PAD_LEFT);
                        }
                    }
                }
                if ($depth < 8) {
                    if ($bits === '') {
                        continue;
                    }
                    $bits = str_pad($bits, (int)ceil(strlen($bits) / 8) * 8, '0');
                    $row = array_map('bindec', str_split($bits, 8));
                }
                if ($row === []) {
                    continue;
                }
                $raw .= chr($filter) . pack('C*', ...self::filterRow($row, $previous, $filter, $bytesPerPixel));
                $previous = $row;
            }
        }

        $png = "\x89PNG\r\n\x1a\n" .
               self::chunk('IHDR', pack('NNC5', self::WIDTH, self::HEIGHT, $depth, $type, 0, 0, $interlaced ? 1 : 0)) .
               $chunks . self::chunk('IDAT', (string)gzcompress($raw)) . self::chunk('IEND', '');
        $rgba = '';
        foreach ($expected as $pixel) {
            $rgba .= pack('C4', ...$pixel);
        }

        return [$png, $rgba];
    }


    /**
     * @param list<int> $row
     * @param list<int>|null $previous
     * @return list<int>
     */
    private static function filterRow(array $row, ?array $previous, int $filter, int $bpp): array
    {
        $previous ??= array_fill(0, count($row), 0);
        $out = [];
        foreach ($row as $i => $value) {
            $a = $i >= $bpp ? $row[$i - $bpp] : 0;
            $b = $previous[$i];
            $c = $i >= $bpp ? $previous[$i - $bpp] : 0;
            $predictor = match ($filter) {
                0 => 0,
                1 => $a,
                2 => $b,
                3 => ($a + $b) >> 1,
                4 => self::paeth($a, $b, $c),
            };
            $out[] = ($value - $predictor) & 0xFF;
        }

        return $out;
    }


    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }


    private static function chunk(string $type, string $body): string
    {
        return pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
    }
}
