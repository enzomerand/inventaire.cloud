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
 * holds the panel is closed, when the page is left or put in the cache, when the page is hidden (another tab, a locked phone: the
 * panel then says so and offers to restart the camera, instead of showing a black picture), when the camera is taken away
 * (unplugged, or used by another application), and when the panel is taken apart. An answer that arrives after any of those (a
 * permission granted late, a pass that was running) finds its ticket out of date and lets the camera it was given go at once.
 *
 * What the panel tells the person (the aim, the wait, the reasons it cannot scan) goes through one `aria-live` region that is
 * always on the page, so that a screen reader reads each new text.
 */
import { t } from '../lib/i18n.js';
import { onModalHidden } from '../lib/modal.js';
import { CAMERA_CONSTRAINTS, cameraAllowed, cameraErrorKey, stopStream } from '../scanner/camera.js';
import { loadDetector } from '../scanner/decoder.js';
import { SCAN_CANCELLED, SCAN_DONE } from '../scanner/events.js';
import { fitsFormat, formatsFor, normalizeScan } from '../scanner/formats.js';
import { prepareImage } from '../scanner/photo.js';

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
 * The codes of a list that hold something.
 *
 * @param {DetectedBarcode[]} codes
 * @returns {DetectedBarcode[]}
 */
function readCodes(codes) {
    return codes.filter((code) => String(code.rawValue ?? '').trim() !== '');
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
    /** @type {Array<[MediaStreamTrack, () => void]>} The tracks of the camera that are listened to (`ended`), with their listener. */
    let watched = [];
    /** @type {(() => void) | null} Stops listening to the closing of the dialog that holds the panel. */
    let forgetDialog = null;
    /** @type {(() => void) | null} Stops listening to the page being hidden and shown. */
    let forgetVisibility = null;

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

        /** What the camera is told about the codes it sees while it keeps looking (a code that is not the field's); empty when nothing. */
        note: '',

        /** True while the camera is stopped because the page was hidden: the panel then offers to restart it. */
        paused: false,

        /** True while a photo is being read (the camera is not on, and the page being hidden is no reason to stop). */
        reading: false,

        /** Ticket of the last start: whatever was started before it is out of date and lets go of what it holds. */
        ticket: 0,

        /** How many passes in a row have failed. */
        failures: 0,

        /** Whether the panel has something to tell. */
        get hasMessage() {
            return this.message !== '';
        },

        /**
         * The text of the live region of the panel: why there is nothing to scan, what is being waited for, or what to aim at.
         * Empty while the panel is not doing anything.
         */
        get status() {
            if (this.message !== '') {
                return this.message;
            }

            if (this.loading) {
                return this.loadingText;
            }

            return this.streaming ? this.note || t('scanner.hint') : '';
        },

        /**
         * The classes of the text of the live region: a warning for a reason or a note, a quiet hint for the rest, and none (no
         * margin, no box) while there is nothing to say.
         */
        get statusClass() {
            if (this.status === '') {
                return '';
            }

            return this.message !== '' || this.note !== ''
                ? 'alert alert-warning mt-2'
                : 'text-secondary text-center small mt-2';
        },

        /**
         * Reads the name of the panel, and closes it whenever the dialog that holds it is closed, however it was (a
         * button, Escape, a click outside): the camera must not outlive the dialog. It also watches the page being hidden.
         *
         * @returns {void}
         */
        init() {
            root = this.$el;
            this.slot = this.$el.dataset.slot ?? '';

            const dialog = this.$el.closest('.modal');

            if (dialog instanceof HTMLElement) {
                forgetDialog = onModalHidden(dialog, () => this.stop());
            }

            const onVisibilityChange = () => this.pauseWhenHidden();

            document.addEventListener('visibilitychange', onVisibilityChange);
            forgetVisibility = () => document.removeEventListener('visibilitychange', onVisibilityChange);
        },

        /**
         * Lets go of the camera and of the listeners when the panel is taken apart (the dialog it sits in stays, and so does the
         * page): none of them may call a panel that is gone.
         *
         * @returns {void}
         */
        destroy() {
            this.halt();
            forgetDialog?.();
            forgetDialog = null;
            forgetVisibility?.();
            forgetVisibility = null;
        },

        /**
         * The page was hidden (another tab, a locked screen, another application): a camera that keeps running in the
         * background is drained battery and a light that stays on, and it comes back as a black picture. It is let go, and the
         * panel says so and offers to start it again.
         *
         * @returns {void}
         */
        pauseWhenHidden() {
            if (!document.hidden || !this.open || this.reading || !(this.streaming || this.loading)) {
                return;
            }

            this.fail(t('scanner.paused'));
            this.paused = true;
        },

        /**
         * Starts the camera again, after the panel let it go because the page was hidden.
         *
         * @returns {Promise<void>}
         */
        restart() {
            return this.start(
                new CustomEvent('barcode-scan-start', { detail: { slot: this.slot, format: this.format } }),
            );
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
            this.forgetStatus();
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
            this.watch(camera, ticket);

            let loaded;

            try {
                loaded = await reader;
            } catch {
                if (ticket === this.ticket) {
                    this.fail(t('scanner.error.reader'));
                }

                return;
            }

            // A start that is out of date leaves nothing behind: the reader it loaded (for the symbologies of its own field) is not kept.
            if (ticket !== this.ticket) {
                return;
            }

            detector = loaded;

            const video = this.$refs.video;

            video.muted = true;
            video.srcObject = camera;
            this.loading = false;
            this.streaming = true;
            // A picture that cannot be played is a camera that does not work, as much as one that cannot be opened.
            video.play()?.catch(() => {
                if (ticket === this.ticket) {
                    this.fail(t('scanner.error.camera'));
                }
            });
            timer = setTimeout(() => this.scan(ticket), SCAN_INTERVAL_MS);
        },

        /**
         * Listens to the tracks of the camera ending (it was unplugged, taken by another application, or the permission was
         * withdrawn): the panel then says the camera does not work, instead of staying on a frozen picture.
         *
         * @param {MediaStream} camera
         * @param {number} ticket The start the camera belongs to.
         * @returns {void}
         */
        watch(camera, ticket) {
            const onEnded = () => {
                if (ticket === this.ticket) {
                    this.fail(t('scanner.error.camera'));
                }
            };

            for (const track of camera.getTracks()) {
                track.addEventListener('ended', onEnded);
                watched.push([track, onEnded]);
            }
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
                    const codes = readCodes(await detector.detect(video));

                    if (ticket !== this.ticket) {
                        return;
                    }

                    this.failures = 0;

                    const found = codes.find((code) => fitsFormat(code.rawValue, this.format));

                    if (found !== undefined) {
                        this.deliver(found);

                        return;
                    }

                    // A code that is not the field's (the barcode of a bottle in front of an ISBN): the scan goes on, and says why
                    // as long as that code stays in sight.
                    this.note = codes.length > 0 ? t('scanner.error.mismatch') : '';
                } catch {
                    if (ticket !== this.ticket) {
                        return;
                    }

                    this.failures += 1;

                    // Frames that cannot be read one after the other: what the person sees is a camera that gives nothing to read.
                    if (this.failures >= MAX_FAILURES) {
                        this.fail(t('scanner.error.camera'));

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

            this.forgetStatus();
            this.reading = true;
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

            let codes;

            try {
                // A photo of several megapixels is shrunk first (see `prepareImage`): the reader gets a picture it can take.
                codes = readCodes(await reader.detect(await prepareImage(file)));
            } catch {
                if (ticket === this.ticket) {
                    this.fail(t('scanner.error.unreadable'));
                }

                return;
            }

            if (ticket !== this.ticket) {
                return;
            }

            const found = codes.find((code) => fitsFormat(code.rawValue, this.format));

            if (found !== undefined) {
                this.deliver(found);
            } else {
                this.fail(t(codes.length > 0 ? 'scanner.error.mismatch' : 'scanner.error.not_found'));
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
            this.forgetStatus();
        },

        /**
         * Forgets what the panel was telling: the reason it could not scan, the note on a code that was not the field's, the pause.
         *
         * @returns {void}
         */
        forgetStatus() {
            this.message = '';
            this.note = '';
            this.paused = false;
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

            for (const [track, listener] of watched) {
                track.removeEventListener('ended', listener);
            }

            watched = [];
            stopStream(stream);

            const video = this.$refs?.video;

            if (video) {
                video.srcObject = null;
            }

            stream = null;
            detector = null;
            this.streaming = false;
            this.loading = false;
            this.reading = false;
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
