{{--
    The stylesheet the PDFs of the application share (the PDF of an export and the PDF of an official version): the page, the repeated
    header, the table and the cards. The numbers come from App\Inventories\Exports\PdfHead.
--}}
@page { margin: {{ $marginTop }}px 32px 48px 32px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; color: #1f2933; margin: 0; }
.head { position: fixed; top: -{{ $headTop }}px; left: 0; right: 0; height: {{ $headHeight }}px; }
h1 { font-size: 15pt; line-height: {{ $titleLine }}px; height: {{ $titleLine }}px; margin: 0; padding: 0; white-space: nowrap; }
.summary { color: #52606d; line-height: {{ $summaryLine }}px; height: {{ $summaryLine }}px; margin: 0; padding: 0; }
.description { color: #52606d; margin: 0 0 10px 0; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.head table { margin-top: {{ $headGap }}px; }
tr { page-break-inside: avoid; }
th { background-color: #e4e9ee; text-align: left; font-weight: bold; border: 1px solid #b8c1ca; padding: 4px 5px; height: {{ $columnsLine - 8 - 2 }}px; line-height: {{ $columnsLine - 8 - 2 }}px; white-space: nowrap; overflow: hidden; }
th.number { text-align: right; }
td { border: 1px solid #d3d9df; border-top-width: 0; padding: 3px 5px; vertical-align: top; word-wrap: break-word; }
tr.odd td { background-color: #f5f7f9; }
.number { text-align: right; }
.card { border: 1px solid #b8c1ca; margin: 0 0 9px 0; page-break-inside: avoid; }
.card .title { background-color: #e4e9ee; font-size: 9pt; font-weight: bold; padding: 4px 5px; }
.card .line { border-top: 1px solid #d3d9df; padding: 2px 5px; }
.card .label { color: #52606d; font-weight: bold; }
.card .updated { color: #7b8794; font-size: 7pt; }
.empty { color: #52606d; }
