import { vi } from 'vitest';

/**
 * Waits until what the page asked to run on its next frame has run: an animation frame when the page is visible, a timer
 * otherwise. Alpine's `x-show` displays an element that way, and so does `revealFirstRefusalOnceShown()`. Under fake timers,
 * the clock is moved on by one frame instead.
 *
 * @returns {Promise<void>}
 */
export async function nextFrame() {
    if (vi.isFakeTimers()) {
        await vi.advanceTimersByTimeAsync(16);

        return;
    }

    await new Promise((resolve) => {
        const later =
            document.visibilityState === 'visible'
                ? requestAnimationFrame
                : (/** @type {() => void} */ run) => setTimeout(run);

        later(() => resolve());
    });
}
