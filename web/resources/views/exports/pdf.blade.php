{{--
    The PDF of an export, laid out by dompdf (A4 landscape, DejaVu Sans). Everything is already formatted in the language of the export
    by App\Inventories\Exports\PdfWriter; the view only places it. No external file is loaded: the library is set not to fetch any.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 34px 32px 48px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; color: #1f2933; }
        h1 { font-size: 15pt; margin: 0 0 3px 0; }
        .summary { color: #52606d; margin: 0 0 3px 0; }
        .description { color: #52606d; margin: 0 0 10px 0; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background-color: #e4e9ee; text-align: left; font-weight: bold; border: 1px solid #b8c1ca; padding: 4px 5px; }
        td { border: 1px solid #d3d9df; padding: 3px 5px; vertical-align: top; word-wrap: break-word; }
        tr.odd td { background-color: #f5f7f9; }
        .number { text-align: right; }
        .card { border: 1px solid #b8c1ca; margin: 0 0 9px 0; page-break-inside: avoid; }
        .card .title { background-color: #e4e9ee; font-size: 9pt; font-weight: bold; padding: 4px 5px; }
        .card .line { border-top: 1px solid #d3d9df; padding: 2px 5px; }
        .card .label { color: #52606d; font-weight: bold; }
        .card .updated { color: #7b8794; font-size: 7pt; }
        .empty { margin-top: 24px; color: #52606d; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="summary">
        {{ __('Exported on :date', ['date' => $exportedAt]) }}
        &middot; {{ trans_choice(':count item|:count items', $count) }}@if ($filtered)
            &middot; {{ __('filtered view') }}@endif
    </p>
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
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($part as $row)
                        <tr class="{{ $line++ % 2 === 0 ? 'odd' : '' }}">
                            @foreach ($row as $position => $cell)
                                <td class="{{ $numeric[$position] ? 'number' : '' }}">{{ $cell }}</td>
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
