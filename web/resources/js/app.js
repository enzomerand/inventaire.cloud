/**
 * Browser entry point.
 *
 * Loads Tabler (which bundles Bootstrap's behaviours such as dropdowns and
 * collapses through data attributes) and starts Alpine exactly once.
 *
 * Alpine's CSP build is used so a strict Content-Security-Policy (no
 * 'unsafe-eval') can be enforced. Its convention, to keep in mind when writing
 * templates: every component is registered here with `Alpine.data()` and
 * referenced by name (`x-data="toasts"`); expressions in templates are limited
 * to property access, simple calls, comparisons and ternaries. Anything else
 * (arrow functions, template literals, statements) becomes a method of the
 * component. Values for a component come from `data-*` attributes, never from
 * server-side interpolation inside an expression.
 */
import '@tabler/core';
import Alpine from '@alpinejs/csp';

import accountMenu from './components/account-menu.js';
import installer from './components/installer.js';
import installerToken from './components/installer-token.js';
import localeSwitcher from './components/locale-switcher.js';
import login from './components/login.js';
import toasts from './components/toasts.js';
import { createToastsStore } from './stores/toasts.js';

window.Alpine = Alpine;

Alpine.store('toasts', createToastsStore());

Alpine.data('accountMenu', accountMenu);
Alpine.data('installer', installer);
Alpine.data('installerToken', installerToken);
Alpine.data('localeSwitcher', localeSwitcher);
Alpine.data('login', login);
Alpine.data('toasts', toasts);

Alpine.start();
