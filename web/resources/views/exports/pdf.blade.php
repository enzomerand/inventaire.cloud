{{--
    The PDF of an export, laid out by dompdf (A4 landscape, DejaVu Sans). Everything is already formatted in the language of the export
    by App\Inventories\Exports\PdfWriter; the view only places it. No external file is loaded: the library is set not to fetch any.

    The header (name of the inventory, line about the export and, for a table, the header of the columns) is written once, in a
    block with `position: fixed`, which the library repeats on every page. It sits in the top margin of the page, whose height is
    made of it: the numbers come from PdfWriter, which knows how high the block is. The table is cut in fragments without a header
    of their own (the library lays a long table out with a cost that grows faster than its size), which all share the same
    percentages on the first row and a fixed layout, so that their columns are those of the header.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
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
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $headTitle }}</h1>
        <p class="summary">
            {{ __('Exported on :date', ['date' => $exportedAt]) }}
            &middot; {{ trans_choice(':count item|:count items', $count) }}@if ($filtered)
                &middot; {{ __('filtered view') }}@endif
        </p>
        @if ($columns !== [])
            <table>
                <tr>
                    @foreach ($columns as $column)
                        <th class="{{ $column['numeric'] ? 'number' : '' }}" style="width: {{ $column['share'] }}%">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </table>
        @endif
    </div>

    @if ($description)
        <p class="description">{{ $description }}</p>
    @endif

    @if ($count === 0)
        <p class="empty">{{ __('No item.') }}</p>
    @elseif ($asTable)
        {{-- Many small tables rather than one: the library lays a table out in memory with a cost that grows faster than its size. --}}
        @php($line = 0)
        @foreach (array_chunk($rows, $tableRows) as $part)
            <table>
                <tbody>
                    @foreach ($part as $index => $row)
                        <tr class="{{ $line++ % 2 === 0 ? 'odd' : '' }}">
                            {{-- A fixed layout takes the widths of the first row: the library does not read the columns --}}
                            @foreach ($row as $position => $cell)
                                <td class="{{ $columns[$position]['numeric'] ? 'number' : '' }}"@if ($index === 0) style="width: {{ $columns[$position]['share'] }}%"@endif>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @else
        {{-- Boxes, not tables: the library lays a box out with half the memory of a table with the same lines. --}}
        @foreach ($rows as $card)
            <div class="card">
                <div class="title">{{ $card['title'] }}</div>
                @foreach ($card['lines'] as [$label, $value])
                    <div class="line"><span class="label">{{ $label }}</span> &nbsp; {{ $value }}</div>
                @endforeach
                @if ($card['updated'] !== '')
                    <div class="line updated">{{ __('Modified on') }} &nbsp; {{ $card['updated'] }}</div>
                @endif
            </div>
        @endforeach
    @endif
</body>
</html>
