{{--
    How it works, in four steps. Each step is a flat field of its own tone holding the same picture, an item card that gets fuller: it
    is drawn from the first row of a use case (App\Landing\UseCases, the wine cellar when the catalogue has it), so the picture shows
    real columns and values in the language of the page. The last step offers a signed copy only where signatures can be asked for.
    The cards are pictures: hidden from assistive technologies.
--}}
@php
    $source = collect($useCases)->firstWhere('slug', 'wine-cellar') ?? ($useCases[0] ?? null);
    $entries = [];

    if ($source !== null && $source['rows'] !== []) {
        foreach ($source['columns'] as $position => $column) {
            $entries[] = ['label' => $column, 'cell' => $source['rows'][0][$position]];
        }
    }

    $signatures = \App\Signatures\Documenso::configured();
    $steps = [
        ['title' => __('Pick a template'), 'text' => __('Start from a ready-made template, or define your own fields.'), 'shown' => 1],
        ['title' => __('Add your items'), 'text' => __('Scan a barcode, type, or import your spreadsheet.'), 'shown' => 3],
        ['title' => __('Work together'), 'text' => __('Invite your team, or send a read-only link.'), 'shown' => 5],
        $signatures
            ? ['title' => __('Freeze a signed copy'), 'text' => __('Export a dated PDF and have it signed by e-mail.'), 'shown' => 5]
            : ['title' => __('Take it with you'), 'text' => __('Download XLSX, ODS, CSV or PDF.'), 'shown' => 5],
    ];
@endphp

<section id="how" aria-labelledby="how-title">
    <div class="container-xl landing-section-head">
        <h2 id="how-title" class="landing-h2">{{ __('How it works') }}</h2>
    </div>

    <ol class="landing-steps list-unstyled">
        @foreach ($steps as $number => $step)
            <li class="landing-step landing-step-{{ $number + 1 }}">
                <div>
                    <h3 class="landing-step-title">{{ $step['title'] }}</h3>
                    <p class="landing-step-text">{{ $step['text'] }}</p>
                </div>

                @if ($entries !== [])
                    <div class="landing-item" data-ui aria-hidden="true">
                        <div class="landing-item-name">@include('landing.cell', ['cell' => $entries[0]['cell']])</div>
                        <dl class="landing-item-fields">
                            @foreach (array_slice($entries, 1, $step['shown'] - 1) as $entry)
                                <div>
                                    <dt>{{ $entry['label'] }}</dt>
                                    <dd>@include('landing.cell', ['cell' => $entry['cell']])</dd>
                                </div>
                            @endforeach
                        </dl>
                        @if ($number >= 2)
                            <div class="landing-item-foot">
                                @if ($number === 2)
                                    <span class="badge bg-primary-lt">{{ __('Shared') }}</span>
                                @elseif ($signatures)
                                    <span class="badge bg-primary-lt"><x-tabler-check aria-hidden="true" /> {{ __('Signed') }}</span>
                                @else
                                    <span class="badge bg-primary-lt">XLSX</span>
                                    <span class="badge bg-primary-lt">PDF</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </li>
        @endforeach
    </ol>
</section>
