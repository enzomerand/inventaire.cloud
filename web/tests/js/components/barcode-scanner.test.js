/**
 * The scanner panel: it reads a code with the camera or from a photo, hands it to the page, and lets the camera go
 * whatever happens. The reader, the camera (`getUserMedia`) and the video are replaced: jsdom has none of them.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import barcodeScanner, { SCAN_INTERVAL_MS } from '../../../resources/js/components/barcode-scanner.js';
import { loadDetector } from '../../../resources/js/scanner/decoder.js';
import { prepareImage } from '../../../resources/js/scanner/photo.js';
import { deferred } from '../support/inventory.js';
import { forgetDialogs } from '../support/modal.js';

vi.mock('../../../resources/js/scanner/decoder.js', () => ({ loadDetector: vi.fn() }));
// jsdom loads no image: the photo goes to the reader as it is, unless a test says otherwise (see photo.test.js for the shrinking).
vi.mock('../../../resources/js/scanner/photo.js', () => ({ prepareImage: vi.fn(async (file) => file) }));
vi.mock('../../../resources/js/lib/modal.js', async () => import('../support/modal.js'));

const ORIGINAL_SECURE = Object.getOwnPropertyDescriptor(window, 'isSecureContext');
const ORIGINAL_DEVICES = Object.getOwnPropertyDescriptor(navigator, 'mediaDevices');

/** A code the reader found. */
const EAN = { rawValue: '3017620422003', format: 'ean_13' };

/** A track of the camera: its `stop` is watched, and a test can fire its `ended` event (`track.end()`). */
function fakeTrack() {
    const listeners = new Set();

    return {
        stop: vi.fn(),
        addEventListener: vi.fn((type, listener) => {
            if (type === 'ended') {
                listeners.add(listener);
            }
        }),
        removeEventListener: vi.fn((type, listener) => {
            if (type === 'ended') {
                listeners.delete(listener);
            }
        }),
        listeners,
        end: () => [...listeners].forEach((listener) => listener(new Event('ended'))),
    };
}

/** The camera: a stream with one track. */
function cameraStream() {
    const track = fakeTrack();

    return { track, getTracks: () => [track] };
}

/** A DOMException the way `getUserMedia` rejects with. */
const refusal = (name) => new DOMException('refused', name);

/** Makes the page a secure one (or not), whatever jsdom says. */
function setSecure(secure) {
    Object.defineProperty(window, 'isSecureContext', { configurable: true, value: secure });
}

/** Gives the browser a camera API (or none). */
function setDevices(devices) {
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: devices });
}

/** Puts back what the test replaced on `window` and `navigator`. */
function restoreBrowser() {
    for (const [owner, name, original] of [
        [window, 'isSecureContext', ORIGINAL_SECURE],
        [navigator, 'mediaDevices', ORIGINAL_DEVICES],
    ]) {
        if (original) {
            Object.defineProperty(owner, name, original);
        } else {
            delete owner[name];
        }
    }
}

/**
 * A panel in a dialog, set up as Alpine would hand it to the page: the element it is set on, its refs and a `$nextTick`.
 *
 * @param {string} [slot]
 * @returns {{ component: Record<string, any>, root: HTMLElement, dialog: HTMLElement, video: HTMLVideoElement, photo: HTMLInputElement, cancelButton: HTMLButtonElement }}
 */
function mountPanel(slot = 'field') {
    const dialog = document.createElement('div');
    const root = document.createElement('div');
    const video = document.createElement('video');
    const photo = document.createElement('input');
    const cancelButton = document.createElement('button');

    dialog.className = 'modal';
    root.dataset.slot = slot;
    photo.type = 'file';
    root.append(video, photo, cancelButton);
    dialog.append(root);
    document.body.append(dialog);

    // jsdom does not play media: the camera is given a frame to look at, and play() is a promise.
    Object.defineProperty(video, 'readyState', { configurable: true, value: 4 });
    Object.defineProperty(video, 'videoWidth', { configurable: true, value: 640 });
    video.play = vi.fn(() => Promise.resolve());

    const component = barcodeScanner();

    component.$el = root;
    component.$refs = { video, photo, cancel: cancelButton };
    component.$nextTick = (callback) => queueMicrotask(callback);
    component.init();

    return { component, root, dialog, video, photo, cancelButton };
}

/** The event the page sends to start a panel. */
const startEvent = (slot = 'field', format = null) =>
    new CustomEvent('barcode-scan-start', { detail: { slot, format } });

/** Lets the pending promises run, and the timers that are due. */
const settle = (ms = 0) => vi.advanceTimersByTimeAsync(ms);

/** The reader: answers the passes with the given results, one after the other (the last one stays). */
function reader(...answers) {
    const queue = [...answers];
    const detector = {
        detect: vi.fn(async () => {
            const answer = queue.length > 1 ? queue.shift() : queue[0];

            if (answer instanceof Error) {
                throw answer;
            }

            return answer;
        }),
    };

    loadDetector.mockResolvedValue(detector);

    return detector;
}

describe('the scanner panel', () => {
    let heard;
    let devices;
    let listeners;

    /** Listens to what the panel tells the page. */
    function listen() {
        heard = [];
        listeners = new AbortController();

        for (const name of ['barcode-scanned', 'barcode-scan-cancelled']) {
            document.body.addEventListener(name, (event) => heard.push([event.type, event.detail]), {
                signal: listeners.signal,
            });
        }
    }

    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '';
        loadDetector.mockReset();
        forgetDialogs();
        devices = { getUserMedia: vi.fn(async () => cameraStream()) };
        setDevices(devices);
        setSecure(true);
        listen();
    });

    afterEach(() => {
        listeners.abort();
        vi.useRealTimers();
        restoreBrowser();
    });

    describe('with the camera', () => {
        it('asks for the camera on the back, loads the reader for the symbologies of the field, and shows the picture', async () => {
            const { component, video } = mountPanel();
            const stream = cameraStream();

            devices.getUserMedia.mockResolvedValue(stream);
            reader([]);
            component.start(startEvent('field', 'ean8'));

            expect(component.open).toBe(true);
            expect(component.loading).toBe(true);
            expect(component.loadingText).toBe('scanner.starting');

            await settle();

            expect(devices.getUserMedia).toHaveBeenCalledWith({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });
            expect(loadDetector).toHaveBeenCalledWith(['ean_8']);
            expect(video.srcObject).toBe(stream);
            expect(video.muted).toBe(true);
            expect(video.play).toHaveBeenCalled();
            expect(component.loading).toBe(false);
            expect(component.streaming).toBe(true);
            expect(component.hasMessage).toBe(false);
        });

        it('loads the reader while the person answers the question of the browser', async () => {
            const { component } = mountPanel();
            const answer = deferred();

            devices.getUserMedia.mockReturnValue(answer.promise);
            reader([]);
            component.start(startEvent());
            await settle();

            // The camera has not answered yet, and the reader is already on its way.
            expect(loadDetector).toHaveBeenCalledTimes(1);
            expect(component.streaming).toBe(false);

            answer.resolve(cameraStream());
            await settle();

            expect(component.streaming).toBe(true);
        });

        it('looks about every 150 ms, and the first code ends it: tracks stopped, panel closed, code handed over', async () => {
            const { component, video } = mountPanel();
            const stream = cameraStream();
            const detector = reader([], [], [EAN]);

            devices.getUserMedia.mockResolvedValue(stream);
            component.start(startEvent());
            await settle();

            expect(detector.detect).not.toHaveBeenCalled();

            await settle(SCAN_INTERVAL_MS);
            expect(detector.detect).toHaveBeenCalledTimes(1);
            expect(detector.detect).toHaveBeenCalledWith(video);

            await settle(SCAN_INTERVAL_MS);
            expect(detector.detect).toHaveBeenCalledTimes(2);
            expect(heard).toEqual([]);

            await settle(SCAN_INTERVAL_MS);

            expect(detector.detect).toHaveBeenCalledTimes(3);
            expect(heard).toEqual([['barcode-scanned', { slot: 'field', value: '3017620422003' }]]);
            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(video.srcObject).toBeNull();
            expect(component.open).toBe(false);
            expect(component.streaming).toBe(false);

            // Nothing is looked at once it is over.
            await settle(5 * SCAN_INTERVAL_MS);
            expect(detector.detect).toHaveBeenCalledTimes(3);
        });

        it('hands over the code the field takes: the UPC-A of an EAN-13 with a leading 0, the ISBN-10 of a book', async () => {
            const upc = mountPanel('field');
            devices.getUserMedia.mockResolvedValue(cameraStream());
            reader([{ rawValue: '0012345678905', format: 'ean_13' }]);
            upc.component.start(startEvent('field', 'upca'));
            await settle(SCAN_INTERVAL_MS);

            expect(heard.at(-1)).toEqual(['barcode-scanned', { slot: 'field', value: '012345678905' }]);

            const book = mountPanel('field');
            devices.getUserMedia.mockResolvedValue(cameraStream());
            reader([{ rawValue: '9780306406157', format: 'ean_13' }]);
            book.component.start(startEvent('field', 'isbn10'));
            await settle(SCAN_INTERVAL_MS);

            expect(heard.at(-1)).toEqual(['barcode-scanned', { slot: 'field', value: '0306406152' }]);
        });

        it('tells the page from the element it sits in, so that the event bubbles up to it', async () => {
            const { component, root } = mountPanel();
            const onRoot = vi.fn();

            root.addEventListener('barcode-scanned', onRoot);
            devices.getUserMedia.mockResolvedValue(cameraStream());
            reader([EAN]);
            component.start(startEvent());
            await settle(SCAN_INTERVAL_MS);

            expect(onRoot).toHaveBeenCalledTimes(1);
            expect(onRoot.mock.calls[0][0].bubbles).toBe(true);
            expect(heard).toHaveLength(1);
        });

        it('ignores a start meant for another panel', async () => {
            const { component } = mountPanel('field');

            component.start(startEvent('search'));
            await settle();

            expect(devices.getUserMedia).not.toHaveBeenCalled();
            expect(component.open).toBe(false);
        });

        it('does not look before the camera has a picture', async () => {
            const { component, video } = mountPanel();
            const detector = reader([EAN]);

            Object.defineProperty(video, 'readyState', { configurable: true, value: 1 });
            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent());
            await settle(3 * SCAN_INTERVAL_MS);

            expect(detector.detect).not.toHaveBeenCalled();

            Object.defineProperty(video, 'readyState', { configurable: true, value: 4 });
            Object.defineProperty(video, 'videoWidth', { configurable: true, value: 0 });
            await settle(3 * SCAN_INTERVAL_MS);

            expect(detector.detect).not.toHaveBeenCalled();

            Object.defineProperty(video, 'videoWidth', { configurable: true, value: 640 });
            await settle(SCAN_INTERVAL_MS);

            expect(heard).toHaveLength(1);
        });

        it('never starts a pass while the previous one is still running', async () => {
            const { component } = mountPanel();
            const slow = deferred();
            const detector = reader([]);

            detector.detect.mockReturnValueOnce(slow.promise);
            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent());
            await settle(SCAN_INTERVAL_MS);

            expect(detector.detect).toHaveBeenCalledTimes(1);

            // A second without an end: no other pass starts, however long it takes.
            await settle(20 * SCAN_INTERVAL_MS);
            expect(detector.detect).toHaveBeenCalledTimes(1);

            slow.resolve([]);
            await settle();
            expect(detector.detect).toHaveBeenCalledTimes(1);

            await settle(SCAN_INTERVAL_MS);
            expect(detector.detect).toHaveBeenCalledTimes(2);
        });

        it('passes over codes with nothing in them', async () => {
            const { component } = mountPanel();

            reader([{ rawValue: '  ', format: 'qr_code' }], [{ rawValue: '', format: 'ean_13' }, EAN]);
            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent());
            await settle(SCAN_INTERVAL_MS);

            expect(heard).toEqual([]);

            await settle(SCAN_INTERVAL_MS);

            expect(heard).toEqual([['barcode-scanned', { slot: 'field', value: '3017620422003' }]]);
        });

        it('keeps looking after a picture the reader cannot read', async () => {
            const { component } = mountPanel();
            const detector = reader(new Error('InvalidStateError'), [EAN]);

            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent());
            await settle(SCAN_INTERVAL_MS);

            expect(component.hasMessage).toBe(false);
            expect(component.streaming).toBe(true);

            await settle(SCAN_INTERVAL_MS);

            expect(detector.detect).toHaveBeenCalledTimes(2);
            expect(heard).toHaveLength(1);
        });

        it('gives up when the reader fails again and again, and says so with the camera let go', async () => {
            const { component } = mountPanel();
            const stream = cameraStream();

            reader(new Error('boom'));
            devices.getUserMedia.mockResolvedValue(stream);
            component.start(startEvent());
            await settle(12 * SCAN_INTERVAL_MS);

            // Frames that cannot be read one after the other are a camera that gives nothing, not a reader that did not load.
            expect(component.message).toBe('scanner.error.camera');
            expect(component.open).toBe(true);
            expect(component.streaming).toBe(false);
            expect(stream.track.stop).toHaveBeenCalledTimes(1);
        });
    });

    describe('when the camera cannot be used', () => {
        it('says the camera needs HTTPS, without asking for it, and leaves the photo', async () => {
            setSecure(false);

            const { component } = mountPanel();

            component.start(startEvent());
            await settle();

            expect(devices.getUserMedia).not.toHaveBeenCalled();
            expect(loadDetector).not.toHaveBeenCalled();
            expect(component.open).toBe(true);
            expect(component.message).toBe('scanner.error.insecure');
            expect(component.loading).toBe(false);
            expect(component.streaming).toBe(false);
        });

        it('says the same when the browser has no camera API at all', async () => {
            setDevices(undefined);

            const { component } = mountPanel();

            component.start(startEvent());
            await settle();

            expect(component.message).toBe('scanner.error.insecure');
        });

        it.each([
            ['NotAllowedError', 'scanner.error.denied'],
            ['SecurityError', 'scanner.error.denied'],
            ['NotFoundError', 'scanner.error.no_camera'],
            ['OverconstrainedError', 'scanner.error.no_camera'],
            ['NotReadableError', 'scanner.error.busy'],
            ['AbortError', 'scanner.error.busy'],
            ['TypeError', 'scanner.error.camera'],
        ])('tells why when the camera answers %s', async (name, key) => {
            const { component } = mountPanel();

            reader([]);
            devices.getUserMedia.mockRejectedValue(refusal(name));
            component.start(startEvent());
            await settle();

            expect(component.message).toBe(key);
            expect(component.open).toBe(true);
            expect(component.loading).toBe(false);
            expect(component.streaming).toBe(false);
            expect(heard).toEqual([]);
        });

        it('has the failure of the reader handled even when the camera answers first and the reader is never waited for', async () => {
            const { component } = mountPanel();
            // `vi.fn` listens to the promises it returns, which would hide a rejection nobody handles: the handler is watched instead.
            const failing = Promise.reject(new Error('offline'));
            const handled = vi.spyOn(failing, 'catch');

            loadDetector.mockReturnValue(failing);
            devices.getUserMedia.mockRejectedValue(refusal('NotAllowedError'));
            component.start(startEvent());
            await settle();

            expect(handled).toHaveBeenCalledTimes(1);
            expect(component.message).toBe('scanner.error.denied');
        });

        it('lets the camera go and says so when the reader cannot be loaded', async () => {
            const { component } = mountPanel();
            const stream = cameraStream();

            loadDetector.mockRejectedValue(new Error('offline'));
            devices.getUserMedia.mockResolvedValue(stream);
            component.start(startEvent());
            await settle();

            expect(component.message).toBe('scanner.error.reader');
            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(component.streaming).toBe(false);
            expect(component.loading).toBe(false);
        });

        it('forgets the last message when it starts again', async () => {
            const { component } = mountPanel();

            devices.getUserMedia.mockRejectedValueOnce(refusal('NotAllowedError'));
            reader([]);
            component.start(startEvent());
            await settle();

            expect(component.hasMessage).toBe(true);

            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent());

            expect(component.message).toBe('');

            await settle();

            expect(component.streaming).toBe(true);
        });
    });

    describe('with a photo', () => {
        /** The file chooser answers with a file. */
        function choose(photo, file = new File(['x'], 'code.jpg', { type: 'image/jpeg' })) {
            Object.defineProperty(photo, 'files', { configurable: true, value: [file] });
            Object.defineProperty(photo, 'value', {
                configurable: true,
                writable: true,
                value: 'C:\\fakepath\\code.jpg',
            });

            return file;
        }

        /** Opens the panel on a page with no camera, where the photo is all there is. */
        async function openWithoutCamera(component) {
            setSecure(false);
            component.start(startEvent());
            await settle();
        }

        it('opens the file chooser', () => {
            const { component, photo } = mountPanel();
            const click = vi.spyOn(photo, 'click').mockImplementation(() => {});

            component.choosePhoto();

            expect(click).toHaveBeenCalledTimes(1);
        });

        it('reads the code of the photo, as the field takes it, and closes the panel', async () => {
            const { component, photo } = mountPanel();
            const detector = reader([{ rawValue: '0012345678905', format: 'ean_13' }]);
            const file = choose(photo);

            component.start(startEvent('field', 'upca'));
            await settle();
            loadDetector.mockClear();

            const reading = component.onPhoto({ target: photo });

            expect(component.loading).toBe(true);
            expect(component.loadingText).toBe('scanner.reading');

            await reading;

            expect(loadDetector).toHaveBeenCalledWith(['upc_a', 'ean_13']);
            expect(detector.detect).toHaveBeenCalledWith(file);
            expect(heard).toEqual([['barcode-scanned', { slot: 'field', value: '012345678905' }]]);
            expect(component.open).toBe(false);
            // The same photo can be chosen again.
            expect(photo.value).toBe('');
        });

        it('works with no camera at all: on an insecure page, or when the permission was refused', async () => {
            setSecure(false);

            const { component, photo } = mountPanel();

            reader([EAN]);
            component.start(startEvent());
            await settle();
            expect(component.message).toBe('scanner.error.insecure');

            choose(photo);
            await component.onPhoto({ target: photo });

            expect(component.message).toBe('');
            expect(heard).toEqual([['barcode-scanned', { slot: 'field', value: '3017620422003' }]]);
        });

        it('says no code was found, and stays open for another try', async () => {
            const { component, photo } = mountPanel();

            reader([]);
            await openWithoutCamera(component);
            choose(photo);
            await component.onPhoto({ target: photo });

            expect(component.message).toBe('scanner.error.not_found');
            expect(component.open).toBe(true);
            expect(component.loading).toBe(false);
            expect(heard).toEqual([]);

            // Another photo: the message goes while it is read.
            reader([EAN]);
            choose(photo);
            const again = component.onPhoto({ target: photo });

            expect(component.message).toBe('');

            await again;

            expect(heard).toHaveLength(1);
        });

        it('says the photo could not be read when the reader refuses it', async () => {
            const { component, photo } = mountPanel();

            reader(new Error('InvalidStateError'));
            await openWithoutCamera(component);
            choose(photo, new File(['not an image'], 'notes.txt', { type: 'text/plain' }));
            await component.onPhoto({ target: photo });

            expect(component.message).toBe('scanner.error.unreadable');
            expect(component.open).toBe(true);
            expect(heard).toEqual([]);
        });

        it('says the reader could not be loaded when it cannot be', async () => {
            const { component, photo } = mountPanel();

            loadDetector.mockRejectedValue(new Error('offline'));
            choose(photo);
            await component.onPhoto({ target: photo });

            expect(component.message).toBe('scanner.error.reader');
            expect(component.loading).toBe(false);
        });

        it('lets the camera go: the photo replaces the live picture', async () => {
            const { component, photo, video } = mountPanel();
            const stream = cameraStream();

            reader([]);
            devices.getUserMedia.mockResolvedValue(stream);
            component.start(startEvent());
            await settle();

            expect(component.streaming).toBe(true);

            choose(photo);
            await component.onPhoto({ target: photo });

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(video.srcObject).toBeNull();
            expect(component.streaming).toBe(false);
        });

        it('does nothing when the chooser was closed without a file', async () => {
            const { component, photo } = mountPanel();

            Object.defineProperty(photo, 'files', { configurable: true, value: [] });
            reader([EAN]);
            await component.onPhoto({ target: photo });

            expect(loadDetector).not.toHaveBeenCalled();
            expect(component.loading).toBe(false);
        });

        it('drops the answer of a photo when the panel was closed meanwhile', async () => {
            const { component, photo } = mountPanel();
            const slow = deferred();

            loadDetector.mockReturnValue(slow.promise);
            choose(photo);
            const reading = component.onPhoto({ target: photo });

            component.stop();
            slow.resolve({ detect: vi.fn(async () => [EAN]) });
            await reading;

            expect(heard).toEqual([]);
            expect(component.open).toBe(false);
        });
    });

    describe('letting the camera go', () => {
        /** A panel whose camera is on. */
        async function scanning(slot = 'field') {
            const panel = mountPanel(slot);
            const stream = cameraStream();
            const detector = reader([]);

            devices.getUserMedia.mockResolvedValue(stream);
            panel.component.start(startEvent(slot));
            await settle(SCAN_INTERVAL_MS);

            expect(panel.component.streaming).toBe(true);

            return { ...panel, stream, detector };
        }

        it('lets go when the page asks, and looks no more', async () => {
            const { component, stream, detector, video } = await scanning();

            component.stop(new CustomEvent('barcode-scan-stop', { detail: { slot: 'field' } }));

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(video.srcObject).toBeNull();
            expect(component.open).toBe(false);
            expect(component.streaming).toBe(false);

            const passes = detector.detect.mock.calls.length;

            await settle(10 * SCAN_INTERVAL_MS);
            expect(detector.detect).toHaveBeenCalledTimes(passes);
            expect(heard).toEqual([]);
        });

        it('lets go when the page asks every panel to stop, with no slot', async () => {
            const { component, stream } = await scanning();

            component.stop(new CustomEvent('barcode-scan-stop', { detail: {} }));

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(component.open).toBe(false);
        });

        it('keeps going when the page stops another panel', async () => {
            const { component, stream } = await scanning('field');

            component.stop(new CustomEvent('barcode-scan-stop', { detail: { slot: 'search' } }));

            expect(stream.track.stop).not.toHaveBeenCalled();
            expect(component.open).toBe(true);
            expect(component.streaming).toBe(true);
        });

        it('lets go when the page is left or put in the cache (pagehide)', async () => {
            const { component, stream } = await scanning();

            component.stop(new Event('pagehide'));

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(component.open).toBe(false);
        });

        it('lets go and tells the page when the person gives up', async () => {
            const { component, stream } = await scanning();

            component.cancel();

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(component.open).toBe(false);
            expect(heard).toEqual([['barcode-scan-cancelled', { slot: 'field' }]]);
        });

        it('tells the page nothing when it is cancelled while it is closed', () => {
            const { component } = mountPanel();

            component.cancel();

            expect(heard).toEqual([]);
        });

        it('lets go when the dialog that holds the panel is closed, however it was', async () => {
            const { stream, dialog, component } = await scanning();

            dialog.dispatchEvent(new Event('hidden.bs.modal'));

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(component.open).toBe(false);
            expect(heard).toEqual([]);
        });

        it('lets go when the panel is taken apart', async () => {
            const { component, stream } = await scanning();

            component.destroy();

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(component.streaming).toBe(false);
        });

        it('lets go of every track of a camera that has several', async () => {
            const { component } = mountPanel();
            const first = fakeTrack();
            const second = fakeTrack();

            reader([]);
            devices.getUserMedia.mockResolvedValue({ getTracks: () => [first, second] });
            component.start(startEvent());
            await settle();
            component.cancel();

            expect(first.stop).toHaveBeenCalledTimes(1);
            expect(second.stop).toHaveBeenCalledTimes(1);
        });

        it('lets go at once of a camera that is granted after the panel was closed', async () => {
            const { component, video } = mountPanel();
            const answer = deferred();
            const stream = cameraStream();

            reader([EAN]);
            devices.getUserMedia.mockReturnValue(answer.promise);
            component.start(startEvent());
            await settle();
            component.stop();
            answer.resolve(stream);
            await settle(5 * SCAN_INTERVAL_MS);

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(video.srcObject).toBeNull();
            expect(component.streaming).toBe(false);
            expect(heard).toEqual([]);
        });

        it('lets go of the camera of a start that a newer one replaced, and keeps the newer one', async () => {
            const { component, video } = mountPanel();
            const late = deferred();
            const first = cameraStream();
            const second = cameraStream();

            reader([]);
            devices.getUserMedia.mockReturnValueOnce(late.promise).mockResolvedValueOnce(second);
            component.start(startEvent());
            await settle();
            component.start(startEvent());
            await settle();

            expect(video.srcObject).toBe(second);

            late.resolve(first);
            await settle();

            expect(first.track.stop).toHaveBeenCalledTimes(1);
            expect(second.track.stop).not.toHaveBeenCalled();
            expect(video.srcObject).toBe(second);
        });

        it('lets go of the camera when the reader fails to load after it was granted late', async () => {
            const { component } = mountPanel();
            const reading = deferred();
            const stream = cameraStream();

            loadDetector.mockReturnValue(reading.promise);
            devices.getUserMedia.mockResolvedValue(stream);
            component.start(startEvent());
            await settle();
            component.stop();
            reading.reject(new Error('offline'));
            await settle();

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(component.message).toBe('');
        });

        it('lets go of the camera of the previous scan when it starts again', async () => {
            const { component, stream } = await scanning();
            const next = cameraStream();

            devices.getUserMedia.mockResolvedValue(next);
            component.start(startEvent());
            await settle();

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(next.track.stop).not.toHaveBeenCalled();
        });
    });
    describe('when the camera stops working', () => {
        /** A panel whose camera is on, looking at a reader that finds nothing. */
        async function scanning() {
            const panel = mountPanel();
            const stream = cameraStream();
            const detector = reader([]);

            devices.getUserMedia.mockResolvedValue(stream);
            panel.component.start(startEvent());
            await settle(SCAN_INTERVAL_MS);

            return { ...panel, stream, detector };
        }

        it('says the camera does not work, and lets it go, when its track ends (unplugged, taken by another application)', async () => {
            const { component, stream } = await scanning();

            stream.track.end();

            expect(component.message).toBe('scanner.error.camera');
            expect(component.streaming).toBe(false);
            expect(component.open).toBe(true);
            expect(stream.track.stop).toHaveBeenCalledTimes(1);
        });

        it('also hears the track end while the reader is still loading', async () => {
            const { component } = mountPanel();
            const stream = cameraStream();

            loadDetector.mockReturnValue(new Promise(() => {}));
            devices.getUserMedia.mockResolvedValue(stream);
            component.start(startEvent());
            await settle();
            stream.track.end();

            expect(component.message).toBe('scanner.error.camera');
            expect(component.loading).toBe(false);
        });

        it('stops listening to the track when the camera is let go: a track that ends later is no business of the panel', async () => {
            const { component, stream } = await scanning();

            component.cancel();

            expect(stream.track.removeEventListener).toHaveBeenCalledWith('ended', expect.any(Function));
            expect(stream.track.listeners.size).toBe(0);

            stream.track.end();

            expect(component.message).toBe('');
        });

        it('says the camera does not work when the picture cannot be played, and lets the camera go', async () => {
            const { component, video } = mountPanel();
            const stream = cameraStream();

            video.play = vi.fn(() => Promise.reject(new DOMException('not allowed', 'NotAllowedError')));
            reader([]);
            devices.getUserMedia.mockResolvedValue(stream);
            component.start(startEvent());
            await settle();

            expect(component.message).toBe('scanner.error.camera');
            expect(component.streaming).toBe(false);
            expect(stream.track.stop).toHaveBeenCalledTimes(1);
        });

        it('does not blame the camera for a play() that a newer start interrupted', async () => {
            const { component, video } = mountPanel();
            const interrupted = deferred();

            video.play = vi.fn(() => interrupted.promise);
            reader([]);
            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent());
            await settle();
            component.stop();
            interrupted.reject(new DOMException('interrupted by a new load request', 'AbortError'));
            await settle();

            expect(component.message).toBe('');
        });
    });

    describe('when the page is hidden', () => {
        /** A panel whose camera is on. */
        async function scanning() {
            const panel = mountPanel();
            const stream = cameraStream();
            const detector = reader([]);

            devices.getUserMedia.mockResolvedValue(stream);
            panel.component.start(startEvent());
            await settle(SCAN_INTERVAL_MS);

            return { ...panel, stream, detector };
        }

        /** The page is hidden (or shown again), as the browser says it. */
        function setHidden(hidden) {
            Object.defineProperty(document, 'hidden', { configurable: true, value: hidden });
            document.dispatchEvent(new Event('visibilitychange'));
        }

        afterEach(() => {
            delete document.hidden;
        });

        it('lets the camera go and says so, instead of staying on a black picture', async () => {
            const { component, stream, video, detector } = await scanning();

            setHidden(true);

            expect(stream.track.stop).toHaveBeenCalledTimes(1);
            expect(video.srcObject).toBeNull();
            expect(component.streaming).toBe(false);
            expect(component.paused).toBe(true);
            expect(component.message).toBe('scanner.paused');
            expect(component.status).toBe('scanner.paused');
            // The panel stays open, with its buttons.
            expect(component.open).toBe(true);

            const passes = detector.detect.mock.calls.length;

            await settle(10 * SCAN_INTERVAL_MS);
            expect(detector.detect).toHaveBeenCalledTimes(passes);
        });

        it('leaves it that way when the page comes back: the person restarts the camera', async () => {
            const { component, stream } = await scanning();

            setHidden(true);
            setHidden(false);

            expect(component.paused).toBe(true);
            expect(component.message).toBe('scanner.paused');
            expect(devices.getUserMedia).toHaveBeenCalledTimes(1);
            expect(stream.track.stop).toHaveBeenCalledTimes(1);
        });

        it('starts the camera again, for the same field, when the person asks', async () => {
            const panel = mountPanel();
            const first = cameraStream();
            const second = cameraStream();

            reader([]);
            devices.getUserMedia.mockResolvedValueOnce(first).mockResolvedValueOnce(second);
            panel.component.start(startEvent('field', 'ean8'));
            await settle(SCAN_INTERVAL_MS);
            setHidden(true);
            setHidden(false);
            loadDetector.mockClear();

            panel.component.restart();
            await settle(SCAN_INTERVAL_MS);

            expect(devices.getUserMedia).toHaveBeenCalledTimes(2);
            expect(loadDetector).toHaveBeenCalledWith(['ean_8']);
            expect(panel.video.srcObject).toBe(second);
            expect(panel.component.streaming).toBe(true);
            expect(panel.component.paused).toBe(false);
            expect(panel.component.message).toBe('');
            expect(first.track.stop).toHaveBeenCalledTimes(1);
            expect(second.track.stop).not.toHaveBeenCalled();
        });

        it('lets go of a camera that was still being asked for', async () => {
            const { component } = mountPanel();
            const answer = deferred();
            const late = cameraStream();

            reader([]);
            devices.getUserMedia.mockReturnValue(answer.promise);
            component.start(startEvent());
            await settle();
            setHidden(true);

            expect(component.paused).toBe(true);
            expect(component.loading).toBe(false);

            answer.resolve(late);
            await settle();

            expect(late.track.stop).toHaveBeenCalledTimes(1);
            expect(component.streaming).toBe(false);
        });

        it('does not touch a photo that is being read: the camera is not on', async () => {
            const { component, photo } = mountPanel();
            const slow = deferred();

            loadDetector.mockReturnValue(slow.promise);
            Object.defineProperty(photo, 'files', {
                configurable: true,
                value: [new File(['x'], 'code.jpg', { type: 'image/jpeg' })],
            });
            const reading = component.onPhoto({ target: photo });

            setHidden(true);

            expect(component.paused).toBe(false);
            expect(component.loading).toBe(true);

            slow.resolve({ detect: vi.fn(async () => [EAN]) });
            await reading;

            expect(heard).toEqual([['barcode-scanned', { slot: 'field', value: EAN.rawValue }]]);
        });

        it('does nothing when the panel is closed, when the page is shown, or when the camera is not on', async () => {
            const { component } = mountPanel();

            setHidden(true);
            expect(component.paused).toBe(false);
            expect(component.message).toBe('');

            setHidden(false);
            expect(component.paused).toBe(false);

            setSecure(false);
            component.start(startEvent());
            await settle();
            setHidden(true);

            // The reason it could not scan stays the one the person was told.
            expect(component.message).toBe('scanner.error.insecure');
            expect(component.paused).toBe(false);
        });

        it('forgets the pause when the panel closes', async () => {
            const { component } = await scanning();

            setHidden(true);
            component.cancel();

            expect(component.paused).toBe(false);
            expect(component.message).toBe('');
        });

        it('stops listening to the page when the panel is taken apart', async () => {
            const { component } = await scanning();

            component.destroy();
            setHidden(true);

            expect(component.paused).toBe(false);
            expect(component.message).toBe('');
        });
    });

    describe('when it is taken apart', () => {
        it('stops listening to the closing of the dialog, which stays on the page', async () => {
            const { component, dialog } = mountPanel();

            reader([]);
            component.destroy();
            await component.start(startEvent());
            dialog.dispatchEvent(new Event('hidden.bs.modal'));

            // Had the panel still heard it, it would have closed.
            expect(component.open).toBe(true);
        });

        it('leaves no reader behind when a start that was replaced finishes loading it', async () => {
            const { component } = mountPanel();
            const slow = deferred();
            const detector = { detect: vi.fn(async () => []) };

            loadDetector.mockReturnValue(slow.promise);
            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent('field', 'ean8'));
            await settle();
            component.stop();
            slow.resolve(detector);
            await settle();

            // A pass that still ran would look at the camera of nobody with the symbologies of the field that was left.
            await component.scan(component.ticket);

            expect(detector.detect).not.toHaveBeenCalled();
            expect(component.streaming).toBe(false);
        });
    });

    describe("when a code is not the field's", () => {
        const BOTTLE = { rawValue: '3017620422003', format: 'ean_13' };
        const BOOK = { rawValue: '9780306406157', format: 'ean_13' };

        it('keeps looking with the camera, says so, and takes the next code that fits', async () => {
            const { component, video } = mountPanel();
            const detector = reader([BOTTLE], [BOTTLE], [BOOK]);

            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent('field', 'isbn13'));
            await settle(SCAN_INTERVAL_MS);

            expect(heard).toEqual([]);
            expect(component.streaming).toBe(true);
            expect(component.note).toBe('scanner.error.mismatch');
            expect(component.status).toBe('scanner.error.mismatch');
            expect(component.statusClass).toContain('alert-warning');
            expect(video.srcObject).not.toBeNull();

            await settle(2 * SCAN_INTERVAL_MS);

            expect(detector.detect).toHaveBeenCalledTimes(3);
            expect(heard).toEqual([['barcode-scanned', { slot: 'field', value: BOOK.rawValue }]]);
        });

        it('takes a code that fits even when the same picture holds one that does not', async () => {
            const { component } = mountPanel();

            reader([BOTTLE, BOOK]);
            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent('field', 'isbn13'));
            await settle(SCAN_INTERVAL_MS);

            expect(heard).toEqual([['barcode-scanned', { slot: 'field', value: BOOK.rawValue }]]);
        });

        it('forgets the note when it starts again', async () => {
            const { component } = mountPanel();

            reader([BOTTLE]);
            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent('field', 'isbn13'));
            await settle(SCAN_INTERVAL_MS);
            expect(component.note).toBe('scanner.error.mismatch');

            component.start(startEvent('field', 'isbn13'));

            expect(component.note).toBe('');
        });

        it('says so about a photo, and stays open for another', async () => {
            const { component, photo } = mountPanel();

            reader([BOTTLE]);
            Object.defineProperty(photo, 'files', {
                configurable: true,
                value: [new File(['x'], 'code.jpg', { type: 'image/jpeg' })],
            });
            component.format = 'isbn10';
            await component.onPhoto({ target: photo });

            expect(heard).toEqual([]);
            expect(component.message).toBe('scanner.error.mismatch');
            expect(component.open).toBe(false);
        });

        it('takes the code of a photo that fits', async () => {
            const { component, photo } = mountPanel();

            reader([BOOK]);
            Object.defineProperty(photo, 'files', {
                configurable: true,
                value: [new File(['x'], 'code.jpg', { type: 'image/jpeg' })],
            });
            component.start(startEvent('field', 'isbn10'));
            await settle();
            await component.onPhoto({ target: photo });

            expect(heard).toEqual([['barcode-scanned', { slot: 'field', value: '0306406152' }]]);
        });

        it('takes any code in a field that takes any, and in a search', async () => {
            const { component } = mountPanel('search');

            reader([{ rawValue: 'ABC-123', format: 'code_128' }]);
            devices.getUserMedia.mockResolvedValue(cameraStream());
            component.start(startEvent('search', null));
            await settle(SCAN_INTERVAL_MS);

            expect(heard).toEqual([['barcode-scanned', { slot: 'search', value: 'ABC-123' }]]);
        });
    });

    describe('the photo handed to the reader', () => {
        it('is the one prepared from the file (shrunk when it is large), and not the file itself', async () => {
            const { component, photo } = mountPanel();
            const detector = reader([EAN]);
            const file = new File(['x'], 'big.jpg', { type: 'image/jpeg' });
            const shrunk = document.createElement('canvas');

            prepareImage.mockResolvedValueOnce(shrunk);
            Object.defineProperty(photo, 'files', { configurable: true, value: [file] });
            await component.onPhoto({ target: photo });

            expect(prepareImage).toHaveBeenCalledWith(file);
            expect(detector.detect).toHaveBeenCalledWith(shrunk);
        });
    });

    describe('what the panel says', () => {
        it('is one text for the live region: the hint while the camera is on, the wait, the reason', async () => {
            const { component } = mountPanel();

            expect(component.status).toBe('');
            // No box and no margin while there is nothing to say.
            expect(component.statusClass).toBe('');

            devices.getUserMedia.mockResolvedValue(cameraStream());
            reader([]);
            component.start(startEvent());

            expect(component.status).toBe('scanner.starting');
            expect(component.statusClass).not.toContain('alert');

            await settle(SCAN_INTERVAL_MS);

            expect(component.status).toBe('scanner.hint');
            expect(component.statusClass).not.toContain('alert');

            component.fail('Something went wrong.');

            expect(component.status).toBe('Something went wrong.');
            expect(component.statusClass).toContain('alert-warning');
        });
    });
});
