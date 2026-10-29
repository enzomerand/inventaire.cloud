{{--
    The motif of the sales pages: the cloud of barcode bars of the logo (art/logo.svg), drawn flat in the current color.

    Three ways to put it on a page, by `mode`:
    - "defs": the symbol `#cloud-bars`, to be drawn once per page, before the first use. It is an SVG of no size and not
      `display: none` (a clip path inside a hidden element is not applied by some browsers).
    - "use" (the default): a reference to the symbol, sized by the CSS of its parent. Any attribute is passed to the <svg>.
    - "inline": the bars as elements of the page itself, so that the stylesheet can animate each of them (`--i`, its rank).
      Its clip path has an id of its own.

    The drawing is decorative: it is hidden from assistive technologies. Nothing here is a Tabler icon, so it may take a class.
--}}
@props(['mode' => 'use'])

@php
    // The bars of the logo: the left edge and the width of each, in a 512 by 512 square, and the cloud that cuts them.
    $bars = [[96.0, 19.39], [125.09, 9.7], [144.48, 29.09], [183.27, 9.7], [202.67, 19.39], [231.76, 9.7], [251.15, 29.09], [289.94, 9.7], [309.33, 19.39], [338.42, 9.7], [357.82, 29.09], [396.61, 19.39]];
@endphp

@if ($mode === 'defs')
    <svg width="0" height="0" focusable="false" aria-hidden="true" class="landing-defs">
        <defs>
            <clipPath id="cloud-bars-clip">
                <circle cx="176" cy="296" r="80" />
                <circle cx="282" cy="232" r="96" />
                <circle cx="360" cy="320" r="56" />
                <rect x="176" y="296" width="184" height="80" />
            </clipPath>
        </defs>
        <symbol id="cloud-bars" viewBox="96 136 320 240">
            <g fill="currentColor" clip-path="url(#cloud-bars-clip)">
                @foreach ($bars as [$x, $width])
                    <rect x="{{ $x }}" y="120" width="{{ $width }}" height="272" />
                @endforeach
            </g>
        </symbol>
    </svg>
@elseif ($mode === 'inline')
    <svg {{ $attributes }} viewBox="96 136 320 240" focusable="false" aria-hidden="true">
        <defs>
            <clipPath id="cloud-bars-inline-clip">
                <circle cx="176" cy="296" r="80" />
                <circle cx="282" cy="232" r="96" />
                <circle cx="360" cy="320" r="56" />
                <rect x="176" y="296" width="184" height="80" />
            </clipPath>
        </defs>
        <g fill="currentColor" clip-path="url(#cloud-bars-inline-clip)">
            @foreach ($bars as $rank => [$x, $width])
                <rect style="--i: {{ $rank }}" x="{{ $x }}" y="120" width="{{ $width }}" height="272" />
            @endforeach
        </g>
    </svg>
@else
    <svg {{ $attributes }} viewBox="0 0 320 240" focusable="false" aria-hidden="true"><use href="#cloud-bars" /></svg>
@endif
