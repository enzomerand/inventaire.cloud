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

import { http } from './lib/http.js';
import { t } from './lib/i18n.js';
import localeSwitcher from './components/locale-switcher.js';
import { createToastsStore } from './stores/toasts.js';

window.Alpine = Alpine;

Alpine.store('toasts', createToastsStore());

// $http and $t give templates access to the shared helper and the server
// translations without any global variable.
Alpine.magic('http', () => http);
Alpine.magic('t', () => t);

Alpine.data('localeSwitcher', localeSwitcher);

Alpine.start();
