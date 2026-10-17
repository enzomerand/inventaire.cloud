{{--
    One operation of the API in the documentation: its method and path, the ability a token needs, then its parameters, its body,
    its answers (with the codes each can carry) and a command that calls it.

    Everything that comes from the OpenAPI document is in English, so the elements that hold it say `lang="en"` while the labels
    around it are in the language of the page. The texts are already HTML where they were Markdown (App\Docs\ApiReference).
--}}
@php
    $methodClass = match ($op['method']) {
        'GET' => 'bg-blue-lt',
        'POST' => 'bg-green-lt',
        'DELETE' => 'bg-red-lt',
        default => 'bg-orange-lt',
    };
@endphp

<article class="card mb-4" id="{{ $op['id'] }}" aria-labelledby="{{ $op['id'] }}-title">
    <div class="card-header flex-column align-items-start gap-2">
        <h3 class="card-title" id="{{ $op['id'] }}-title" lang="en">{{ $op['summary'] }}</h3>
        <div class="d-flex flex-wrap align-items-center gap-2 w-100">
            <span class="badge {{ $methodClass }} font-monospace">{{ $op['method'] }}</span>
            <code class="text-break">{{ $op['path'] }}</code>
            <span class="ms-auto">
                @if ($op['ability'] !== null)
                    <span class="badge bg-azure-lt"><span class="visually-hidden">{{ __('Token ability required:') }} </span><span class="font-monospace">{{ $op['ability'] }}</span></span>
                @else
                    <span class="badge bg-green-lt">{{ __('No token needed') }}</span>
                @endif
            </span>
        </div>
    </div>

    <div class="card-body">
        @if ($op['description'] !== null)
            <div class="markdown mb-3" lang="en">{{ $op['description'] }}</div>
        @endif

        @if ($op['parameters'] !== [])
            <h4 class="h5">{{ __('Parameters') }}</h4>
            <div class="table-responsive border rounded mb-3" tabindex="0" role="region" aria-label="{{ __('Parameters of :operation', ['operation' => $op['id']]) }}">
                <table class="table table-sm table-vcenter mb-0">
                    <caption class="visually-hidden">{{ __('Parameters of :operation', ['operation' => $op['id']]) }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Name') }}</th>
                            <th scope="col">{{ __('Sent in') }}</th>
                            <th scope="col">{{ __('Type') }}</th>
                            <th scope="col">{{ __('Required') }}</th>
                            <th scope="col">{{ __('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($op['parameters'] as $parameter)
                            <tr>
                                <th scope="row" class="fw-normal font-monospace text-nowrap">{{ $parameter['name'] }}</th>
                                <td class="font-monospace text-nowrap">{{ $parameter['in'] }}</td>
                                <td class="text-nowrap"><x-docs.type :tokens="$parameter['type']" /></td>
                                <td class="text-nowrap">{{ $parameter['required'] ? __('Yes') : __('No') }}</td>
                                <td class="app-docs-wide" lang="en">
                                    {{ $parameter['description'] }}
                                    @foreach ($parameter['details'] as $detail)
                                        <div class="text-secondary small text-break">{{ $detail }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($op['body'] !== null)
            @php
                // Worked out here and handed over as a variable: a `=>` in a bound attribute reads as an arrow function to the Alpine guard.
                $bodyLabel = __('Properties of the body of :operation', ['operation' => $op['id']]);
            @endphp
            <h4 class="h5">
                {{ __('Request body') }}
                @unless ($op['body']['required'])
                    <span class="text-secondary fw-normal small">({{ __('optional') }})</span>
                @endunless
            </h4>
            @foreach ($op['body']['contents'] as $content)
                <p class="mb-2"><code>{{ $content['mediaType'] }}</code> <x-docs.type :tokens="$content['type']" /></p>
                <x-docs.rows :rows="$content['rows']" :label="$bodyLabel" />
            @endforeach
        @endif

        <h4 class="h5">{{ __('Responses') }}</h4>
        @foreach ($op['responses'] as $response)
            @php
                $statusClass = match ($response['status'][0] ?? '') {
                    '2' => 'bg-green-lt',
                    '3' => 'bg-azure-lt',
                    '4' => 'bg-orange-lt',
                    default => 'bg-red-lt',
                };
                $responseLabel = __('Properties of the response :status of :operation', ['status' => $response['status'], 'operation' => $op['id']]);
            @endphp

            <div class="mb-3">
                <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
                    <span class="badge {{ $statusClass }} font-monospace">{{ $response['status'] }}</span>
                    <span lang="en">{{ $response['description'] }}</span>
                </div>

                @if ($response['headers'] !== [])
                    <div class="small text-secondary mb-1">
                        {{ __('Headers:') }}
                        @foreach ($response['headers'] as $header)
                            <code>{{ $header['name'] }}</code>@unless ($loop->last), @endunless
                        @endforeach
                    </div>
                @endif

                @forelse ($response['contents'] as $content)
                    <div class="small mb-2"><code>{{ $content['mediaType'] }}</code> <x-docs.type :tokens="$content['type']" /></div>
                    <x-docs.rows :rows="$content['rows']" :label="$responseLabel" />
                @empty
                    <div class="small text-secondary mb-2">{{ __('No body') }}</div>
                @endforelse

                @if ($response['codes'] !== [])
                    <div class="table-responsive border rounded" tabindex="0" role="region" aria-label="{{ __('Error codes of the response :status of :operation', ['status' => $response['status'], 'operation' => $op['id']]) }}">
                        <table class="table table-sm table-vcenter mb-0">
                            <caption class="visually-hidden">{{ __('Error codes of the response :status of :operation', ['status' => $response['status'], 'operation' => $op['id']]) }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Code') }}</th>
                                    <th scope="col">{{ __('Meaning') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($response['codes'] as $code)
                                    <tr>
                                        <th scope="row" class="fw-normal font-monospace text-nowrap">{{ $code['code'] }}</th>
                                        <td class="app-docs-wide" lang="en">{{ $code['description'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach

        <h4 class="h5">{{ __('Example') }}</h4>
        <pre class="border rounded p-3 mb-0 overflow-auto" tabindex="0"><code>{{ $op['curl'] }}</code></pre>
    </div>
</article>
