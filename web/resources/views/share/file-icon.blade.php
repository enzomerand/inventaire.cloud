{{--
    The icon of a file that has no thumbnail, by the "icon" of the file (SharedFile): an image, a PDF document or any other file.
    Icons: never pass a class attribute to <x-tabler-*>; the parent sizes them (.app-file-icon).
--}}
@if ($file->icon === 'image')
    <x-tabler-photo aria-hidden="true" />
@elseif ($file->icon === 'pdf')
    <x-tabler-file-type-pdf aria-hidden="true" />
@else
    <x-tabler-file aria-hidden="true" />
@endif
