{{--
    How the value of a field is shown, from a "cell" (see resources/js/fields/cell.js): the element is chosen by its
    kind, and everything else is bound with x-text and attribute bindings, never x-html, so that nothing a person typed
    is interpreted as markup. Links to the web carry rel="noopener noreferrer nofollow".

    The links to a file are the exception: they carry rel="noopener" and never "noreferrer". The file is served by the API on
    this very origin, and without a Referer the request of the new tab is not stateful for Sanctum, so the API answers 401
    instead of the file. (The Referrer-Policy of the app is same-origin: the Referer never leaves the site.)

    Included in a template that has a "cell" in scope: the table of the items, the cards of the gallery and the details of an item.

    The files of a photo or file field are a thumbnail (or an icon) or a name for each file the cell shows, and "+n" for the
    others. A file is a link only where the cell says so (the details of an item): an <a> without href is no link, which is how
    one template serves the table, where the whole cell opens the item, and the details. The alternative text of a thumbnail
    is the name of the file.
--}}
<template x-if="cell.kind === 'empty'">
    <span class="text-secondary" x-text="cell.text"></span>
</template>
<template x-if="cell.kind === 'text'">
    <span :class="cell.multiline ? 'text-break app-multiline' : 'text-break'" :title="cell.title" x-text="cell.text"></span>
</template>
<template x-if="cell.kind === 'mono'">
    <span class="font-monospace text-break" x-text="cell.text"></span>
</template>
<template x-if="cell.kind === 'badges'">
    <span class="d-inline-flex flex-wrap gap-1">
        <template x-for="badge in cell.badges" :key="badge.text">
            <span :class="badge.class" x-text="badge.text"></span>
        </template>
    </span>
</template>
<template x-if="cell.kind === 'link' && !cell.plain">
    <a class="text-break" :href="cell.href" :target="cell.target" rel="noopener noreferrer nofollow" x-text="cell.text"></a>
</template>
{{-- A cell that is itself a button cannot hold a link: it shows the address as text (the table puts a link beside it). --}}
<template x-if="cell.kind === 'link' && cell.plain">
    <span class="text-break text-primary" x-text="cell.text"></span>
</template>
<template x-if="cell.kind === 'stars'">
    <span class="text-yellow text-nowrap" role="img" :aria-label="cell.title" :title="cell.title" x-text="cell.text"></span>
</template>
<template x-if="cell.kind === 'swatch'">
    <span class="d-inline-flex align-items-center gap-2">
        <span class="app-swatch" :style="cell.style" aria-hidden="true"></span>
        <span class="font-monospace" x-text="cell.text"></span>
    </span>
</template>
<template x-if="cell.kind === 'photos'">
    <span class="d-inline-flex flex-wrap align-items-center gap-1">
        <template x-for="file in cell.files" :key="file.id">
            <a :href="file.href" :target="file.target" rel="noopener">
                <template x-if="file.thumb">
                    <img class="app-thumb" :src="file.thumb" :alt="file.name" width="32" height="32" loading="lazy" decoding="async">
                </template>
                <template x-if="!file.thumb">
                    <span class="app-thumb app-file-icon text-secondary" role="img" :aria-label="file.name">
                        @include('partials.file-icon')
                    </span>
                </template>
            </a>
        </template>
        <template x-if="cell.more > 0">
            <span class="badge bg-secondary-lt" x-text="'+' + cell.more"></span>
        </template>
    </span>
</template>
<template x-if="cell.kind === 'files'">
    <span class="d-inline-flex flex-wrap align-items-center gap-2">
        <template x-for="file in cell.files" :key="file.id">
            <a class="d-inline-flex align-items-center gap-1" :href="file.href" :target="file.target" rel="noopener">
                <span class="app-file-icon text-secondary" aria-hidden="true">
                    @include('partials.file-icon')
                </span>
                <span class="text-break" x-text="file.name"></span>
                <template x-if="file.href">
                    <span class="text-secondary small text-nowrap" x-text="file.sizeText"></span>
                </template>
            </a>
        </template>
        <template x-if="cell.more > 0">
            <span class="badge bg-secondary-lt" x-text="'+' + cell.more"></span>
        </template>
    </span>
</template>
