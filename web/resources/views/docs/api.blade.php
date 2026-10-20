{{--
    The documentation of the public API: public, drawn by the server (search engines read it) from the OpenAPI document, with no
    script of its own and nothing loaded from another host. The introduction, then every operation under its tag, then the
    schemas; a column of links follows the reader on a wide screen and folds into a button on a narrow one (Bootstrap's collapse,
    driven by its data attributes: without script the links are simply not shown and the page is read in order).

    The text of the document says its language with `lang` (see docs.reference); the labels around it are in the
    language of the page. What the document draws (the long part) is the view docs.reference, rendered once for each
    language and kept in the cache by App\Docs\RenderedReference: `$reference` here is its HTML.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', $seo->title)

@section('heading', __('API documentation'))

@section('content')
    {{ $reference }}
@endsection
