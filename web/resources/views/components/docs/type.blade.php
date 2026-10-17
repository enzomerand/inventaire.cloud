{{--
    The type of a value in the documentation of the API: a list of tokens (App\Docs\SchemaDescriber), the names of schemas
    being links to their entry in the list of schemas. Written on one line, so that the only spaces between two tokens are the
    ones they carry ("array of ", " | ") and one more that the browser folds into them. (A directive needs a space before it:
    `@endif@endforeach` is not read.)
--}}
@props(['tokens'])
<span class="font-monospace">@foreach ($tokens as $token)@if ($token['schema'] !== null)<a href="#{{ \App\Docs\ApiReference::schemaId($token['schema']) }}">{{ $token['text'] }}</a>@else{{ $token['text'] }}@endif @endforeach</span>
