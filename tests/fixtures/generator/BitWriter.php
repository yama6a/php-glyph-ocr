<?php

namespace GlyphOcr\Tests\Fixtures\Generator;

/**
 * BitWriter packs bits least significant first, as deflate needs.
 */
final class BitWriter
{
    private string $out = '';
    private int $buffer = 0;
    private int $count = 0;


    public function write(int $value, int $bits): void
    {
        $this->buffer |= $value << $this->count;
        $this->count += $bits;
        while ($this->count >= 8) {
            $this->out .= chr($this->buffer & 0xFF);
            $this->buffer >>= 8;
            $this->count -= 8;
        }
    }


    /**
     * Writes a Huffman code, which deflate stores most significant bit first.
     */
    public function writeReversed(int $code, int $bits): void
    {
        $reversed = 0;
        for ($i = 0; $i < $bits; $i++) {
            $reversed = ($reversed << 1) | (($code >> $i) & 1);
        }
        $this->write($reversed, $bits);
    }


    public function finish(): string
    {
        if ($this->count > 0) {
            $this->out .= chr($this->buffer & 0xFF);
        }

        return $this->out;
    }
}
