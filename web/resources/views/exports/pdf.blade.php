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
        @include('exports.partials.pdf-style')
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

    @include('exports.partials.pdf-body')
</body>
</html>
