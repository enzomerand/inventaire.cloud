{{--
    A value of an item on the public page of a share link, drawn from a SharedCell by its kind. Everything is escaped: the texts are what
    people typed, and the addresses are the ones SharedInventory decided to link (a web address is only ever http: or https:).

    The links to the web carry rel="nofollow noopener noreferrer" (no weight for search engines, no access to this page, and above all no
    Referer: the address of this page is a secret). The files are read through the routes of the link, which check it again each time.
    Icons: never pass a class attribute to <x-tabler-*>; the parent sizes them (.app-file-icon).
--}}
@switch($cell->kind->value)
    @case('empty')
        <span class="text-secondary" aria-label="{{ __('Empty') }}">&mdash;</span>
        @break
    @case('mono')
        <span class="font-monospace text-break">{{ $cell->text }}</span>
        @break
    @case('badges')
        <span class="d-inline-flex flex-wrap gap-1">
            @foreach ($cell->badges as $badge)
                <span class="{{ $badge['class'] }}">{{ $badge['text'] }}</span>
            @endforeach
        </span>
        @break
    @case('link')
        <a class="text-break" href="{{ $cell->href }}" @if ($cell->external) target="_blank" @endif rel="nofollow noopener noreferrer">{{ $cell->text }}</a>
        @break
    @case('stars')
        <span class="app-stars text-nowrap" role="img" aria-label="{{ $cell->title }}" title="{{ $cell->title }}">{{ $cell->text }}</span>
        @break
    @case('swatch')
        <span class="d-inline-flex align-items-center gap-2">
            <span class="app-swatch" style="background-color: {{ $cell->text }}" aria-hidden="true"></span>
            <span class="font-monospace">{{ $cell->text }}</span>
        </span>
        @break
    @case('photos')
        <span class="d-inline-flex flex-wrap align-items-center gap-2">
            @foreach ($cell->files as $file)
                <a href="{{ $file->href }}" @if ($file->displayable) target="_blank" @endif rel="noopener noreferrer">
                    @if ($file->thumbnail !== null)
                        <img class="app-thumb-lg" src="{{ $file->thumbnail }}" alt="{{ $file->name }}" width="72" height="72" loading="lazy" decoding="async">
                    @else
                        <span class="app-thumb-lg app-file-icon text-secondary" role="img" aria-label="{{ $file->name }}">
                            @include('share.file-icon')
                        </span>
                    @endif
                </a>
            @endforeach
        </span>
        @break
    @case('files')
        <span class="d-inline-flex flex-wrap align-items-center gap-3">
            @foreach ($cell->files as $file)
                <a class="d-inline-flex align-items-center gap-1" href="{{ $file->href }}" @if ($file->displayable) target="_blank" @endif rel="noopener noreferrer">
                    <span class="app-file-icon text-secondary" aria-hidden="true">
                        @include('share.file-icon')
                    </span>
                    <span class="text-break">{{ $file->name }}</span>
                    <span class="text-secondary small text-nowrap">{{ $file->size }}</span>
                </a>
            @endforeach
        </span>
        @break
    @default
        <span @class(['text-break', 'app-multiline' => $cell->multiline])>{{ $cell->text }}</span>
@endswitch
