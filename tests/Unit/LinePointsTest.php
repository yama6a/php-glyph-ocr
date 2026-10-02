<?php

namespace GlyphOcr\Tests\Unit;

use GlyphOcr\Internal\LinePoints;
use GlyphOcr\Internal\Rounding;
use PHPUnit\Framework\TestCase;

class LinePointsTest extends TestCase
{
    public function testWalksAHorizontalLineInBothDirections(): void
    {
        $this->assertSame([0, 0, 1, 0, 2, 0, 3, 0], LinePoints::walk(0, 0, 3, 0));
        $this->assertSame([0, 0, 1, 0, 2, 0, 3, 0], LinePoints::walk(3, 0, 0, 0));
    }


    public function testWalksAVerticalLineFromTheTop(): void
    {
        $this->assertSame([3, 2, 3, 3, 3, 4], LinePoints::walk(3, 4, 3, 2));
    }


    public function testWalksADiagonalAsAVerticalLine(): void
    {
        $this->assertSame([0, 0, 1, 1, 2, 2], LinePoints::walk(0, 0, 2, 2));
    }


    public function testRoundsShallowSlopesHalfAwayFromZero(): void
    {
        // y = 2 + 5/19 * (x - 1); at x = 10 the exact value is 4.368...
        $points = LinePoints::walk(1, 2, 20, 7);
        $this->assertSame(40, count($points));
        $this->assertSame([10, 4], [$points[18], $points[19]]);
        $this->assertSame([20, 7], [$points[38], $points[39]]);
    }


    public function testPutsTheSinglePointOfAZeroLengthLineAtXZero(): void
    {
        // Subtitle Edit divides 0 by 0 here and .NET converts the NaN to 0.
        $this->assertSame([0, 5], LinePoints::walk(5, 5, 5, 5));
    }


    public function testScalesTheEndPointsBeforeWalking(): void
    {
        $this->assertSame([0, 0, 1, 0, 2, 0], LinePoints::walkScaled([0, 0, 4, 0], 8, 8, 4, 4));
    }


    public function testRoundingMatchesDotNet(): void
    {
        $this->assertSame(3, Rounding::awayFromZero(2.5));
        $this->assertSame(-3, Rounding::awayFromZero(-2.5));
        $this->assertSame(2, Rounding::awayFromZero(2.4999999));
        $this->assertSame(2, Rounding::halfToEven(2.5));
        $this->assertSame(4, Rounding::halfToEven(3.5));
        $this->assertSame(3, Rounding::halfToEven(2.6));
    }
}
