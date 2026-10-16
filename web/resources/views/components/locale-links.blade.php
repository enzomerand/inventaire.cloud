{{--
    Language switcher of a page that exists once per language, with an address of its own for each (the public pages of
    the templates): a link to every language, not a button that stores the choice, so that search engines and visitors
    follow the same addresses and nothing is written to the language cookie.

    `alternates` maps each supported language to the path of this page in it. The dropdown is Bootstrap's, driven by its
    data attributes: no Alpine component is needed.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@props(['alternates'])

@php
    $current = app()->getLocale();
    $names = config('app.locale_names');
@endphp

<div class="nav-item dropdown">
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
        @foreach ($alternates as $locale => $path)
            <a
                class="dropdown-item @if ($locale === $current) active @endif"
                href="{{ $path }}"
                lang="{{ $locale }}"
                hreflang="{{ $locale }}"
                @if ($locale === $current) aria-current="true" @endif
            >
                {{ $names[$locale] ?? $locale }}
            </a>
        @endforeach
    </div>
</div>
