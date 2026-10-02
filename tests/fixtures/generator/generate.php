<?php

// Writes every golden image and its text file. Run from the repository root:
// php tests/fixtures/generator/generate.php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use GlyphOcr\Tests\Fixtures\Generator\Fixtures;

foreach (Fixtures::all() as $name => $spec) {
    $base = dirname(__DIR__) . '/' . $name;
    if (!is_dir(dirname($base))) {
        mkdir(dirname($base), 0777, true);
    }
    file_put_contents("$base.png", Fixtures::render($spec));
    file_put_contents("$base.txt", implode("\n", $spec['lines']) . "\n");
    echo "$name\n";
}
