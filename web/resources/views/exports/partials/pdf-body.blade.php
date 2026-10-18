{{--
    The items of a PDF, laid out by App\Inventories\Exports\PdfBody: a table cut in fragments, or one card for each item.
    Needs $count, $asTable, $columns, $rows and $tableRows.
--}}
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
