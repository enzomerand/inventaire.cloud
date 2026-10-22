/**
 * The camera, as far as the scanner needs it: whether the browser may open it, what is asked of it, why it failed, and
 * how it is let go.
 */

/**
 * What is asked of the camera: the one on the back of a phone when there is a choice, and enough pixels to read the thin
 * bars of an EAN-13 (the default of some phones is 640 by 480).
 *
 * @type {MediaStreamConstraints}
 */
export const CAMERA_CONSTRAINTS = {
    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
    audio: false,
};

/**
 * Whether the browser can open the camera at all: only a secure context (HTTPS, or localhost) gets `mediaDevices`.
 *
 * @returns {boolean}
 */
export function cameraAllowed() {
    return window.isSecureContext && typeof navigator.mediaDevices?.getUserMedia === 'function';
}

/**
 * The message key that tells why the camera did not open.
 *
 * @param {unknown} error What `getUserMedia` rejected with.
 * @returns {string} A key of `ClientMessages`.
 */
export function cameraErrorKey(error) {
    switch (/** @type {{ name?: string } | null} */ (error)?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
        case 'PermissionDeniedError':
            return 'scanner.error.denied';
        case 'NotFoundError':
        case 'OverconstrainedError':
        case 'DevicesNotFoundError':
            return 'scanner.error.no_camera';
        case 'NotReadableError':
        case 'TrackStartError':
        case 'AbortError':
            return 'scanner.error.busy';
        default:
            return 'scanner.error.camera';
    }
}

/**
 * Lets the camera go: every track is stopped, which turns the indicator light off.
 *
 * @param {MediaStream | null} stream
 * @returns {void}
 */
export function stopStream(stream) {
    for (const track of stream?.getTracks() ?? []) {
        track.stop();
    }
}
