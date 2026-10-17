{{--
    One schema of the API in the documentation: what it is, the schemas it is made of, its properties, and, for a schema that is
    one of several shapes, each shape. The anchor `schema-<Name>` is what the links of the types point to.
--}}
<article class="card mb-3" id="{{ $schema['id'] }}" aria-labelledby="{{ $schema['id'] }}-title">
    <div class="card-header">
        <h3 class="card-title font-monospace" id="{{ $schema['id'] }}-title">{{ $schema['name'] }}</h3>
    </div>

    <div class="card-body">
        @if ($schema['documentation']->toHtml() !== '')
            <div class="markdown mb-3" lang="en">{{ $schema['documentation'] }}</div>
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
            <div class="text-secondary small text-break mb-1" lang="en">{{ $detail }}</div>
        @endforeach

        <x-docs.rows :rows="$schema['rows']" :label="__('Properties of :schema', ['schema' => $schema['name']])" />

        @if ($schema['variants'] !== [])
            <p class="mb-2">{{ __('One of these shapes:') }}</p>

            @foreach ($schema['variants'] as $variant)
                <div class="mb-3">
                    <div class="mb-1">
                        @if ($variant['title'] !== '')
                            <strong lang="en">{{ $variant['title'] }}</strong>
                        @endif
                        <x-docs.type :tokens="$variant['type']" />
                    </div>
                    @if ($variant['documentation']->toHtml() !== '')
                        <div class="markdown mb-2" lang="en">{{ $variant['documentation'] }}</div>
                    @endif
                    <x-docs.rows :rows="$variant['rows']" :label="__('Properties of :schema', ['schema' => $variant['title'] !== '' ? $variant['title'] : $schema['name']])" />
                </div>
            @endforeach
        @endif
    </div>
</article>
