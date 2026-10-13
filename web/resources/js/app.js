/**
 * Browser entry point.
 *
 * Loads Tabler (which bundles Bootstrap's behaviours such as dropdowns and
 * collapses through data attributes) and starts Alpine exactly once. Anything
 * the pages need from the JavaScript side is registered on Alpine here, so
 * Blade templates only ever reference names, never import modules.
 */
import '@tabler/core';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
