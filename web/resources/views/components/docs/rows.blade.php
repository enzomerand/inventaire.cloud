{{--
    The table of the properties of an object in the documentation of the API: name, type, whether it is required, and what the
    document says about it. `rows` are those of App\Docs\SchemaDescriber::rows(); their descriptions are already HTML (rendered
    from the Markdown of the document, which is not always in the language of the page: `textLang` is the one it is in, English or
    the language of its translation). The table scrolls inside its own container, which a keyboard can focus. The name, the type and the "required" stay on one line (the table scrolls rather than breaking
    `inventory` in the middle), the description is given room.
--}}
@props(['rows', 'label', 'textLang' => 'en'])
@if ($rows !== [])
    <div class="table-responsive border rounded mb-3" tabindex="0" role="region" aria-label="{{ $label }}">
        <table class="table table-sm table-vcenter mb-0">
            <caption class="visually-hidden">{{ $label }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('Name') }}</th>
                    <th scope="col">{{ __('Type') }}</th>
                    <th scope="col">{{ __('Required') }}</th>
                    <th scope="col">{{ __('Description') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <th scope="row" class="fw-normal font-monospace text-nowrap">{{ $row['name'] }}</th>
                        <td class="text-nowrap"><x-docs.type :tokens="$row['type']" /></td>
                        <td class="text-nowrap">{{ $row['required'] ? __('Yes') : __('No') }}</td>
                        <td class="app-docs-wide" lang="{{ $textLang }}">
                            {{ $row['description'] }}
                            @foreach ($row['details'] as $detail)
                                <div class="text-secondary small text-break">{{ $detail }}</div>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
