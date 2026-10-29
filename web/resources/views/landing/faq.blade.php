{{--
    A list of questions as an accordion of flat tiles: Bootstrap's collapse (Tabler loads it), so the keyboard and the ARIA states come
    with it and no script of ours is involved. The first answer is open. The same list draws the FAQPage structured data (App\Landing\Faq),
    so the questions and answers here are the ones search engines are told about.

    Icons: never pass a class attribute to <x-tabler-*>; Tabler sizes the chevron (`.accordion-button-toggle`).

    Takes `$faq` (the questions and answers), `$faqId` (a prefix for the ids, unique on the page) and `$faqTitle` (the heading).
--}}
<section id="{{ $faqId }}" class="landing-section" aria-labelledby="{{ $faqId }}-title">
    <div class="container-xl">
        <div class="row g-5">
            <div class="col-lg-4">
                <h2 id="{{ $faqId }}-title" class="landing-h2 landing-sticky">{{ $faqTitle }}</h2>
            </div>

            <div class="col-lg-8">
                <div class="accordion landing-accordion" id="{{ $faqId }}-list">
                    @foreach ($faq as $position => $entry)
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button
                                    type="button"
                                    class="accordion-button @unless ($loop->first) collapsed @endunless"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#{{ $faqId }}-answer-{{ $position }}"
                                    aria-expanded="{{ $loop->first ? 'true' : 'false' }}"
                                    aria-controls="{{ $faqId }}-answer-{{ $position }}"
                                >
                                    <span>{{ $entry['question'] }}</span>
                                    <span class="accordion-button-toggle" aria-hidden="true"><x-tabler-chevron-down aria-hidden="true" /></span>
                                </button>
                            </h3>
                            <div id="{{ $faqId }}-answer-{{ $position }}" class="accordion-collapse collapse @if ($loop->first) show @endif" data-bs-parent="#{{ $faqId }}-list">
                                <div class="accordion-body">{{ $entry['answer'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>
