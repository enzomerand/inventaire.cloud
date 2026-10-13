{{--
    Language switcher shown in the header.

    The list comes from config('app.supported_locales') so adding a language
    never requires touching this template. Each language is written in itself.

    Icons: never pass a class attribute to <x-tabler-*>. The SVG files already
    carry one, so the rendered tag would get it twice (invalid HTML). Size and
    space icons from a parent element instead.
--}}
@php
    $current = app()->getLocale();
    $names = config('app.locale_names');
@endphp

<div class="nav-item dropdown" x-data="localeSwitcher(@js(route('locale.update', absolute: false)))">
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
                @click="select(@js($locale))"
                :disabled="busy"
                @if ($locale === $current) aria-current="true" @endif
            >
                {{ $names[$locale] ?? $locale }}
            </button>
        @endforeach
    </div>
</div>
