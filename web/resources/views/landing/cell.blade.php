{{--
    One cell of a grid drawn as a picture (the use cases, the item card): the same kinds of cell as the page of a template, drawn the
    same way but static and without the roles a real grid has. `$cell` is a cell as App\Inventories\Templates\TemplatePresenter writes it.
--}}
@switch($cell['kind'])
    @case('badges')
        <span class="d-inline-flex flex-wrap gap-1">
            @foreach ($cell['badges'] as $badge)
                <span class="{{ $badge['class'] }}">{{ $badge['text'] }}</span>
            @endforeach
        </span>
        @break
    @case('stars')
        <span class="app-stars text-nowrap">{{ $cell['text'] }}</span>
        @break
    @case('swatch')
        <span class="d-inline-flex align-items-center gap-2">
            <span class="app-swatch" style="background-color: {{ $cell['text'] }}"></span>
            <span class="font-monospace">{{ $cell['text'] }}</span>
        </span>
        @break
    @case('mono')
        <span class="font-monospace">{{ $cell['text'] }}</span>
        @break
    @case('empty')
        <span class="text-secondary">&mdash;</span>
        @break
    @default
        <span>{{ $cell['text'] }}</span>
@endswitch
