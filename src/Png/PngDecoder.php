<?php

namespace GlyphOcr\Png;

use GlyphOcr\Exceptions\InvalidImageException;

/**
 * PngDecoder decodes PNG files to 8-bit RGBA without ext-gd.
 */
final class PngDecoder
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Channels per pixel for each PNG colour type. */
    private const CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    private const ALLOWED_DEPTHS = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]];

    /** Adam7 passes as [x start, y start, x step, y step]. */
    private const ADAM7 = [[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]];

    /** Refuses images above this pixel count, so a forged header cannot exhaust memory. */
    private const MAX_PIXELS = 40_000_000;


    /**
     * Returns the width, the height and the RGBA bytes with straight alpha.
     *
     * @return array{width: int, height: int, rgba: string}
     */
    public static function decode(string $png): array
    {
        if (!str_starts_with($png, self::SIGNATURE)) {
            throw new InvalidImageException("Cannot decode the PNG - the PNG signature is missing!");
        }

        $offset = 8;
        $length = strlen($png);
        $header = null;
        $palette = null;
        $transparency = null;
        $data = '';
        $ended = false;
        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', $png, $offset)[1];
            $type = substr($png, $offset + 4, 4);
            if ($offset + 12 + $chunkLength > $length) {
                throw new InvalidImageException("Cannot decode the PNG - the $type chunk is truncated!");
            }
            $body = substr($png, $offset + 8, $chunkLength);
            $crc = unpack('N', $png, $offset + 8 + $chunkLength)[1];
            if (crc32($type . $body) !== $crc) {
                throw new InvalidImageException("Cannot decode the PNG - the CRC of the $type chunk is wrong!");
            }
            $offset += 12 + $chunkLength;

            if ($type === 'IHDR') {
                $header = self::readHeader($body);
            } elseif ($type === 'PLTE') {
                $palette = $body;
            } elseif ($type === 'tRNS') {
                $transparency = $body;
            } elseif ($type === 'IDAT') {
                $data .= $body;
            } elseif ($type === 'IEND') {
                $ended = true;
                break;
            } elseif ($header === null) {
                throw new InvalidImageException("Cannot decode the PNG - the first chunk is $type, not IHDR!");
            } elseif ((ord($type[0]) & 0x20) === 0) {
                throw new InvalidImageException("Cannot decode the PNG - the critical chunk $type is unknown!");
            }
        }

        if ($header === null) {
            throw new InvalidImageException("Cannot decode the PNG - the IHDR chunk is missing!");
        }
        if (!$ended) {
            throw new InvalidImageException("Cannot decode the PNG - the IEND chunk is missing!");
        }
        if ($data === '') {
            throw new InvalidImageException("Cannot decode the PNG - the IDAT chunk is missing!");
        }
        if ($header['colorType'] === 3 && $palette === null) {
            throw new InvalidImageException("Cannot decode the PNG - a palette image needs a PLTE chunk!");
        }

        $raw = @gzuncompress($data);
        if ($raw === false) {
            throw new InvalidImageException("Cannot decode the PNG - the image data is not a valid zlib stream!");
        }

        $width = $header['width'];
        $height = $header['height'];
        $samples = self::unfilterAll($raw, $header);
        $rgba = self::toRgba($samples, $header, $palette, $transparency);

        return ['width' => $width, 'height' => $height, 'rgba' => $rgba];
    }


    /**
     * @return array{width: int, height: int, depth: int, colorType: int, interlaced: bool}
     */
    private static function readHeader(string $body): array
    {
        if (strlen($body) !== 13) {
            throw new InvalidImageException("Cannot decode the PNG - the IHDR chunk must have 13 bytes!");
        }
        $fields = unpack('Nwidth/Nheight/Cdepth/CcolorType/Ccompression/Cfilter/Cinterlace', $body);
        if ($fields['width'] < 1 || $fields['height'] < 1) {
            throw new InvalidImageException("Cannot decode the PNG - width and height must be at least 1!");
        }
        if ($fields['width'] * $fields['height'] > self::MAX_PIXELS) {
            throw new InvalidImageException("Cannot decode the PNG - {$fields['width']}x{$fields['height']} " .
                                            "pixels is more than the limit of " . self::MAX_PIXELS . "!");
        }
        if (!isset(self::ALLOWED_DEPTHS[$fields['colorType']]) ||
            !in_array($fields['depth'], self::ALLOWED_DEPTHS[$fields['colorType']], true)) {
            throw new InvalidImageException("Cannot decode the PNG - colour type {$fields['colorType']} with " .
                                            "bit depth {$fields['depth']} is not valid!");
        }
        if ($fields['compression'] !== 0 || $fields['filter'] !== 0 || $fields['interlace'] > 1) {
            throw new InvalidImageException("Cannot decode the PNG - the compression, filter or interlace " .
                                            "method is unknown!");
        }

        return [
            'width'      => $fields['width'],
            'height'     => $fields['height'],
            'depth'      => $fields['depth'],
            'colorType'  => $fields['colorType'],
            'interlaced' => $fields['interlace'] === 1,
        ];
    }


    /**
     * Returns one sample row string per image row: one byte per sample for depth 8 and lower,
     * two bytes per sample for depth 16.
     *
     * @param array{width: int, height: int, depth: int, colorType: int, interlaced: bool} $header
     * @return list<string>
     */
    private static function unfilterAll(string $raw, array $header): array
    {
        $channels = self::CHANNELS[$header['colorType']];
        $bitsPerPixel = $channels * $header['depth'];
        $bytesPerPixel = max(1, $bitsPerPixel >> 3);
        $width = $header['width'];
        $height = $header['height'];

        if (!$header['interlaced']) {
            $rows = self::unfilter($raw, 0, $width, $height, $bitsPerPixel, $bytesPerPixel);

            return array_map(fn(string $row): string => self::unpackRow($row, $width, $header), $rows);
        }

        $sampleBytes = $header['depth'] === 16 ? 2 * $channels : $channels;
        $image = array_fill(0, $height, str_repeat("\0", $width * $sampleBytes));
        $offset = 0;
        foreach (self::ADAM7 as [$x0, $y0, $dx, $dy]) {
            $passWidth = intdiv($width - $x0 + $dx - 1, $dx);
            $passHeight = intdiv($height - $y0 + $dy - 1, $dy);
            if ($passWidth <= 0 || $passHeight <= 0) {
                continue;
            }
            $rows = self::unfilter($raw, $offset, $passWidth, $passHeight, $bitsPerPixel, $bytesPerPixel);
            $offset += $passHeight * (1 + intdiv($passWidth * $bitsPerPixel + 7, 8));
            foreach ($rows as $passY => $row) {
                $samples = self::unpackRow($row, $passWidth, $header);
                $y = $y0 + $passY * $dy;
                $target = $image[$y];
                for ($passX = 0; $passX < $passWidth; $passX++) {
                    $target = substr_replace($target, substr($samples, $passX * $sampleBytes, $sampleBytes),
                                             ($x0 + $passX * $dx) * $sampleBytes, $sampleBytes);
                }
                $image[$y] = $target;
            }
        }

        return $image;
    }


    /**
     * @return list<string>
     */
    private static function unfilter(string $raw, int $offset, int $width, int $height, int $bitsPerPixel,
                                     int $bytesPerPixel): array
    {
        $stride = intdiv($width * $bitsPerPixel + 7, 8);
        if (strlen($raw) < $offset + $height * ($stride + 1)) {
            throw new InvalidImageException("Cannot decode the PNG - the image data is shorter than the image size!");
        }

        $rows = [];
        $previous = array_fill(0, $stride, 0);
        for ($y = 0; $y < $height; $y++) {
            $start = $offset + $y * ($stride + 1);
            $filter = ord($raw[$start]);
            $line = substr($raw, $start + 1, $stride);
            if ($filter === 0) {
                $rows[] = $line;
                $previous = array_values(unpack('C*', $line));
                continue;
            }
            $current = array_values(unpack('C*', $line));
            switch ($filter) {
                case 1:
                    for ($i = $bytesPerPixel; $i < $stride; $i++) {
                        $current[$i] = ($current[$i] + $current[$i - $bytesPerPixel]) & 0xFF;
                    }
                    break;
                case 2:
                    for ($i = 0; $i < $stride; $i++) {
                        $current[$i] = ($current[$i] + $previous[$i]) & 0xFF;
                    }
                    break;
                case 3:
                    for ($i = 0; $i < $stride; $i++) {
                        $left = $i >= $bytesPerPixel ? $current[$i - $bytesPerPixel] : 0;
                        $current[$i] = ($current[$i] + (($left + $previous[$i]) >> 1)) & 0xFF;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $stride; $i++) {
                        if ($i >= $bytesPerPixel) {
                            $a = $current[$i - $bytesPerPixel];
                            $c = $previous[$i - $bytesPerPixel];
                        } else {
                            $a = 0;
                            $c = 0;
                        }
                        $b = $previous[$i];
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);
                        $predictor = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $current[$i] = ($current[$i] + $predictor) & 0xFF;
                    }
                    break;
                default:
                    throw new InvalidImageException("Cannot decode the PNG - row $y uses the unknown filter $filter!");
            }
            $rows[] = pack('C*', ...$current);
            $previous = $current;
        }

        return $rows;
    }


    /**
     * Expands packed sub-byte samples to one byte per sample. Rows of depth 8 and 16 stay as they are.
     *
     * @param array{width: int, height: int, depth: int, colorType: int, interlaced: bool} $header
     */
    private static function unpackRow(string $row, int $width, array $header): string
    {
        $depth = $header['depth'];
        if ($depth >= 8) {
            return $row;
        }

        $perByte = intdiv(8, $depth);
        $mask = (1 << $depth) - 1;
        $out = '';
        $count = 0;
        $bytes = strlen($row);
        for ($i = 0; $i < $bytes && $count < $width; $i++) {
            $byte = ord($row[$i]);
            for ($k = 0; $k < $perByte && $count < $width; $k++, $count++) {
                $out .= chr(($byte >> (8 - $depth * ($k + 1))) & $mask);
            }
        }

        return $out;
    }


    /**
     * @param list<string> $rows
     * @param array{width: int, height: int, depth: int, colorType: int, interlaced: bool} $header
     */
    private static function toRgba(array $rows, array $header, ?string $palette, ?string $transparency): string
    {
        $depth = $header['depth'];
        $colorType = $header['colorType'];
        $width = $header['width'];

        if ($depth === 16) {
            $transparentKey = null;
            if ($transparency !== null && ($colorType === 0 || $colorType === 2)) {
                $transparentKey = $transparency;
            }
            $channels = self::CHANNELS[$colorType];
            $result = '';
            foreach ($rows as $row) {
                $high = '';
                for ($x = 0; $x < $width; $x++) {
                    $pixel = substr($row, $x * $channels * 2, $channels * 2);
                    $samples = '';
                    for ($c = 0; $c < $channels; $c++) {
                        $samples .= $pixel[$c * 2];
                    }
                    $alpha = $transparentKey !== null && $pixel === $transparentKey ? "\0" : "\xFF";
                    $high .= self::expand8($samples, $colorType, $alpha);
                }
                $result .= $high;
            }

            return $result;
        }

        if ($colorType === 3) {
            $lookup = [];
            $entries = intdiv(strlen((string)$palette), 3);
            for ($i = 0; $i < 256; $i++) {
                if ($i < $entries) {
                    $alpha = $transparency !== null && $i < strlen($transparency) ? $transparency[$i] : "\xFF";
                    $lookup[$i] = substr((string)$palette, $i * 3, 3) . $alpha;
                } else {
                    $lookup[$i] = "\0\0\0\xFF";
                }
            }
            $result = '';
            foreach ($rows as $row) {
                foreach (unpack('C*', $row) as $index) {
                    $result .= $lookup[$index];
                }
            }

            return $result;
        }

        if ($colorType === 0) {
            $scale = intdiv(255, (1 << $depth) - 1);
            $key = $transparency !== null && strlen($transparency) >= 2 ? unpack('n', $transparency)[1] : -1;
            $lookup = [];
            for ($v = 0; $v < (1 << $depth); $v++) {
                $grey = chr($v * $scale);
                $lookup[$v] = $grey . $grey . $grey . ($v === $key ? "\0" : "\xFF");
            }
            $result = '';
            foreach ($rows as $row) {
                foreach (unpack('C*', $row) as $value) {
                    $result .= $lookup[$value];
                }
            }

            return $result;
        }

        if ($colorType === 6) {
            return implode('', $rows);
        }

        if ($colorType === 4) {
            $result = '';
            foreach ($rows as $row) {
                foreach (str_split($row, 2) as $pair) {
                    $result .= $pair[0] . $pair[0] . $pair[0] . $pair[1];
                }
            }

            return $result;
        }

        // Colour type 2: RGB.
        $key = null;
        if ($transparency !== null && strlen($transparency) >= 6) {
            $key = chr(unpack('n', $transparency, 0)[1] & 0xFF) . chr(unpack('n', $transparency, 2)[1] & 0xFF) .
                   chr(unpack('n', $transparency, 4)[1] & 0xFF);
        }
        $result = '';
        foreach ($rows as $row) {
            if ($key === null) {
                $result .= implode("\xFF", str_split($row, 3)) . "\xFF";
                continue;
            }
            foreach (str_split($row, 3) as $pixel) {
                $result .= $pixel . ($pixel === $key ? "\0" : "\xFF");
            }
        }

        return $result;
    }


    /**
     * Builds one RGBA pixel from 8-bit samples of the given colour type.
     */
    private static function expand8(string $samples, int $colorType, string $alpha): string
    {
        return match ($colorType) {
            0       => $samples . $samples . $samples . $alpha,
            2       => $samples . $alpha,
            4       => $samples[0] . $samples[0] . $samples[0] . $samples[1],
            default => $samples,
        };
    }
}
