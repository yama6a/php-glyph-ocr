<?php

namespace GlyphOcr\Internal;

/**
 * @internal
 */
final class Rounding
{
    /**
     * Rounds half away from zero without the pre-rounding of PHP's round() before 8.4, so every PHP version
     * gives the same result as .NET Math.Round with MidpointRounding.AwayFromZero.
     */
    public static function awayFromZero(float $value): int
    {
        if (is_nan($value)) {
            // .NET 9 and later convert NaN to 0.
            return 0;
        }
        $truncated = (int)$value;
        $fraction = $value - $truncated;
        if ($fraction >= 0.5) {
            return $truncated + 1;
        }
        if ($fraction <= -0.5) {
            return $truncated - 1;
        }

        return $truncated;
    }


    /**
     * Rounds half to even, as .NET Math.Round does without a midpoint argument.
     */
    public static function halfToEven(float $value): int
    {
        $floor = floor($value);
        $fraction = $value - $floor;
        if ($fraction > 0.5) {
            return (int)$floor + 1;
        }
        if ($fraction < 0.5) {
            return (int)$floor;
        }

        return ((int)$floor) % 2 === 0 ? (int)$floor : (int)$floor + 1;
    }
}
