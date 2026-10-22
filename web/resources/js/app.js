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

import accountData from './components/account-data.js';
import accountDeletion from './components/account-deletion.js';
import accountMenu from './components/account-menu.js';
import accountProfile from './components/account-profile.js';
import accountSecurity from './components/account-security.js';
import accountTokens from './components/account-tokens.js';
import adminSettings from './components/admin-settings.js';
import adminUsers from './components/admin-users.js';
import barcodeScanner from './components/barcode-scanner.js';
import flashNotice from './components/flash-notice.js';
import installer from './components/installer.js';
import installerToken from './components/installer-token.js';
import inventoryFields from './components/inventory-fields.js';
import inventoryHeader from './components/inventory-header.js';
import inventoryItems from './components/inventory-items.js';
import inventorySharing from './components/inventory-sharing.js';
import inventoryVersions from './components/inventory-versions.js';
import invitationsLink from './components/invitations-link.js';
import localeSwitcher from './components/locale-switcher.js';
import login from './components/login.js';
import myInvitations from './components/my-invitations.js';
import toasts from './components/toasts.js';
import workspaceInventories from './components/workspace-inventories.js';
import workspaceInvitations from './components/workspace-invitations.js';
import workspaceMembers from './components/workspace-members.js';
import workspaceSwitcher from './components/workspace-switcher.js';
import workspaces from './components/workspaces.js';
import { reloadWhenRestored } from './lib/restore.js';
import { registerServiceWorker } from './lib/service-worker.js';
import { createToastsStore } from './stores/toasts.js';
import { createWorkspacesStore } from './stores/workspaces.js';

window.Alpine = Alpine;

// A page restored from the back/forward cache comes back with the state it was left in: reload it instead.
reloadWhenRestored();

// The service worker only adds the offline page: it is registered once the page has loaded.
registerServiceWorker();

Alpine.store('toasts', createToastsStore());
Alpine.store('workspaces', createWorkspacesStore());

Alpine.data('accountData', accountData);
Alpine.data('accountDeletion', accountDeletion);
Alpine.data('accountMenu', accountMenu);
Alpine.data('accountProfile', accountProfile);
Alpine.data('accountSecurity', accountSecurity);
Alpine.data('accountTokens', accountTokens);
Alpine.data('adminSettings', adminSettings);
Alpine.data('adminUsers', adminUsers);
Alpine.data('barcodeScanner', barcodeScanner);
Alpine.data('flashNotice', flashNotice);
Alpine.data('installer', installer);
Alpine.data('installerToken', installerToken);
Alpine.data('inventoryFields', inventoryFields);
Alpine.data('inventoryHeader', inventoryHeader);
Alpine.data('inventoryItems', inventoryItems);
Alpine.data('inventorySharing', inventorySharing);
Alpine.data('inventoryVersions', inventoryVersions);
Alpine.data('invitationsLink', invitationsLink);
Alpine.data('localeSwitcher', localeSwitcher);
Alpine.data('login', login);
Alpine.data('myInvitations', myInvitations);
Alpine.data('toasts', toasts);
Alpine.data('workspaceInventories', workspaceInventories);
Alpine.data('workspaceInvitations', workspaceInvitations);
Alpine.data('workspaceMembers', workspaceMembers);
Alpine.data('workspaceSwitcher', workspaceSwitcher);
Alpine.data('workspaces', workspaces);

Alpine.start();
