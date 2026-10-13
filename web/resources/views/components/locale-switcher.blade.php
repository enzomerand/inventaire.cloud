{{--
    Language switcher shown in the header.

    The list comes from config('app.supported_locales') so adding a language
    never requires touching this template. Each language is written in itself.

    Alpine runs in its CSP build: the component is referenced by name and gets
    its values from the data-* attributes below, never from expressions that
    Blade fills in.

    Icons: never pass a class attribute to <x-tabler-*>. The SVG files already
    carry one, so the rendered tag would get it twice (invalid HTML). Size and
    space icons from a parent element instead.
--}}
@php
    $current = app()->getLocale();
    $names = config('app.locale_names');
@endphp

<div class="nav-item dropdown" x-data="localeSwitcher" data-url="{{ route('locale.update', absolute: false) }}">
    <button
        type="button"
        class="nav-link dropdown-toggle d-flex align-items-center gap-1 bg-transparent border-0"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        aria-label="{{ __('Change language') }}"
    >
        <x-tabler-language aria-hidden="true" />
        <span>{{ $names[$current] ?? $current }}</span>
    </button>

    <div class="dropdown-menu dropdown-menu-end">
        @foreach (config('app.supported_locales') as $locale)
            <button
                type="button"
                class="dropdown-item @if ($locale === $current) active @endif"
                lang="{{ $locale }}"
                data-locale="{{ $locale }}"
                @click="select"
                :disabled="busy"
                @if ($locale === $current) aria-current="true" @endif
            >
                {{ $names[$locale] ?? $locale }}
            </button>
        @endforeach
    </div>
</div>
