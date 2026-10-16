{{--
    The control of a photo or file field in the dialog of an item: the files it holds (thumbnails for a photo field, names
    and sizes for a file field), the ones chosen for an item that is being added, the ones on their way up or refused, and
    the button that adds one while the field has room. A file goes up as soon as it is chosen (an item that exists) or right
    after the item is saved (an item being added); removing one asks first, since the file is erased.

    Included in the field loop of the dialog (app/inventory.blade.php), so it reads the inventoryItems component
    (resources/js/components/inventory-items.js, with resources/js/item/files.js) and has a "field" in scope.
    The remove buttons are .btn-close, which the cursor skips when the dialog opens on this field: it lands on "Add" (and in the
    usual place when the field is full, since "Add" is then hidden).

    The field is a group named by its label (role="group" aria-labelledby): the label points at no control, since the one
    button that adds a file is not there once the field is full.

    Do not put a display utility (d-flex...) on an element that carries x-show or :hidden: the utility wins and it never hides.
--}}
<template x-if="control(field) === 'files'">
    <div role="group" :aria-labelledby="labelId(field)">
        <template x-if="isPhotoField(field)">
            <ul class="list-unstyled d-flex flex-wrap gap-2 m-0 mb-2">
                <template x-for="file in filesOf(field)" :key="file.id">
                    <li class="app-photo-tile">
                        <a :href="file.href" :target="file.target" rel="noopener noreferrer">
                            <template x-if="file.thumb">
                                <img class="app-thumb-lg" :src="file.thumb" :alt="file.name" width="72" height="72" loading="lazy" decoding="async">
                            </template>
                            <template x-if="!file.thumb">
                                <span class="app-thumb-lg app-file-icon" role="img" :aria-label="file.name">
                                    @include('partials.file-icon')
                                </span>
                            </template>
                        </a>
                        <button type="button" class="btn-close app-tile-remove" :aria-label="removeFileLabel(file)" :title="removeFileLabel(file)" :disabled="busy || uploading" @click="askRemove(file)"></button>
                    </li>
                </template>
            </ul>
        </template>

        <template x-if="!isPhotoField(field)">
            <ul class="list-unstyled m-0 mb-2">
                <template x-for="file in filesOf(field)" :key="file.id">
                    <li class="d-flex align-items-center gap-2 py-1">
                        <span class="app-file-icon text-secondary" aria-hidden="true">
                            @include('partials.file-icon')
                        </span>
                        <a class="app-grow text-break" :href="file.href" :target="file.target" rel="noopener noreferrer" x-text="file.name"></a>
                        <span class="text-secondary small text-nowrap" x-text="file.sizeText"></span>
                        <button type="button" class="btn-close" :aria-label="removeFileLabel(file)" :title="removeFileLabel(file)" :disabled="busy || uploading" @click="askRemove(file)"></button>
                    </li>
                </template>
            </ul>
        </template>

        {{-- Chosen for an item that does not exist yet: nothing is sent until it is saved. --}}
        <ul class="list-unstyled m-0 mb-2">
            <template x-for="file in stagedOf(field)" :key="file.id">
                <li class="d-flex align-items-center gap-2 py-1">
                    <span class="app-file-icon text-secondary" aria-hidden="true">
                        @include('partials.file-icon')
                    </span>
                    <span class="app-grow text-break" x-text="file.name"></span>
                    <span class="text-secondary small text-nowrap" x-text="file.sizeText"></span>
                    <button type="button" class="btn-close" :aria-label="removeFileLabel(file)" :title="removeFileLabel(file)" :disabled="busy" @click="unstage(file)"></button>
                </li>
            </template>
        </ul>

        <ul class="list-unstyled m-0 mb-2">
            <template x-for="upload in uploadsOf(field)" :key="upload.key">
                <li>
                    @include('partials.item-upload-row')
                </li>
            </template>
        </ul>

        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm" :id="controlId(field)" x-show="canAddTo(field)" @click="pickFiles(field.id)">
                <x-tabler-plus aria-hidden="true" />
                <span x-text="addLabel(field)"></span>
            </button>
            <span class="text-secondary small" x-text="countText(field)"></span>
        </div>
        <div class="form-hint" x-text="fileHint"></div>
    </div>
</template>
