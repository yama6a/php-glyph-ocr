<?php

namespace GlyphOcr\Exceptions;

/**
 * InvalidImageException signals image data that the library cannot decode.
 */
class InvalidImageException extends \RuntimeException implements GlyphOcrException
{
}
