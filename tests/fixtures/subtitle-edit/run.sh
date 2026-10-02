#!/usr/bin/env bash
# Builds the nOCR engine of Subtitle Edit at the ported commit and writes expected.txt.
# Needs the .NET 10 SDK. Run from the repository root: tests/fixtures/subtitle-edit/run.sh
set -euo pipefail

commit=b8da12a4262e0294b1c8a67905b3cfe5066c1e00
here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

cp "$here/Program.cs" "$here/Harness.csproj" "$work/"
for name in NikseBitmap2 NikseBitmapImageSplitter2 ImageSplitterItem2 NOcrDb NOcrChar NOcrLine OcrPoint OcrPointF \
  NOcrCaseFixer INOcrCaseFixer OcrLineHeightTracker NOcrLineGenerator NOcrLineAlgorithm; do
  curl -fsSL "https://raw.githubusercontent.com/SubtitleEdit/subtitleedit/$commit/src/libuilogic/Ocr/$name.cs" \
    -o "$work/$name.cs"
done
# The comparisons with BinaryOcrBitmap at the end of the splitter need the rest of Subtitle Edit, and nOCR never calls them.
awk '/internal static int IsBitmapsAlike\(BinaryOcrBitmap bmp1, NikseBitmap2 bmp2\)/ { print "}"; exit } { print }' \
  "$work/NikseBitmapImageSplitter2.cs" > "$work/Splitter.tmp"
mv "$work/Splitter.tmp" "$work/NikseBitmapImageSplitter2.cs"

export DOTNET_CLI_TELEMETRY_OPTOUT=1 DOTNET_NOLOGO=1
dotnet build "$work/Harness.csproj" -c Release -o "$work/out" > /dev/null
dotnet "$work/out/Harness.dll" "$here/../../../resources/Latin.nocr" "$here"/../*/*.png > "$here/expected.txt"
