/**
 * Alpine component of the scanner panel: it reads a barcode with the camera, or from a photo, and hands the code to the
 * page that asked.
 *
 * The panel (`partials/barcode-scanner.blade.php`) is set inside a dialog of the page, once per use (the item dialog, the
 * search). It is a scope of its own: the page does not reach into it, they talk through the events of `../scanner/events.js`
 * (the page asks it to start and to stop through the window, it answers with a bubbling event). `data-slot` names the panel,
 * and a message for another slot is not its business.
 *
 * With the camera, the video is looked at about every 150 ms: a pass starts when the previous one has ended (a slow phone
 * is never asked for two at once), and the first code found stops every track of the camera and goes to the page. The photo
 * (the file chooser, which opens the camera app of a phone) is the way for whoever has no camera, no permission, or no
 * secure connection, and it is decoded the same way. The reader itself is loaded on the first scan (`../scanner/decoder.js`).
 *
 * The camera is let go whatever happens: when the page asks to stop, on a result, on Cancel or Escape, when the dialog that
 * holds the panel is closed, when the page is left or put in the cache, and when the panel is taken apart. An answer that
 * arrives after any of those (a permission granted late, a pass that was running) finds its ticket out of date and lets the
 * camera it was given go at once.
 */
import { t } from '../lib/i18n.js';
import { onModalHidden } from '../lib/modal.js';
import { CAMERA_CONSTRAINTS, cameraAllowed, cameraErrorKey, stopStream } from '../scanner/camera.js';
import { loadDetector } from '../scanner/decoder.js';
import { SCAN_CANCELLED, SCAN_DONE } from '../scanner/events.js';
import { formatsFor, normalizeScan } from '../scanner/formats.js';

/** The pause between two passes over the video, in milliseconds. */
export const SCAN_INTERVAL_MS = 150;

/** How many passes in a row may fail before the reader is declared broken (a frame that cannot be read is no failure of the reader). */
const MAX_FAILURES = 10;

/** `HTMLMediaElement.HAVE_CURRENT_DATA`: the video holds a frame to look at. */
const HAVE_CURRENT_DATA = 2;

/**
 * @typedef {import('../scanner/decoder.js').DetectedBarcode} DetectedBarcode
 */

/**
 * The first code of a list that holds something.
 *
 * @param {DetectedBarcode[]} codes
 * @returns {DetectedBarcode | undefined}
 */
function firstCode(codes) {
    return codes.find((code) => String(code.rawValue ?? '').trim() !== '');
}

/**
 * @returns {Record<string, any>}
 */
export default function barcodeScanner() {
    // What a scan holds is kept out of the state of the component on purpose: Alpine wraps the objects of the state in
    // proxies, and the reader (a class with private fields) cannot be called through one.
    /** @type {HTMLElement | null} The element the component is set on, where the answers are dispatched from. */
    let root = null;
    /** @type {MediaStream | null} The stream of the camera while it is on. */
    let stream = null;
    /** @type {import('../scanner/decoder.js').Detector | null} The reader, once it is loaded. */
    let detector = null;
    /** @type {ReturnType<typeof setTimeout> | null} The timer of the pause between two passes, null when none is running. */
    let timer = null;

    return {
        /** Which panel of the page this is (`data-slot`). */
        slot: '',

        /** The `format` option of the barcode field being filled in; null for a search. */
        format: null,

        /** Whether the panel is shown. */
        open: false,

        /** True while the camera or the reader is starting, or a photo is being read. */
        loading: false,

        /** What the panel says while it is loading. */
        loadingText: '',

        /** True while the camera is on and its picture is shown. */
        streaming: false,

        /** Why there is nothing to scan (or nothing was found), in words; empty when all is well. */
        message: '',

        /** Ticket of the last start: whatever was started before it is out of date and lets go of what it holds. */
        ticket: 0,

        /** How many passes in a row have failed. */
        failures: 0,

        /** Whether the panel has something to tell. */
        get hasMessage() {
            return this.message !== '';
        },

        /**
         * Reads the name of the panel, and closes it whenever the dialog that holds it is closed, however it was (a
         * button, Escape, a click outside): the camera must not outlive the dialog.
         *
         * @returns {void}
         */
        init() {
            root = this.$el;
            this.slot = this.$el.dataset.slot ?? '';

            const dialog = this.$el.closest('.modal');

            if (dialog instanceof HTMLElement) {
                onModalHidden(dialog, () => this.stop());
            }
        },

        /**
         * Lets go of the camera when the panel is taken apart.
         *
         * @returns {void}
         */
        destroy() {
            this.halt();
        },

        /**
         * Opens the panel and starts looking for a code (the page asked, for this slot). The reader is loaded while the
         * person answers the question of the browser about the camera.
         *
         * @param {CustomEvent} event `detail`: `{ slot, format }`.
         * @returns {Promise<void>}
         */
        async start(event) {
            if (event.detail?.slot !== this.slot) {
                return;
            }

            this.halt();

            const ticket = this.ticket;

            this.format = event.detail.format ?? null;
            this.message = '';
            this.open = true;
            this.$nextTick(() => this.$refs.cancel?.focus());

            if (!cameraAllowed()) {
                this.message = t('scanner.error.insecure');

                return;
            }

            this.loading = true;
            this.loadingText = t('scanner.starting');

            const reader = loadDetector(formatsFor(this.format));

            // Its failure is told below, once the camera has answered; until then nobody is waiting for it.
            reader.catch(() => {});

            let camera;

            try {
                camera = await navigator.mediaDevices.getUserMedia(CAMERA_CONSTRAINTS);
            } catch (error) {
                if (ticket === this.ticket) {
                    this.fail(t(cameraErrorKey(error)));
                }

                return;
            }

            if (ticket !== this.ticket) {
                stopStream(camera);

                return;
            }

            stream = camera;

            try {
                detector = await reader;
            } catch {
                if (ticket === this.ticket) {
                    this.fail(t('scanner.error.reader'));
                }

                return;
            }

            if (ticket !== this.ticket) {
                return;
            }

            const video = this.$refs.video;

            video.muted = true;
            video.srcObject = camera;
            this.loading = false;
            this.streaming = true;
            video.play()?.catch(() => {});
            timer = setTimeout(() => this.scan(ticket), SCAN_INTERVAL_MS);
        },

        /**
         * One pass over the picture of the camera: looks for a code in the frame on screen, and plans the next pass once
         * this one is over. Nothing is looked at before the camera has a frame to give.
         *
         * @param {number} ticket The start this pass belongs to.
         * @returns {Promise<void>}
         */
        async scan(ticket) {
            timer = null;

            if (ticket !== this.ticket || detector === null) {
                return;
            }

            const video = this.$refs.video;

            if (video.readyState >= HAVE_CURRENT_DATA && video.videoWidth > 0) {
                try {
                    const found = firstCode(await detector.detect(video));

                    if (ticket !== this.ticket) {
                        return;
                    }

                    this.failures = 0;

                    if (found !== undefined) {
                        this.deliver(found);

                        return;
                    }
                } catch {
                    if (ticket !== this.ticket) {
                        return;
                    }

                    this.failures += 1;

                    if (this.failures >= MAX_FAILURES) {
                        this.fail(t('scanner.error.reader'));

                        return;
                    }
                }
            }

            timer = setTimeout(() => this.scan(ticket), SCAN_INTERVAL_MS);
        },

        /**
         * Opens the file chooser (the camera app on a phone).
         *
         * @returns {void}
         */
        choosePhoto() {
            this.$refs.photo.click();
        },

        /**
         * Reads the code of a photo the person took or picked. The camera is let go first: the photo replaces the live view.
         *
         * @param {Event} event The `change` event of the file input.
         * @returns {Promise<void>}
         */
        async onPhoto(event) {
            const input = /** @type {HTMLInputElement} */ (event.target);
            const file = input.files?.[0] ?? null;

            // Forgotten at once, so that the same photo can be chosen again.
            input.value = '';

            if (file === null) {
                return;
            }

            this.halt();

            const ticket = this.ticket;

            this.message = '';
            this.loading = true;
            this.loadingText = t('scanner.reading');

            let reader;

            try {
                reader = await loadDetector(formatsFor(this.format));
            } catch {
                if (ticket === this.ticket) {
                    this.fail(t('scanner.error.reader'));
                }

                return;
            }

            let found;

            try {
                found = firstCode(await reader.detect(file));
            } catch {
                if (ticket === this.ticket) {
                    this.fail(t('scanner.error.unreadable'));
                }

                return;
            }

            if (ticket !== this.ticket) {
                return;
            }

            if (found === undefined) {
                this.fail(t('scanner.error.not_found'));
            } else {
                this.deliver(found);
            }
        },

        /**
         * A code was read: the camera is let go, the panel closes and the page gets the code the field takes.
         *
         * @param {DetectedBarcode} found
         * @returns {void}
         */
        deliver(found) {
            const value = normalizeScan(found.rawValue, found.format, this.format);

            this.stop();
            this.announce(SCAN_DONE, { slot: this.slot, value });
        },

        /**
         * Something prevents the scan: the camera is let go and the panel stays open with the reason, for the person to
         * pick a photo instead or to give up.
         *
         * @param {string} message
         * @returns {void}
         */
        fail(message) {
            this.halt();
            this.message = message;
        },

        /**
         * The person gives up (the Cancel button, Escape): the panel closes and the page is told.
         *
         * @returns {void}
         */
        cancel() {
            if (!this.open) {
                return;
            }

            this.stop();
            this.announce(SCAN_CANCELLED, { slot: this.slot });
        },

        /**
         * Lets go of the camera and closes the panel: the page asked for it (for this slot, or for every panel), the dialog
         * that holds the panel was closed, or the page is being left.
         *
         * @param {CustomEvent} [event] `detail.slot`, when the page asked for one panel only.
         * @returns {void}
         */
        stop(event) {
            const slot = event?.detail?.slot;

            if (typeof slot === 'string' && slot !== this.slot) {
                return;
            }

            this.halt();
            this.open = false;
            this.message = '';
        },

        /**
         * Lets go of everything a scan holds: the pending pass, the camera, the picture. Whatever was started before is out
         * of date from now on.
         *
         * @returns {void}
         */
        halt() {
            this.ticket += 1;

            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }

            stopStream(stream);

            const video = this.$refs?.video;

            if (video) {
                video.srcObject = null;
            }

            stream = null;
            detector = null;
            this.streaming = false;
            this.loading = false;
            this.failures = 0;
        },

        /**
         * Tells the page something, from the root of the panel so that the event bubbles up to the page.
         *
         * @param {string} name
         * @param {Record<string, any>} detail
         * @returns {void}
         */
        announce(name, detail) {
            root?.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
        },
    };
}
