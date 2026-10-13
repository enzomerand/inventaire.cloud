import { defineConfig } from 'vitest/config';

// Kept apart from vite.config.js: tests need no Laravel plugin and must not
// trigger asset hot-reload settings.
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        clearMocks: true,
        unstubGlobals: true,
    },
});
