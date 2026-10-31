{{--
    The terms of sale as a PDF document, laid out by dompdf: the French text (the version that prevails, with the withdrawal form it
    ends with) in a page of its own, styled inline because the library reads no stylesheet of the application. DejaVu Sans is the font the
    library embeds whole, so every accent is drawn. Same variables as the page: $seller, $plans, $termsVersion, and $termsVersionDate.
--}}
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Conditions générales de vente</title>
    <style>
        @page { margin: 48px 46px 62px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; line-height: 1.45; color: #1d273b; }
        h1 { margin: 0 0 4px; font-size: 16pt; line-height: 1.2; }
        h2 { margin: 16px 0 5px; font-size: 11pt; line-height: 1.25; page-break-after: avoid; }
        h3 { margin: 12px 0 4px; font-size: 9.6pt; page-break-after: avoid; }
        p { margin: 0 0 7px; }
        table { width: 100%; margin: 0 0 10px; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #c9d0da; text-align: left; vertical-align: top; }
        thead th { border-bottom: 2px solid #1d273b; }
        .version { margin: 0 0 14px; color: #5b6578; font-size: 8pt; }
    </style>
</head>
<body>
    <h1>Conditions générales de vente</h1>
    <p class="version">Version du {{ $termsVersionDate }}</p>

    @include('legal.terms-fr')
</body>
</html>
