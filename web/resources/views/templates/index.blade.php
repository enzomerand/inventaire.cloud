{{--
    The gallery of the inventory templates: public, drawn by the server (search engines read it), the templates under the
    heading of their category. The language is the one of the address.

    Nothing here is interactive, so there is no Alpine component. A card is one link to the page of the template.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php); the icon of a template is
    one of a closed list (TemplateIcons) drawn by a dynamic component.
--}}
@extends('layouts.app')

@section('title', $seo->title)

@section('heading', __('Inventory templates'))

@section('content')
    <p class="lead text-secondary mb-4">
        {{ __('Start from a ready-made inventory: pick a template, adjust its fields and start counting.') }}
    </p>

    @forelse ($groups as $category => $templates)
        <section class="mb-5" aria-labelledby="category-{{ $category }}">
            <h2 class="h3 mb-3" id="category-{{ $category }}">{{ \App\Inventories\Templates\TemplateCategories::label($category) }}</h2>

            <div class="row row-cards">
                @foreach ($templates as $template)
                    <div class="col-12 col-md-6 col-lg-4">
                        <a class="card card-link card-link-pop h-100 text-reset" href="{{ \App\Support\PublicUrls::templatePath($template->slug, $locale) }}">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <span class="avatar avatar-md bg-primary-lt">
                                        <x-dynamic-component :component="\App\Inventories\Templates\TemplateIcons::component($template->icon)" aria-hidden="true" />
                                    </span>
                                    <h3 class="card-title m-0 text-break">{{ $template->name($locale) }}</h3>
                                </div>
                                <p class="text-secondary mb-3">{{ $template->summary($locale) }}</p>
                                <div class="text-secondary small">{{ trans_choice(':count field|:count fields', $template->fieldsCount()) }}</div>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="card">
            <div class="card-body text-center text-secondary py-5">{{ __('No template is available yet.') }}</div>
        </div>
    @endforelse
@endsection
