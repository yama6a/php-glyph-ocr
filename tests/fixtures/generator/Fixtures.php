<?php

namespace GlyphOcr\Tests\Fixtures\Generator;

/**
 * Fixtures lists every golden image: its set, its text and how to draw it.
 */
final class Fixtures
{
    private const WHITE = [255, 255, 255];
    private const YELLOW = [255, 255, 0];
    private const BLACK = [0, 0, 0];

    private const DEJAVU = 'DejaVuSans.ttf';
    private const DEJAVU_OBLIQUE = 'DejaVuSans-Oblique.ttf';
    private const LIBERATION = 'LiberationSans-Regular.ttf';
    private const LIBERATION_ITALIC = 'LiberationSans-Italic.ttf';
    private const NOTO = 'NotoSans-Regular.ttf';
    private const NOTO_ITALIC = 'NotoSans-Italic.ttf';
    private const OPEN_SANS = 'OpenSans-Regular.ttf';
    private const OPEN_SANS_ITALIC = 'OpenSans-Italic.ttf';


    /**
     * Returns the fixtures keyed by their path below tests/fixtures, without the extension.
     *
     * @return array<string, array{lines: list<string>, font: string, size: float, fill: array{int, int, int},
     *     outline: array{int, int, int}, outlineWidth: float, antiAlias: bool, output: string, padding: int,
     *     canvasWidth: int|null}>
     */
    public static function all(): array
    {
        $fixtures = [];

        // Blu-ray style: smooth RGBA edges, white or yellow fill, black outline.
        $bluray = [
            ['01', ["I don't know what you mean."], self::LIBERATION, 40, self::WHITE],
            ['02', ['Where were you last night?', 'I waited for hours.'], self::LIBERATION, 44, self::WHITE],
            ['03', ['We have to leave, now!'], self::DEJAVU, 40, self::WHITE],
            ['04', ["It's 10:30 already."], self::DEJAVU, 48, self::YELLOW],
            ['05', ['Thank you, Mr. Jensen.', 'Good night.'], self::LIBERATION, 52, self::YELLOW],
            ['06', ['Nobody saw anything, right?'], self::LIBERATION, 60, self::WHITE],
            ['07', ['The train leaves at 7:45.', 'Do not be late.'], self::DEJAVU, 36, self::WHITE],
            ['08', ['What happened to the money?'], self::DEJAVU, 56, self::WHITE],
        ];
        foreach ($bluray as [$id, $lines, $font, $size, $fill]) {
            $fixtures["bluray/$id"] = self::spec($lines, $font, $size, $fill, $size / 14, true, 'rgba', 6);
        }

        // PGS style: the cropped object bitmap with a palette of up to 256 entries.
        $pgs = [
            ['01', ['Get in the car. Quickly!'], self::LIBERATION, 48],
            ['02', ['- Are you sure?', '- Yes, I am.'], self::LIBERATION, 48],
            ['03', ["I'll be back in 5 minutes."], self::DEJAVU, 44],
            ['04', ['Hello, my friend.', 'How are you?'], self::DEJAVU, 52],
            ['05', ['Let me go!'], self::LIBERATION, 60],
            ['06', ['Which way is the station?'], self::DEJAVU, 40],
        ];
        foreach ($pgs as [$id, $lines, $font, $size]) {
            $fixtures["pgs/$id"] = self::spec($lines, $font, $size, self::WHITE, $size / 16, true, 'pgs', 2);
        }

        // DVD style: 720 pixels wide, 4 colours, small fonts.
        $dvd = [
            ['01', ['Come on, hurry up!'], self::LIBERATION, 26],
            ['02', ['Why did you do that?', 'You promised me.'], self::LIBERATION, 28],
            ['03', ['Turn left at the bridge.'], self::DEJAVU, 24],
            ['04', ['Maybe tomorrow, maybe never.', 'Who knows?'], self::DEJAVU, 26],
            ['05', ["Don't move."], self::LIBERATION, 30],
            ['06', ['Twenty years ago, in 1999.'], self::LIBERATION, 24],
        ];
        foreach ($dvd as [$id, $lines, $font, $size]) {
            $fixtures["dvd/$id"] = self::spec($lines, $font, $size, self::WHITE, 2.0, true, 'dvd', 4, 720);
        }

        // Italic fonts, Blu-ray style.
        $italic = [
            ['01', ['She is not coming back.'], self::LIBERATION_ITALIC, 44],
            ['02', ['Remember what I told you.', 'Always.'], self::LIBERATION_ITALIC, 40],
            ['03', ['Long ago, in a small town...'], self::DEJAVU_OBLIQUE, 40],
            ['04', ['Is anybody there?'], self::DEJAVU_OBLIQUE, 52],
        ];
        foreach ($italic as [$id, $lines, $font, $size]) {
            $fixtures["italic/$id"] = self::spec($lines, $font, $size, self::WHITE, $size / 14, true, 'rgba', 6);
        }

        // No outline: smooth white text, and hard-edged text without anti-aliasing.
        $plain = [
            ['01', ['Good morning, everybody.'], self::LIBERATION, 36, true],
            ['02', ['Open the door.', 'Slowly.'], self::DEJAVU, 32, true],
            ['03', ['Wait for me here.'], self::LIBERATION, 40, false],
            ['04', ['Six apples and nine pears.'], self::DEJAVU, 30, false],
        ];
        foreach ($plain as [$id, $lines, $font, $size, $antiAlias]) {
            $fixtures["plain/$id"] = self::spec($lines, $font, $size, self::WHITE, 0.0, $antiAlias, 'rgba', 4);
        }

        // Small sizes from 20 pixels up, Blu-ray style.
        $small = [
            ['01', ['See you later.'], self::LIBERATION, 20],
            ['02', ['Call the police!', 'Now!'], self::DEJAVU, 22],
            ['03', ['Is this your bag?'], self::LIBERATION, 24],
        ];
        foreach ($small as [$id, $lines, $font, $size]) {
            $fixtures["small/$id"] = self::spec($lines, $font, $size, self::WHITE, 1.5, true, 'rgba', 4);
        }

        // Capital I and lower case l in one line, in each style. Most sans-serif fonts draw both as one bar.
        $capitalI = [
            ['01', ["I'll tell Lily I'm ill.", 'Is it illegal?'], self::LIBERATION, 44, 'bluray'],
            ['02', ["If I fall, I'll call you.", 'Lisa will help.'], self::DEJAVU, 40, 'bluray'],
            ['03', ['IT IS ALL I HAVE.'], self::LIBERATION, 48, 'pgs'],
            ['04', ["Il est là. Ils l'aiment."], self::DEJAVU, 52, 'pgs'],
            ['05', ['I hope Isla likes it.', 'I lied.'], self::LIBERATION, 28, 'dvd'],
            ['06', ['Lily, I told you.'], self::DEJAVU, 26, 'dvd'],
            ['07', ["I think I lost Lucy's ball."], self::NOTO, 44, 'bluray'],
            ['08', ['Is Ian in Lisbon?'], self::NOTO, 28, 'dvd'],
            ['09', ['I really liked it.'], self::LIBERATION_ITALIC, 44, 'bluray'],
            ['10', ['Alice is all I need.'], self::DEJAVU_OBLIQUE, 40, 'bluray'],
            ['11', ['I will look later.'], self::NOTO_ITALIC, 44, 'bluray'],
            ['12', ["I'll call Bill."], self::LIBERATION, 22, 'small'],
            ['13', ['I like it. Lola does not.'], self::OPEN_SANS, 44, 'bluray'],
            ['14', ['Is Lille in Belgium?'], self::OPEN_SANS, 28, 'dvd'],
        ];
        foreach ($capitalI as [$id, $lines, $font, $size, $style]) {
            $fixtures["capital-i/$id"] = self::styled($style, $lines, $font, $size);
        }

        // Open Sans, a font that no bundled database has glyphs of.
        $unseen = [
            ['01', ["I don't believe it.", "Let's go home."], self::OPEN_SANS, 44, 'bluray'],
            ['02', ['Where is the hospital?'], self::OPEN_SANS, 48, 'pgs'],
            ['03', ['Call me when you land.', 'I will wait.'], self::OPEN_SANS, 28, 'dvd'],
            ['04', ['It was a long night.'], self::OPEN_SANS_ITALIC, 44, 'bluray'],
            ['05', ['Bring the keys, please.'], self::OPEN_SANS, 36, 'plain'],
            ['06', ['Is everyone ready?'], self::OPEN_SANS, 24, 'small'],
        ];
        foreach ($unseen as [$id, $lines, $font, $size, $style]) {
            $fixtures["unseen/$id"] = self::styled($style, $lines, $font, $size);
        }

        return $fixtures;
    }


    /**
     * Draws the lines as the set of the same name above draws them.
     *
     * @param list<string> $lines
     * @return array{lines: list<string>, font: string, size: float, fill: array{int, int, int},
     *     outline: array{int, int, int}, outlineWidth: float, antiAlias: bool, output: string, padding: int,
     *     canvasWidth: int|null}
     */
    public static function styled(string $style, array $lines, string $font, float $size): array
    {
        return match ($style) {
            'bluray' => self::spec($lines, $font, $size, self::WHITE, $size / 14, true, 'rgba', 6),
            'pgs'    => self::spec($lines, $font, $size, self::WHITE, $size / 16, true, 'pgs', 2),
            'dvd'    => self::spec($lines, $font, $size, self::WHITE, 2.0, true, 'dvd', 4, 720),
            'plain'  => self::spec($lines, $font, $size, self::WHITE, 0.0, true, 'rgba', 4),
            'small'  => self::spec($lines, $font, $size, self::WHITE, 1.5, true, 'rgba', 4),
        };
    }


    /**
     * @param list<string> $lines
     * @param array{int, int, int} $fill
     * @return array{lines: list<string>, font: string, size: float, fill: array{int, int, int},
     *     outline: array{int, int, int}, outlineWidth: float, antiAlias: bool, output: string, padding: int,
     *     canvasWidth: int|null}
     */
    private static function spec(array $lines, string $font, float $size, array $fill, float $outlineWidth,
                                 bool $antiAlias, string $output, int $padding, ?int $canvasWidth = null): array
    {
        return [
            'lines'        => $lines,
            'font'         => $font,
            'size'         => $size,
            'fill'         => $fill,
            'outline'      => self::BLACK,
            'outlineWidth' => $outlineWidth,
            'antiAlias'    => $antiAlias,
            'output'       => $output,
            'padding'      => $padding,
            'canvasWidth'  => $canvasWidth,
        ];
    }


    /**
     * Returns the PNG bytes of one fixture.
     *
     * @param array{lines: list<string>, font: string, size: float, fill: array{int, int, int},
     *     outline: array{int, int, int}, outlineWidth: float, antiAlias: bool, output: string, padding: int,
     *     canvasWidth: int|null} $spec
     */
    public static function render(array $spec): string
    {
        static $fonts = [];
        $fonts[$spec['font']] ??= new TrueTypeFont((string)file_get_contents(__DIR__ . '/fonts/' . $spec['font']));

        return TextRenderer::render($fonts[$spec['font']], $spec['lines'], $spec['size'], $spec['fill'],
                                    $spec['outline'], $spec['outlineWidth'], $spec['antiAlias'], $spec['output'],
                                    $spec['padding'], $spec['canvasWidth']);
    }
}
