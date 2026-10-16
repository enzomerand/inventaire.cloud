{{--
    What the head of a page says to robots: whether it may be indexed, and, for a public page, how it is described.

    `page` is the App\Support\Seo\PageMeta of a public page (the home page, the gallery of templates, the page of a template),
    or null for every other page (signing in, the application, the installer, the errors), which always asks not to be
    indexed. A public page asks to be indexed only when the instance allows it (`app.indexable`, APP_INDEXABLE) and the page
    itself does not ask otherwise (PageMeta::mayBeIndexed(), which the X-Robots-Tag header follows too): a copy for
    development or staging does not end up in a search engine, and nothing else changes on its pages.

    The addresses are absolute and come from APP_URL, never from the request (see App\Support\PublicUrls). The structured
    data (JSON-LD) is encoded by PageMeta so that no text can end the script element: it is the one place where the output is
    written without escaping, because it is already JSON with `<`, `>`, `&` and quotes written as escapes.
--}}
@props(['page' => null])

@if ($page !== null && $page->mayBeIndexed())
    <meta name="robots" content="index, follow">
@else
    <meta name="robots" content="noindex, nofollow">
@endif

@if ($page !== null)
    <meta name="description" content="{{ $page->description }}">
    <link rel="canonical" href="{{ $page->canonical }}">
    @foreach ($page->alternates as $locale => $address)
        <link rel="alternate" hreflang="{{ str_replace('_', '-', (string) $locale) }}" href="{{ $address }}">
    @endforeach
    @if ($page->default !== null && $page->alternates !== [])
        <link rel="alternate" hreflang="x-default" href="{{ $page->default }}">
    @endif

    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $page->title }}">
    <meta property="og:description" content="{{ $page->description }}">
    <meta property="og:url" content="{{ $page->canonical }}">
    <meta property="og:locale" content="{{ $page->ogLocale() }}">
    @foreach ($page->ogAlternateLocales() as $alternate)
        <meta property="og:locale:alternate" content="{{ $alternate }}">
    @endforeach

    @foreach ($page->jsonLdScripts() as $json)
        <script type="application/ld+json">{!! $json !!}</script>
    @endforeach
@endif
