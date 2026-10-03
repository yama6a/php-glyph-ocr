# PHP Glyph OCR
A pure PHP OCR engine for clean rendered text bitmaps, such as the images of Blu-ray and DVD subtitles. It is a port of the nOCR engine from [Subtitle Edit](https://github.com/SubtitleEdit/subtitleedit) by Nikolaj Olsson.

The engine cuts an image into lines and glyphs and compares each glyph with a database of known glyphs. It reads text that a computer drew, in a font that the database knows. It does not read photos, scans or handwriting.

## Install
Needs PHP 8.2 or later with ext-zlib. ext-gd is optional and only needed for `Image::fromGd()`.

```sh
composer require yama6a/php-glyph-ocr
```

## Usage
```php
use GlyphOcr\GlyphDatabase;
use GlyphOcr\Image;
use GlyphOcr\Recognizer;

$recognizer = new Recognizer(GlyphDatabase::subtitleFonts());
$result = $recognizer->recognize(Image::fromPng(file_get_contents('subtitle.png')));

$result->text();                    // all lines, joined with "\n"
$result->confidence();              // mean confidence of all glyphs, from 0 to 1
$result->lines[0]->text;            // the first line
$result->lines[0]->confidence;      // mean confidence of the glyphs on the first line
$result->lines[0]->chars[0];        // RecognizedChar: text, confidence, italic, glyph, sample
```

- **Images**: `Image::fromPng($bytes)` decodes every PNG colour type without GD. `Image::fromGd($gdImage)` copies a GD image. `Image::fromRgba($pixels, $width, $height)` takes raw RGBA bytes or a list of integers.
- **Confidence**: the share of the database glyph's line points that agree with the image, from 0 to 1. An unknown glyph reads as `*` with confidence 0.
- **Ink**: a pixel is ink when the sum of its premultiplied red, green and blue is at least `inkThreshold` (200). So white or yellow text counts and a black outline does not.
- **State**: a recognizer learns the glyph heights from the images it reads and uses them to split the next images. Use one recognizer per subtitle stream, or call `reset()`.

| Option | Default | Meaning |
|:--- |:--- |:--- |
| `inkThreshold` | `200` | Minimum premultiplied red + green + blue of an ink pixel, 1 to 765 |
| `spaceWidth` | `null` | Empty columns that make a space. `null` uses a third of the typical glyph height of the line |
| `maxWrongPixels` | `25` | Error budget of the loose match passes |
| `fixLatinCase` | `true` | Picks upper or lower case for letters such as o and O from their height |
| `unknownText` | `'*'` | Text of a glyph that matches nothing |
| `italicSlant` | `0.0` | Above 0, a glyph that matches nothing is slanted back by this factor and tried again |
| `rightToLeft` | `false` | Puts the glyphs of each line in right to left order |
| `minLineHeight` | `12` | Minimum line height in pixels until the recognizer has learned the glyph heights |
| `lineContext` | `true` | Compares each glyph with the other glyphs of its line to pick I or l, o, O or 0, and comma or apostrophe. `false` keeps the database text, as Subtitle Edit does |

## Databases and training
`GlyphDatabase` reads and writes the `.nocr` files of Subtitle Edit, version 1 and 2. The package ships two databases:

| Method | Glyphs | File size | Load time, PHP 8.5 | Memory |
|:--- | ---:| ---:| ---:| ---:|
| `GlyphDatabase::subtitleFonts()` | 2,007 | 254 KB + 477 KB | 90 ms | 76 MB |
| `GlyphDatabase::latin()` | 699 | 477 KB | 58 ms | 52 MB |

- **`subtitleFonts()`**: glyphs trained on DejaVu Sans, Liberation Sans and Noto Sans, upright and italic, at 28 and 48 px, followed by the Latin database for other fonts. Liberation Sans has the metrics of Arial. Use this database for subtitles.
- **`latin()`**: the Latin database of Subtitle Edit, unchanged. Use it to get the same result as Subtitle Edit.
- Other scripts and other fonts need their own database.

`tests/fixtures/generator/train.php` writes `resources/SubtitleFonts.nocr` again. The fonts and their licenses are in `tests/fixtures/generator/fonts`. The database holds line segments measured on rendered glyphs, and no font outlines.

Train a glyph from a sample that a person confirmed. The new glyph goes first, so it wins over older glyphs that match equally well:

```php
use GlyphOcr\Trainer;

$database = GlyphDatabase::fromFile('my-font.nocr');
$trainer = new Trainer();
foreach ($recognizer->recognize($image)->unknownChars() as $char) {
    $database->add($trainer->train($char->sample, askAPerson($char->sample->toAscii())));
}
$database->save('my-font.nocr');
```

- `Recognizer::split($image)` returns the glyph samples of each line without matching them, with `null` for a space.
- `GlyphSample::merge([$left, $right])` joins the parts of a character that the splitter cuts apart, such as `"` or `%`.
- The trainer draws random line segments with a seeded generator, so the same sample and seed give the same glyph.

## Accuracy
The golden images in `tests/fixtures` are white or yellow subtitles, 20 to 60 pixels, 1 or 2 lines. `tests/fixtures/SOURCES.md` describes them. One recognizer reads each set in order.

Character accuracy, with the lines read without error in brackets:

| Set | Fonts | `subtitleFonts()` | `latin()` | After training |
|:--- |:--- | ---:| ---:| ---:|
| Blu-ray, smooth RGBA | DejaVu Sans, Liberation Sans | 98.8% (9 of 11) | 93.8% (3 of 11) | 99.2% (10 of 11) |
| PGS palette | DejaVu Sans, Liberation Sans | 100% (8 of 8) | 93.0% (3 of 8) | 100% (8 of 8) |
| DVD, 4 colours, 24 to 30 px | DejaVu Sans, Liberation Sans | 95.5% (4 of 8) | 63.2% (0 of 8) | 94.2% (4 of 8) |
| Italic | DejaVu Sans, Liberation Sans | 98.0% (3 of 5) | 92.1% (1 of 5) | 97.0% (2 of 5) |
| No outline | DejaVu Sans, Liberation Sans | 98.9% (4 of 5) | 79.8% (1 of 5) | 96.6% (3 of 5) |
| Small, 20 to 24 px | DejaVu Sans, Liberation Sans | 98.1% (3 of 4) | 50.0% (0 of 4) | 92.3% (1 of 4) |
| Capital I and lower case l | DejaVu Sans, Liberation Sans, Noto Sans, Open Sans | 99.4% (15 of 17) | 90.2% (3 of 17) | 97.9% (10 of 17) |
| Unseen font | Open Sans | 96.7% (5 of 8) | 84.9% (0 of 8) | 96.7% (4 of 8) |
| All | | 98.3% (51 of 66) | 85.0% (11 of 66) | 97.4% (42 of 66) |

- Character accuracy is 1 minus the edit distance divided by the length of the expected text.
- The Latin database has no glyphs of these fonts. The numbers show how it does on fonts it has not seen. The subtitle fonts database has no glyphs of Open Sans.
- "After training" adds to the Latin database one trained glyph per character for each font, size and style: the alphabet, digits and `.,!?'-:`, drawn on a separate image. A new recognizer reads each image.
- With `lineContext: false`, the Latin database reads 80.3% of the characters. Then 37 of the 269 capital I and lower case l in the golden texts come out as the other letter. The subtitle fonts database reads 91.2%, and 63 come out as the other letter. With `lineContext: true`, none do.
- With `italicSlant: 0.2`, the italic set reads 96.0% of the characters.
- With the same database and `lineContext: false`, the port reads every golden image exactly as Subtitle Edit does. `SubtitleEditParityTest` checks this.

## Speed
Mean time per golden image, one image of 1 or 2 lines, after the database is loaded:

| | PHP 8.2 | PHP 8.5 |
|:--- | ---:| ---:|
| `subtitleFonts()` | 122 ms | 115 ms |
| `latin()` | 244 ms | 247 ms |
| Load `subtitleFonts()` | 184 ms | 90 ms |
| Load `latin()` | 122 ms | 58 ms |

A full 1920x1080 frame with the same 2 lines takes about 1 second and 103 MB, so crop the image to the text where you can. A glyph that matches nothing is the slow case, because it runs through every match pass. So the subtitle fonts database is faster on the fonts it knows. Measured on one core of an x86_64 machine, without JIT.

## Limits
- One text colour on a transparent or dark background. The ink threshold removes the outline, so text with a light outline or a light background does not work.
- The matcher compares shapes. Most sans-serif fonts draw I and l as the same bar, and l is about 5% taller. The recognizer picks the letter from the tops of the capitals, the ascenders and the other bars on the line. A line without them falls back to the heights of earlier lines, then to the neighbouring letters.
- No dictionary and no language model. Subtitle Edit fixes common OCR errors in a separate step, which this package does not port.
- Italic text needs italic glyphs in the database, or `italicSlant`.

## Exceptions
Every exception implements `GlyphOcr\Exceptions\GlyphOcrException`: `InvalidArgumentException`, `InvalidImageException` for a PNG that does not decode, and `InvalidDatabaseException` for a `.nocr` file that does not load.

## Attribution
This package is a port of the nOCR engine from [Subtitle Edit](https://github.com/SubtitleEdit/subtitleedit) by Nikolaj Olsson, MIT license, at commit [`b8da12a`](https://github.com/SubtitleEdit/subtitleedit/tree/b8da12a4262e0294b1c8a67905b3cfe5066c1e00). `LICENSE` keeps both copyright notices.

| This package | Subtitle Edit file in `src/libuilogic/Ocr` |
|:--- |:--- |
| `Internal/Matcher.php`, `Glyph.php` | `NOcrDb.cs`, `NOcrChar.cs` |
| `GlyphDatabase.php` | `NOcrDb.cs`, `NOcrChar.cs` (file format) |
| `Internal/Splitter.php`, `Internal/SplitItem.php` | `NikseBitmapImageSplitter2.cs`, `ImageSplitterItem2.cs` |
| `Internal/Bitmap.php` | `NikseBitmap2.cs` |
| `Internal/LinePoints.php` | `NOcrLine.cs` |
| `Internal/CaseFixer.php` | `NOcrCaseFixer.cs` |
| `Internal/LineHeightTracker.php` | `OcrLineHeightTracker.cs` |
| `Trainer.php` | `NOcrChar.cs`, `NOcrLineGenerator.cs` |
| `GlyphSample.php` (merge) | `ExpandedOcrGroup.cs` |
| `resources/Latin.nocr` | `Ocr/Latin.nocr` at the repository root, unchanged |

`resources/SubtitleFonts.nocr` is trained on renderings of these fonts:

| Font | License |
|:--- |:--- |
| [DejaVu Sans 2.37](https://github.com/dejavu-fonts/dejavu-fonts/releases/tag/version_2_37) | Bitstream Vera license with public domain changes |
| [Liberation Sans 2.1.5](https://github.com/liberationfonts/liberation-fonts/releases/tag/2.1.5) | SIL Open Font License 1.1 |
| [Noto Sans 2.015](https://github.com/notofonts/latin-greek-cyrillic/releases/tag/NotoSans-v2.015) | SIL Open Font License 1.1 |
