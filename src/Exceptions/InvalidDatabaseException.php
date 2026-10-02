<?php

namespace GlyphOcr\Exceptions;

/**
 * InvalidDatabaseException signals a glyph database file that the library cannot read or write.
 */
class InvalidDatabaseException extends \RuntimeException implements GlyphOcrException
{
}
