{{--
    The icon of a file that has no thumbnail: an image, a PDF document or any other file, by the "icon" of the file (see
    resources/js/fields/files.js).

    Included in a template that has a "file" in scope: the cells of a photo or file field (partials/field-value), the
    fields of the dialog of an item (partials/item-file-field) and its attachments (partials/item-attachments).
    Icons: never pass a class attribute to <x-tabler-*>; the parent sizes them (.app-file-icon).
--}}
<template x-if="file.icon === 'image'">
    <x-tabler-photo aria-hidden="true" />
</template>
<template x-if="file.icon === 'pdf'">
    <x-tabler-file-type-pdf aria-hidden="true" />
</template>
<template x-if="file.icon === 'file'">
    <x-tabler-file aria-hidden="true" />
</template>
