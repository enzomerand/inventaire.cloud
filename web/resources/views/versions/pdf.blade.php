{{--
    The PDF of an official version, laid out by dompdf (A4 landscape, DejaVu Sans). Everything is already formatted in the language of
    the version by App\Inventories\Versions\OfficialPdfWriter; the view only places it. No external file is loaded.

    The page, the repeated header and the items share their layout with the PDF of an export (partials of exports/): see the notes in
    exports/pdf.blade.php. The header here is the title and three lines (when it was frozen, who by, the SHA-256 of the data). The row
    of the columns of a table is not part of it: the writer draws it on every page but the last when there is a page of signatures, which
    must not show the columns of the items above its boxes.

    The page of signatures comes last, after a page break: a box for each signer, with an empty area (`#signer-N`) the signature is
    drawn in. The writer reads the position of each area from the laid-out page, to tell the signature service where to put the field.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }}</title>
    <style>
        @include('exports.partials.pdf-style')
        .head .line { color: #52606d; line-height: {{ $summaryLine }}px; height: {{ $summaryLine }}px; margin: 0; padding: 0; white-space: nowrap; }
        .head .hash { color: #1f2933; }
        .signatures { page-break-before: always; }
        .signatures h2 { font-size: 13pt; line-height: 22px; height: 22px; margin: 0 0 4px 0; padding: 0; }
        .signatures .notice { color: #52606d; line-height: 13px; margin: 0 0 8px 0; }
        table.signers { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        table.signers td { border: 0; padding: 0 6px 6px 0; vertical-align: top; background-color: #ffffff; }
        .signer { border: 1px solid #b8c1ca; padding: 4px 6px; }
        .signer .who { font-size: 9pt; font-weight: bold; line-height: 13px; height: 13px; white-space: nowrap; overflow: hidden; }
        .signer .mail { color: #52606d; line-height: 13px; height: 13px; white-space: nowrap; overflow: hidden; }
        .signer .caption { color: #7b8794; font-size: 7pt; line-height: 12px; height: 12px; margin: 3px 0 2px 0; }
        .signer .area { width: {{ $areaWidth }}px; height: {{ $areaHeight }}px; border: 1px solid #52606d; margin: 0; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $headTitle }}</h1>
        <p class="line">{{ $headLines[0] }}</p>
        <p class="line">{{ $headLines[1] }}</p>
        <p class="line hash">{{ $headLines[2] }}</p>
    </div>

    @include('exports.partials.pdf-body')

    @if ($signers !== [])
        <div class="signatures">
            <h2>{{ __('Signatures') }}</h2>
            <p class="notice">{{ $signNotice }}</p>
            <table class="signers">
                @foreach (array_chunk($signers, $perRow, true) as $pair)
                    <tr>
                        @foreach ($pair as $position => $signer)
                            <td>
                                <div class="signer">
                                    <div class="who">{{ $signer['name'] }}</div>
                                    <div class="mail">{{ $signer['email'] }}</div>
                                    <div class="caption">{{ __('Signature') }}</div>
                                    <div class="area" id="signer-{{ $position }}"></div>
                                </div>
                            </td>
                        @endforeach
                        @for ($filler = count($pair); $filler < $perRow; $filler++)
                            <td></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
</body>
</html>
