# Sources

Every image here is written for this repository by `generator/generate.php`. No file holds text from a film.

## Golden images

Each `<set>/<nn>.png` has a `<set>/<nn>.txt` with the exact text, one line per text line.

| Set | Shape | Fonts and sizes |
|:--- |:--- |:--- |
| `bluray` | 8-bit RGBA, smooth edges, white or yellow fill, black outline of size / 14 pixels | DejaVu Sans, Liberation Sans, 36 to 60 px |
| `pgs` | Cropped object bitmap as a Blu-ray PGS stream holds it: a palette of up to 256 RGBA entries, white fill, black outline | DejaVu Sans, Liberation Sans, 40 to 60 px |
| `dvd` | 720 pixels wide, 4 palette colours as a DVD VobSub subpicture: transparent, white fill, black outline, grey edge | DejaVu Sans, Liberation Sans, 24 to 30 px |
| `italic` | As `bluray`, with the italic font files | DejaVu Sans Oblique, Liberation Sans Italic, 40 to 52 px |
| `plain` | White text without outline, 2 with smooth edges and 2 without anti-aliasing | DejaVu Sans, Liberation Sans, 30 to 40 px |
| `small` | As `bluray`, with a 1.5 pixel outline | DejaVu Sans, Liberation Sans, 20 to 24 px |
| `capital-i` | Lines with capital I and lower case l, in the shapes of the sets above | DejaVu Sans, Liberation Sans, Noto Sans and their italics, Open Sans, 22 to 52 px |
| `unseen` | General lines in the shapes of the sets above, in a font that no bundled database has glyphs of | Open Sans, Open Sans Italic, 24 to 48 px |

The image sizes and palettes follow the PGS and VobSub fixtures of [subtitle-toolbox](https://github.com/yama6a/subtitle-toolbox/tree/master/tests/files), which hold shapes only.

## Rebuild

```sh
php tests/fixtures/generator/generate.php
```

The generator needs no PHP extension beyond mbstring. It reads the outlines with its own TrueType parser, fills them with its own rasterizer and writes the PNG files with its own deflate encoder. So the bytes do not depend on FreeType, GD or zlib, and `FixtureGeneratorTest` checks that a rebuild gives the same bytes. `generator/Fixtures.php` lists the text and the drawing settings of every image.

The rasterizer uses the accumulation method of [font-rs](https://github.com/raphlinus/font-rs) (Apache 2.0), written again in PHP.

## Fonts

| File | Source | License |
|:--- |:--- |:--- |
| `generator/fonts/DejaVuSans.ttf`, `DejaVuSans-Oblique.ttf` | [DejaVu Fonts 2.37](https://github.com/dejavu-fonts/dejavu-fonts/releases/tag/version_2_37) | Bitstream Vera license with public domain changes, see `DejaVu-LICENSE.txt` |
| `generator/fonts/LiberationSans-Regular.ttf`, `LiberationSans-Italic.ttf` | [Liberation Fonts 2.1.5](https://github.com/liberationfonts/liberation-fonts/releases/tag/2.1.5) | SIL Open Font License 1.1, see `Liberation-LICENSE.txt` |
| `generator/fonts/NotoSans-Regular.ttf`, `NotoSans-Italic.ttf` | [Noto Sans 2.015](https://github.com/notofonts/latin-greek-cyrillic/releases/tag/NotoSans-v2.015), the unhinted files | SIL Open Font License 1.1, see `Noto-LICENSE.txt` |
| `generator/fonts/OpenSans-Regular.ttf`, `OpenSans-Italic.ttf` | [Open Sans](https://github.com/googlefonts/opensans/tree/bd7e37632246368c60fdcbd374dbf9bad11969b6/fonts/ttf) at commit `bd7e376`, the static files | SIL Open Font License 1.1, see `OpenSans-LICENSE.txt` |

All licenses allow redistribution of the unchanged font files with their license text.

## Subtitle Edit output

`subtitle-edit/expected.txt` holds what the nOCR engine of Subtitle Edit reads from every golden image with `resources/Latin.nocr`. `SubtitleEditParityTest` checks that the port reads the same. To write the file again, install the .NET 10 SDK and run:

```sh
tests/fixtures/subtitle-edit/run.sh
```

The script downloads the Subtitle Edit source files at the ported commit and builds `Program.cs`, which runs the engine as `seconv` does. A line break shows as ` / `.
