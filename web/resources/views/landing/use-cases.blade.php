{{--
    The use cases of the home page: a list of templates the visitor picks from, and, for the one picked, the grid its inventory would
    have and the scan that would fill a row. The data is the template's own (App\Landing\UseCases): what is shown is what "use this
    template" creates.

    Every panel is in the page (search engines read them all); the server hides all but the first, and the Alpine component
    `useCases` (resources/js/components/use-cases.js) shows the one picked. Without the script the first one stays, and the buttons
    do nothing: the link to the gallery is always there. The panels are plain <div>s with the `hidden` attribute, never a Tabler
    `d-*` class (which beats `hidden`). The grid and the phone are pictures: hidden from assistive technologies, the caption says what
    they show.

    Icons: never pass a class attribute to <x-tabler-*>.
--}}
<section id="use-cases" class="landing-field landing-field-secondary" aria-labelledby="use-cases-title">
    <div class="container-xl" x-data="useCases">
        <div class="row g-5">
            <div class="col-lg-4">
                <h2 id="use-cases-title" class="landing-h2">{{ __('Start from a real use case') }}</h2>

                <ul class="landing-picker list-unstyled">
                    @foreach ($useCases as $index => $case)
                        <li>
                            <button
                                type="button"
                                id="use-case-{{ $index }}"
                                class="landing-picker-button"
                                data-index="{{ $index }}"
                                aria-controls="use-case-panel-{{ $index }}"
                                aria-pressed="{{ $loop->first ? 'true' : 'false' }}"
                                :aria-pressed="isCurrent($el)"
                                @click="select"
                            >
                                <span class="landing-picker-name">{{ $case['name'] }}</span>
                                <span class="landing-picker-hook">{{ $case['hook'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                <a class="landing-link" href="{{ \App\Support\PublicUrls::templatesPath(app()->getLocale()) }}">{{ __('Browse the templates') }}</a>
            </div>

            <div class="col-lg-8">
                @foreach ($useCases as $index => $case)
                    <div
                        id="use-case-panel-{{ $index }}"
                        role="region"
                        aria-labelledby="use-case-{{ $index }}"
                        data-index="{{ $index }}"
                        @unless ($loop->first) hidden @endunless
                        :hidden="!isCurrent($el)"
                    >
                        <div class="landing-stage">
                            <div class="landing-stage-cloud" aria-hidden="true"><x-cloud-bars /></div>

                            <div class="landing-grid" data-ui aria-hidden="true">
                                <div class="table-responsive">
                                    <table class="table table-sm table-vcenter mb-0">
                                        <thead>
                                            <tr>
                                                @foreach ($case['columns'] as $column)
                                                    <th scope="col">{{ $column }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($case['rows'] as $position => $row)
                                                <tr @class(['table-active' => $case['scan'] !== null && $case['scan']['row'] === $position])>
                                                    @foreach ($row as $cell)
                                                        <td>@include('landing.cell', ['cell' => $cell])</td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            @if ($case['scan'] !== null)
                                <div class="landing-phone" data-ui aria-hidden="true">
                                    <div class="landing-phone-view">
                                        <div class="landing-phone-bars">
                                            @foreach ([3, 1, 5, 1, 3, 1, 5, 3, 1, 5, 1, 3] as $weight)
                                                <span style="--weight: {{ $weight }}"></span>
                                            @endforeach
                                        </div>
                                        <span class="landing-phone-line"></span>
                                    </div>
                                    <div class="landing-phone-chip">{{ __('Scanned: :value', ['value' => $case['scan']['value']]) }}</div>
                                </div>
                            @endif
                        </div>

                        <p class="landing-caption">
                            {{ __('Template: :name, :fields.', ['name' => $case['name'], 'fields' => trans_choice(':count field|:count fields', $case['fields'])]) }}
                            <a href="{{ $case['url'] }}">{{ __('Use this template') }}</a>
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
