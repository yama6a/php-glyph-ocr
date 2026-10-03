<?php

// Writes resources/SubtitleFonts.nocr. Run from the repository root:
// php tests/fixtures/generator/train.php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use GlyphOcr\Tests\Fixtures\Generator\SubtitleFonts;

$database = SubtitleFonts::database();
$database->save(dirname(__DIR__, 3) . '/resources/SubtitleFonts.nocr');
echo $database->count() . " glyphs\n";
