{{--
    The scanner panel: the picture of the camera while it looks for a barcode, a button to read a photo instead, and a button
    to give up. It is shown only while a scan is running or has something to say (resources/js/components/barcode-scanner.js).

    Set inside a dialog of the page of an inventory (app/inventory.blade.php), once per use: $scannerSlot names it
    ("field" in the dialog of an item, "search" in the dialog of the toolbar). The page asks it to start through the window
    (barcode-scan-start, barcode-scan-stop) and hears it through bubbling events (barcode-scanned, barcode-scan-cancelled): see
    resources/js/scanner/events.js. It is a scope of Alpine of its own, so its refs never collide with the page's, and it is
    not a form: its buttons never submit the form of the dialog it may sit in.

    The photo button opens the file chooser of the browser, which is the camera app on a phone. It is the way for whoever has
    no camera, did not allow it, or opens the page without HTTPS (the browser only gives the camera to a secure page).

    Escape closes the panel and not the dialog around it (the panel holds the cursor while it is open).
    Do not put a display utility (d-flex...) on an element that carries :hidden: the utility wins and it never hides.
--}}
<div
    x-data="barcodeScanner"
    data-slot="{{ $scannerSlot }}"
    :hidden="!open"
    @barcode-scan-start.window="start"
    @barcode-scan-stop.window="stop"
    @pagehide.window="stop"
    @keydown.escape.stop.prevent="cancel"
>
    <div :hidden="!streaming">
        <video class="app-scan-video" x-ref="video" playsinline muted autoplay aria-hidden="true"></video>
        <p class="text-secondary text-center small mt-2 mb-0">{{ __('Point the camera at the barcode.') }}</p>
    </div>

    <div class="text-secondary text-center py-4" :hidden="!loading" role="status">
        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
        <span x-text="loadingText"></span>
    </div>

    <div class="alert alert-warning mb-0" role="alert" :hidden="!hasMessage" x-text="message"></div>

    <div class="btn-list justify-content-center mt-3">
        <button type="button" class="btn" @click="choosePhoto">
            <x-tabler-camera aria-hidden="true" />
            {{ __('Take a photo or choose an image') }}
        </button>
        <button type="button" class="btn" x-ref="cancel" data-autofocus @click="cancel">{{ __('Cancel') }}</button>
    </div>

    <input type="file" accept="image/*" capture="environment" hidden tabindex="-1" x-ref="photo" @change="onPhoto">
</div>
