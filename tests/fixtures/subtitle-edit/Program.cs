using Nikse.SubtitleEdit.UiLogic.Ocr;
using SkiaSharp;

// Runs SeConv.Core.NOcrOcrEngine.Recognize of Subtitle Edit on each image with a fresh engine, without the
// italic tags. Usage: dotnet run -- <Latin.nocr> <image.png>...
var db = new NOcrDb(args[0]);
foreach (var file in args.Skip(1))
{
    var tracker = new OcrLineHeightTracker();
    var caseFixer = new NOcrCaseFixer();
    using var bitmap = SKBitmap.Decode(file);
    var parent = new NikseBitmap2(bitmap);
    parent.MakeTwoColor(200);
    parent.CropTop(0, new SKColor(0, 0, 0, 0));
    var letters = NikseBitmapImageSplitter2.SplitBitmapToLettersNew(parent, 12, false, true,
        tracker.GetMinLineHeight(), true, tracker.GetAverageLineHeight());
    var text = new System.Text.StringBuilder();
    for (var i = 0; i < letters.Count; i++)
    {
        var item = letters[i];
        if (item.NikseBitmap == null)
        {
            if (item.SpecialCharacter != null)
            {
                text.Append(item.SpecialCharacter == Environment.NewLine ? " / " : item.SpecialCharacter);
            }

            continue;
        }

        var match = db.GetMatch(parent, letters, item, item.Top, true, 25, lastDitch: true);
        if (match is { ExpandCount: > 0 })
        {
            i += match.ExpandCount - 1;
        }

        text.Append(match == null ? "*" : caseFixer.FixUppercaseLowercaseIssues(item, match));
    }

    var set = Path.GetFileName(Path.GetDirectoryName(file));
    Console.WriteLine($"{set}/{Path.GetFileName(file)} | {text.ToString().Trim()}");
}
