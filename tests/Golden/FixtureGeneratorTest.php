<?php

namespace GlyphOcr\Tests\Golden;

use GlyphOcr\Tests\Fixtures\Generator\Fixtures;
use PHPUnit\Framework\TestCase;

class FixtureGeneratorTest extends TestCase
{
    public function testTheGeneratorRebuildsEveryFixtureByteForByte(): void
    {
        $fixtures = dirname(__DIR__) . '/fixtures';
        $all = Fixtures::all();

        foreach ($all as $name => $spec) {
            $this->assertSame(md5_file("$fixtures/$name.png"), md5(Fixtures::render($spec)), "$name.png differs");
            $this->assertSame(implode("\n", $spec['lines']) . "\n", file_get_contents("$fixtures/$name.txt"));
        }
        $this->assertCount(count($all), glob("$fixtures/*/*.png"));
    }
}
