{{--
    One schema of the API in the documentation: what it is, the schemas it is made of, its properties, and, for a schema that is
    one of several shapes, each shape. The anchor `schema-<Name>` is what the links of the types point to. The texts are in the
    language `$textLang` (the same for the whole reference, set by docs.reference).
--}}
@php
    $textLang ??= 'en';
@endphp
<article class="card mb-3" id="{{ $schema['id'] }}" aria-labelledby="{{ $schema['id'] }}-title">
    <div class="card-header">
        <h3 class="card-title font-monospace" id="{{ $schema['id'] }}-title">{{ $schema['name'] }}</h3>
    </div>

    <div class="card-body">
        @if ($schema['documentation']->toHtml() !== '')
            <div class="markdown mb-3" lang="{{ $textLang }}">{{ $schema['documentation'] }}</div>
        @endif

        @if ($schema['rows'] === [] && $schema['variants'] === [])
            <p class="mb-2">{{ __('Type') }}: <x-docs.type :tokens="$schema['type']" /></p>
        @endif

        @if ($schema['extends'] !== [])
            <p class="mb-2">
                {{ __('Made of:') }}
                @foreach ($schema['extends'] as $parent)
                    <a class="font-monospace" href="#{{ \App\Docs\ApiReference::schemaId($parent) }}">{{ $parent }}</a>@unless ($loop->last), @endunless
                @endforeach
            </p>
        @endif

        @foreach ($schema['details'] as $detail)
            <div class="text-secondary small text-break mb-1" lang="{{ $textLang }}">{{ $detail }}</div>
        @endforeach

        @php
            // Worked out here and handed over as a variable: a `=>` in a bound attribute reads as an arrow function to the Alpine guard.
            $schemaLabel = __('Properties of :schema', ['schema' => $schema['name']]);
        @endphp
        <x-docs.rows :rows="$schema['rows']" :label="$schemaLabel" :text-lang="$textLang" />

        @if ($schema['variants'] !== [])
            <p class="mb-2">{{ __('One of these shapes:') }}</p>

            @foreach ($schema['variants'] as $variant)
                @php
                    $variantLabel = __('Properties of :schema', ['schema' => $variant['title'] !== '' ? $variant['title'] : $schema['name']]);
                @endphp
                <div class="mb-3">
                    <div class="mb-1">
                        @if ($variant['title'] !== '')
                            <strong lang="{{ $textLang }}">{{ $variant['title'] }}</strong>
                        @endif
                        <x-docs.type :tokens="$variant['type']" />
                    </div>
                    @if ($variant['documentation']->toHtml() !== '')
                        <div class="markdown mb-2" lang="{{ $textLang }}">{{ $variant['documentation'] }}</div>
                    @endif
                    <x-docs.rows :rows="$variant['rows']" :label="$variantLabel" :text-lang="$textLang" />
                </div>
            @endforeach
        @endif
    </div>
</article>
