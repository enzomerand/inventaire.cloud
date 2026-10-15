{{--
    How the value of a field is shown, from a "cell" (see resources/js/fields/cell.js): the element is chosen by its
    kind, and everything else is bound with x-text and attribute bindings, never x-html, so that nothing a person typed
    is interpreted as markup. Links carry rel="noopener noreferrer nofollow".

    Included in a template that has a "cell" in scope: the table of the items and the details of an item.
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
<template x-if="cell.kind === 'link'">
    <a class="text-break" :href="cell.href" :target="cell.target" rel="noopener noreferrer nofollow" x-text="cell.text"></a>
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
