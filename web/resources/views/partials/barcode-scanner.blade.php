{{--
    The scanner panel: the picture of the camera while it looks for a barcode, a button to read a photo instead, and a button
    to give up. Its parts are shown only while a scan is running or has something to say (resources/js/components/barcode-scanner.js).

    Set inside a dialog of the page of an inventory (app/inventory.blade.php), once per use: $scannerSlot names it
    ("field" in the dialog of an item, "search" in the dialog of the toolbar). The page asks it to start through the window
    (barcode-scan-start, barcode-scan-stop) and hears it through bubbling events (barcode-scanned, barcode-scan-cancelled): see
    resources/js/scanner/events.js. It is a scope of Alpine of its own, so its refs never collide with the page's, and it is
    not a form: its buttons never submit the form of the dialog it may sit in.

    The photo button opens the file chooser of the browser: on a phone it offers the camera app and the gallery (an input with
    "capture" would only offer the camera). It is the way for whoever has no camera, did not allow it, or opens the page without
    HTTPS (the browser only gives the camera to a secure page).

    Everything the panel tells (the aim, the wait, the reasons it cannot scan, a code that is not the field's) is the text of one
    live region that is always on the page, with no hidden element around it (the panel itself is not hidden, its parts are): a
    screen reader only reads the changes of a region that was there before, and a region that appears together with its text is
    not reliably announced. The region is empty while the panel does nothing. When the page was hidden the camera is let go and
    the button "Restart the camera" appears.

    Escape closes the panel and not the dialog around it (the panel holds the cursor while it is open).
    Do not put a display utility (d-flex...) on an element that carries :hidden: the utility wins and it never hides.
--}}
<div
    x-data="barcodeScanner"
    data-slot="{{ $scannerSlot }}"
    @barcode-scan-start.window="start"
    @barcode-scan-stop.window="stop"
    @pagehide.window="stop"
    @keydown.escape.stop.prevent="cancel"
>
    <div :hidden="!streaming">
        <video class="app-scan-video" x-ref="video" playsinline muted autoplay aria-hidden="true"></video>
    </div>

    <div class="text-secondary text-center pt-4" :hidden="!loading">
        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
    </div>

    <div aria-live="polite" aria-atomic="true">
        <p class="mb-0" :class="statusClass" x-text="status"></p>
    </div>

    <div class="btn-list justify-content-center mt-3" :hidden="!open">
        <button type="button" class="btn btn-primary" :hidden="!paused" @click="restart">
            <x-tabler-refresh aria-hidden="true" />
            {{ __('Restart the camera') }}
        </button>
        <button type="button" class="btn" @click="choosePhoto">
            <x-tabler-camera aria-hidden="true" />
            {{ __('Take a photo or choose an image') }}
        </button>
        <button type="button" class="btn" x-ref="cancel" data-autofocus @click="cancel">{{ __('Cancel') }}</button>
    </div>

    <input type="file" accept="image/*" hidden tabindex="-1" x-ref="photo" @change="onPhoto">
</div>
