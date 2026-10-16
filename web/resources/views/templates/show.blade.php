{{--
    The page of one inventory template: public, drawn by the server (search engines read it). Its name, its summary and
    description, the table of its fields, a few example rows and the button that uses it. The language is the one of the
    address.

    Everything comes from the presenter as plain text and badge classes, and is escaped on the way out: no markup from a file
    of the catalogue is ever interpreted. Nothing here is interactive, so there is no Alpine component. The button is a
    link to /app/templates/{slug}, which signs the visitor in first when needed.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php); the icon of a template is
    one of a closed list (TemplateIcons) drawn by a dynamic component.
--}}
@extends('layouts.app')

@section('title', $seo->title)

@section('heading', $template->name($locale))

@section('content')
    @php($useUrl = route('app.templates.use', ['slug' => $template->slug], absolute: false))

    <nav aria-label="{{ __('Breadcrumb') }}">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="{{ route('home', absolute: false) }}">{{ __('Home') }}</a></li>
            <li class="breadcrumb-item"><a href="{{ \App\Support\PublicUrls::templatesPath($locale) }}">{{ __('Inventory templates') }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $template->name($locale) }}</li>
        </ol>
    </nav>

    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <p class="lead">{{ $template->summary($locale) }}</p>

                    @foreach ($template->description($locale) as $paragraph)
                        <p class="@if ($loop->last) mb-0 @endif">{{ $paragraph }}</p>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="avatar avatar-md bg-primary-lt">
                            <x-dynamic-component :component="\App\Inventories\Templates\TemplateIcons::component($template->icon)" aria-hidden="true" />
                        </span>
                        <div>
                            <div class="fw-bold text-break">{{ $template->name($locale) }}</div>
                            <div class="text-secondary small">{{ \App\Inventories\Templates\TemplateCategories::label($template->category) }}</div>
                        </div>
                    </div>
                    <p class="text-secondary">{{ trans_choice(':count field|:count fields', $template->fieldsCount()) }}</p>
                    <a class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center gap-1" href="{{ $useUrl }}" rel="nofollow">
                        <x-tabler-rocket aria-hidden="true" />
                        {{ __('Use this template') }}
                    </a>
                    <p class="text-secondary small mt-3 mb-0">{{ __('You will be asked to sign in, then the inventory is created with these fields.') }}</p>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Fields') }}</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Name') }}</th>
                                <th scope="col">{{ __('Type') }}</th>
                                <th scope="col">{{ __('Required') }}</th>
                                <th scope="col">{{ __('Details') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fields as $field)
                                <tr>
                                    <th scope="row" class="fw-normal text-break">{{ $field['name'] }}</th>
                                    <td class="text-secondary">{{ $field['type'] }}</td>
                                    <td>{{ $field['required'] ? __('Yes') : __('No') }}</td>
                                    <td>
                                        <span class="d-inline-flex flex-wrap gap-1">
                                            @foreach ($field['badges'] as $badge)
                                                <span class="{{ $badge['class'] }}">{{ $badge['text'] }}</span>
                                            @endforeach
                                            @foreach ($field['details'] as $detail)
                                                <span class="text-secondary">{{ $detail }}</span>
                                            @endforeach
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if ($examples['rows'] !== [])
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('Examples') }}</h2>
                    </div>
                    <div class="card-body pb-0">
                        <p class="text-secondary">{{ __('These rows only show what the inventory could hold. They are not added to your inventory.') }}</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    @foreach ($examples['columns'] as $column)
                                        <th scope="col">{{ $column }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($examples['rows'] as $row)
                                    <tr>
                                        @foreach ($row as $cell)
                                            <td>
                                                @switch($cell['kind'])
                                                    @case('badges')
                                                        <span class="d-inline-flex flex-wrap gap-1">
                                                            @foreach ($cell['badges'] as $badge)
                                                                <span class="{{ $badge['class'] }}">{{ $badge['text'] }}</span>
                                                            @endforeach
                                                        </span>
                                                        @break
                                                    @case('stars')
                                                        <span class="text-yellow text-nowrap" role="img" aria-label="{{ $cell['label'] }}" title="{{ $cell['label'] }}">{{ $cell['text'] }}</span>
                                                        @break
                                                    @case('swatch')
                                                        <span class="d-inline-flex align-items-center gap-2">
                                                            <span class="app-swatch" style="background-color: {{ $cell['text'] }}" aria-hidden="true"></span>
                                                            <span class="font-monospace">{{ $cell['text'] }}</span>
                                                        </span>
                                                        @break
                                                    @case('mono')
                                                        <span class="font-monospace text-break">{{ $cell['text'] }}</span>
                                                        @break
                                                    @case('empty')
                                                        <span class="text-secondary" aria-label="{{ __('Empty') }}">&mdash;</span>
                                                        @break
                                                    @default
                                                        <span class="text-break">{{ $cell['text'] }}</span>
                                                @endswitch
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        <div class="col-12 text-center py-3">
            <a class="btn btn-primary btn-lg d-inline-flex align-items-center gap-1" href="{{ $useUrl }}" rel="nofollow">
                <x-tabler-rocket aria-hidden="true" />
                {{ __('Use this template') }}
            </a>
        </div>
    </div>
@endsection
