<?php

namespace GlyphOcr\Tests\Fixtures\Generator;

/**
 * PngWriter writes PNG files with its own deflate encoder, so the bytes do not depend on the zlib build.
 * The encoder uses greedy LZ77 matching and the fixed Huffman codes of RFC 1951.
 */
final class PngWriter
{
    /**
     * @param string $rgba width * height * 4 bytes
     */
    public static function rgba(int $width, int $height, string $rgba): string
    {
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0" . substr($rgba, $y * $width * 4, $width * 4);
        }

        return self::file($width, $height, 6, '', '', $raw);
    }


    /**
     * @param list<string> $palette 4-byte RGBA entries
     * @param string $indexes width * height palette indexes, one byte each
     */
    public static function palette(int $width, int $height, array $palette, string $indexes): string
    {
        $plte = '';
        $trns = '';
        foreach ($palette as $entry) {
            $plte .= substr($entry, 0, 3);
            $trns .= $entry[3];
        }
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0" . substr($indexes, $y * $width, $width);
        }

        return self::file($width, $height, 3, $plte, rtrim($trns, "\xFF"), $raw);
    }


    private static function file(int $width, int $height, int $colorType, string $plte, string $trns,
                                 string $raw): string
    {
        $png = "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, $colorType, 0, 0, 0));
        if ($plte !== '') {
            $png .= self::chunk('PLTE', $plte);
        }
        if ($trns !== '') {
            $png .= self::chunk('tRNS', $trns);
        }
        $zlib = "\x78\x01" . self::deflate($raw) . pack('N', hexdec(hash('adler32', $raw)));

        return $png . self::chunk('IDAT', $zlib) . self::chunk('IEND', '');
    }


    private static function chunk(string $type, string $body): string
    {
        return pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
    }


    private static function deflate(string $data): string
    {
        $writer = new BitWriter();
        $writer->write(1, 1);
        $writer->write(1, 2);

        $length = strlen($data);
        $heads = [];
        $i = 0;
        while ($i < $length) {
            $bestLength = 0;
            $bestDistance = 0;
            if ($i + 2 < $length) {
                $key = substr($data, $i, 3);
                $candidate = $heads[$key] ?? null;
                if ($candidate !== null && $i - $candidate <= 32768) {
                    $max = min(258, $length - $i);
                    $matched = 0;
                    while ($matched < $max && $data[$candidate + $matched] === $data[$i + $matched]) {
                        $matched++;
                    }
                    if ($matched >= 3) {
                        $bestLength = $matched;
                        $bestDistance = $i - $candidate;
                    }
                }
            }

            if ($bestLength >= 3) {
                self::writeLength($writer, $bestLength);
                self::writeDistance($writer, $bestDistance);
                $end = $i + $bestLength;
                for (; $i < $end; $i++) {
                    if ($i + 2 < $length) {
                        $heads[substr($data, $i, 3)] = $i;
                    }
                }
                continue;
            }

            self::writeLiteral($writer, ord($data[$i]));
            if ($i + 2 < $length) {
                $heads[substr($data, $i, 3)] = $i;
            }
            $i++;
        }
        self::writeLiteral($writer, 256);

        return $writer->finish();
    }


    private static function writeLiteral(BitWriter $writer, int $symbol): void
    {
        if ($symbol < 144) {
            $writer->writeReversed(0x30 + $symbol, 8);
        } elseif ($symbol < 256) {
            $writer->writeReversed(0x190 + $symbol - 144, 9);
        } elseif ($symbol < 280) {
            $writer->writeReversed($symbol - 256, 7);
        } else {
            $writer->writeReversed(0xC0 + $symbol - 280, 8);
        }
    }


    private static function writeLength(BitWriter $writer, int $length): void
    {
        $bases = [3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 19, 23, 27, 31, 35, 43, 51, 59, 67, 83, 99, 115, 131, 163,
                  195, 227, 258];
        $extra = [0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0];
        for ($code = 28; $code >= 0; $code--) {
            if ($length >= $bases[$code]) {
                break;
            }
        }
        self::writeLiteral($writer, 257 + $code);
        $writer->write($length - $bases[$code], $extra[$code]);
    }


    private static function writeDistance(BitWriter $writer, int $distance): void
    {
        $bases = [1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193, 257, 385, 513, 769, 1025, 1537, 2049,
                  3073, 4097, 6145, 8193, 12289, 16385, 24577];
        $extra = [0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13];
        for ($code = 29; $code >= 0; $code--) {
            if ($distance >= $bases[$code]) {
                break;
            }
        }
        $writer->writeReversed($code, 5);
        $writer->write($distance - $bases[$code], $extra[$code]);
    }
}

