/**
 * Alpine component of the sharing page of an inventory: the share links of the owner, listed, made and revoked.
 *
 * A share link is a secret address that shows the inventory, read-only, to anybody who holds it. The list gives each link its label,
 * whether it still works or expired, whether the photos and files are shown, when it was made, last opened and expires, and its
 * address, which the owner copies from a read-only box (the API keeps it, encrypted, so it can be copied again later; when it cannot be
 * read back, the address is missing and the owner makes a new link). Revoking asks for a confirmation in the row itself and takes effect
 * at once: the address answers "not found" from then on.
 *
 * Making a link is a form that opens in the card (`creating`): an optional label, whether to show the files, and how long it works. The
 * creation is `silent` for the HTTP helper: its refusals (validation, the limit of links, sharing turned off, the pace of creations)
 * are shown in the form. The endpoint, whether the administrator allows sharing and the limit of links come from the markup (`data-*`
 * attributes on the root), because the CSP build of Alpine does not allow Blade to inject values into expressions.
 */
import { momentText } from '../grid/rows.js';
import { announce, copyValue } from '../lib/feedback.js';
import { firstMessages, throttledMessage } from '../lib/forms.js';
import { HttpError, http, scheduleNavigation } from '../lib/http.js';
import { t } from '../lib/i18n.js';
import { notify } from '../lib/notify.js';
import { reloadPage } from '../lib/page.js';

/** The lifetime the form offers first, in days: the value of the option of the select (the API's own default). */
const DEFAULT_EXPIRY = '30';

/**
 * @typedef {object} ShareLink A share link, as `ShareLinkResource` describes it.
 * @property {string} id
 * @property {string | null} label
 * @property {string | null} url The address to give out; null when it can no longer be read back.
 * @property {boolean} include_files
 * @property {string | null} expires_at ISO 8601; null for a link that never expires.
 * @property {string | null} last_used_at ISO 8601; null for a link nobody opened.
 * @property {string | null} created_at ISO 8601.
 * @property {{ id: string, name: string, is_owner: boolean } | null} created_by `is_owner`: whether that account is still an owner of the workspace; null once the account was deleted.
 */

/**
 * @returns {Record<string, any>}
 */
export default function inventorySharing() {
    return {
        /** The links of the inventory, the newest first, the expired ones included. */
        links: [],

        /** True while the list is being read (and until it was, so that the empty message does not flash). */
        loading: true,

        /** True when the list could not be read: the card then offers to try again. */
        loadFailed: false,

        /** True while the creation is in flight; blocks double submits. */
        busy: false,

        /** Whether the creation form is open. */
        creating: false,

        /** Whether the administrator allows sharing; off, no link can be made and the existing ones do not work. */
        enabled: true,

        /** How many links an inventory may have, from `data-limit` (0 when the page does not say). */
        limit: 0,

        /** What was typed and chosen in the creation form: `label`, `includeFiles` and `expires` (days, "" for never). */
        form: { label: '', includeFiles: true, expires: DEFAULT_EXPIRY },

        /** The messages of a refused creation, under their fields: `label`, `expires` ("" when there is none). */
        errors: {},

        /** Why the creation was refused when it is not about a field ("" when it was not). */
        formError: '',

        /** Id of the link whose address was copied last. */
        copiedId: null,

        /** Whether that address was copied just now (its button says so for a moment). */
        copied: false,

        /** Id of the link waiting for the confirmation of its revocation, or null. */
        confirmingId: null,

        /** Id of the link being revoked (request in flight), or null. */
        revokingId: null,

        /** What assistive technology is told, in the card's polite live region ("" when nothing). */
        announcement: '',

        /** Root element of the component, kept from `init()` because `$root` is gone once an element has left the page. */
        rootElement: null,

        /** Timer that takes "Copied" off the button. */
        copiedTimer: null,

        /** Endpoint of the links (GET, POST, and DELETE on `/{id}`), from `data-url` on the component root. */
        get url() {
            return this.rootElement.dataset.url;
        },

        /** Whether the inventory has as many links as it may (the API refuses the next one): the expired ones do not count. */
        get atLimit() {
            return this.limit > 0 && this.links.filter((link) => !this.isExpired(link)).length >= this.limit;
        },

        /** Whether the creation form can be submitted: nothing is in flight and there is room for another link. */
        get canCreate() {
            return !this.busy && !this.atLimit;
        },

        /** Whether the list is known to be empty (the empty message is not shown while it is read, or when it failed). */
        get empty() {
            return !this.loading && !this.loadFailed && this.links.length === 0;
        },

        /** The sentence that says the inventory has all the links it may, "" while it has room. */
        get limitText() {
            return this.atLimit ? t('sharing.error.limit', { count: this.limit }) : '';
        },

        /**
         * Reads what the page was rendered with, then the links.
         *
         * @returns {Promise<void>}
         */
        async init() {
            this.rootElement = this.$root;
            this.enabled = this.rootElement.dataset.enabled !== '0';
            this.limit = Number(this.rootElement.dataset.limit ?? 0) || 0;

            await this.load();
        },

        /**
         * Stops the timer that makes the button forget it copied, when the page is taken apart.
         *
         * @returns {void}
         */
        destroy() {
            clearTimeout(this.copiedTimer);
            this.copiedTimer = null;
        },

        /**
         * Reads the links from the API.
         *
         * @returns {Promise<void>}
         */
        async load() {
            this.loading = true;
            this.loadFailed = false;

            try {
                const answer = await http.get(this.url);

                this.links = answer.data;
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (!(error instanceof HttpError)) {
                    return;
                }

                this.loadFailed = true;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Opens the creation form, with the lifetime and the choice about the files it offers first.
         *
         * @returns {void}
         */
        open() {
            this.creating = true;
            this.focus('label');
        },

        /**
         * Gives up the creation: closes the form and forgets what was typed.
         *
         * @returns {void}
         */
        cancel() {
            this.creating = false;
            this.reset();
            this.focus('open');
        },

        /**
         * Makes the link and puts it at the head of the list, its address selected so that it can be copied at once.
         *
         * @returns {Promise<void>}
         */
        async create() {
            if (!this.canCreate) {
                return;
            }

            this.busy = true;
            this.errors = {};
            this.formError = '';

            try {
                const label = this.form.label.trim();
                const answer = await http.post(
                    this.url,
                    {
                        ...(label === '' ? {} : { label }),
                        include_files: this.form.includeFiles,
                        expires_in_days: this.form.expires === '' ? null : Number(this.form.expires),
                    },
                    { silent: true },
                );

                this.links = [answer.data, ...this.links];
                this.creating = false;
                this.reset();
                announce(this, t('sharing.notice.created'));
                this.focusRow('url', answer.data.id, true);
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Copies the address of a link. Where the browser does not allow it (a page that is not served over https, a refused
         * permission), the address is selected instead, so that the keyboard can copy it.
         *
         * @param {ShareLink} link
         * @returns {Promise<void>}
         */
        copy(link) {
            this.copiedId = link.id;

            return copyValue(this, link.url ?? '', {
                select: () => this.focusRow('url', link.id, true),
                copied: t('tokens.copied'),
                hint: t('admin.settings.select_hint'),
            });
        },

        /**
         * Selects the whole address in its box (a click or the focus on it does it, whatever the browser).
         *
         * @param {Event} event The focus or click on the box.
         * @returns {void}
         */
        selectUrl(event) {
            event.target.select?.();
        },

        /**
         * Asks for the confirmation of the revocation of a link.
         *
         * @param {ShareLink} link
         * @returns {void}
         */
        askRevoke(link) {
            this.confirmingId = link.id;
            this.focusRow('confirm', link.id);
        },

        /**
         * Gives up the revocation.
         *
         * @returns {void}
         */
        cancelRevoke() {
            const id = this.confirmingId;

            this.confirmingId = null;
            this.focusRow('revoke', id);
        },

        /**
         * Revokes a link: its address stops working at once, and it leaves the list.
         *
         * @param {ShareLink} link
         * @returns {Promise<void>}
         */
        async revoke(link) {
            if (this.revokingId !== null) {
                return;
            }

            this.revokingId = link.id;

            try {
                await http.delete(`${this.url}/${encodeURIComponent(String(link.id))}`);

                this.remove(link.id);
                announce(this, t('sharing.notice.revoked'));
                this.focus('heading');
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (!(error instanceof HttpError)) {
                    return;
                }

                // Already gone (revoked elsewhere, or pruned): the list was out of date, and now it is not.
                if (error.status === 404) {
                    this.remove(link.id);
                    this.focus('heading');
                } else if (!error.reported) {
                    notify('danger', error.message);
                }
            } finally {
                this.revokingId = null;
            }
        },

        /**
         * Takes a link off the list.
         *
         * @param {string} id
         * @returns {void}
         */
        remove(id) {
            this.links = this.links.filter((link) => link.id !== id);

            if (this.confirmingId === id) {
                this.confirmingId = null;
            }
        },

        /**
         * Whether the revocation of a link waits for its confirmation.
         *
         * @param {ShareLink} link
         * @returns {boolean}
         */
        isConfirming(link) {
            return this.confirmingId === link.id;
        },

        /**
         * Whether the revocation of a link is in flight.
         *
         * @param {ShareLink} link
         * @returns {boolean}
         */
        isRevoking(link) {
            return this.revokingId === link.id;
        },

        /**
         * Whether the time of a link is up.
         *
         * @param {ShareLink} link
         * @returns {boolean}
         */
        isExpired(link) {
            return link.expires_at !== null && new Date(link.expires_at).getTime() <= Date.now();
        },

        /**
         * Whether the address of a link is shown: the link works, its address can be read back, and the administrator allows sharing
         * (an address that answers "not found" is not worth copying).
         *
         * @param {ShareLink} link
         * @returns {boolean}
         */
        showsUrl(link) {
            return this.enabled && Boolean(link.url) && !this.isExpired(link);
        },

        /**
         * Whether the address of a link is missing although the link works (it could not be read back).
         *
         * @param {ShareLink} link
         * @returns {boolean}
         */
        lacksUrl(link) {
            return this.enabled && !link.url && !this.isExpired(link);
        },

        /**
         * What a link is called: its label, or a word for a link that has none.
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        linkName(link) {
            return link.label ? link.label : t('sharing.untitled');
        },

        /**
         * Whether a link was made by somebody who is no longer an owner of the workspace: they left it, were given another role, or deleted
         * their account. The link outlives them, so the owners are told.
         *
         * @param {ShareLink} link
         * @returns {boolean}
         */
        byFormerMember(link) {
            return !link.created_by || link.created_by.is_owner !== true;
        },

        /**
         * Whether a link works or its time is up, in words.
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        stateLabel(link) {
            return t(this.isExpired(link) ? 'sharing.state.expired' : 'sharing.state.active');
        },

        /**
         * The classes of the badge of the state of a link (a badge always says the state in words as well).
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        stateClass(link) {
            return this.isExpired(link) ? 'bg-secondary-lt' : 'bg-green-lt';
        },

        /**
         * Whether the photos and files are shown through a link, in words.
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        filesLabel(link) {
            return t(link.include_files ? 'sharing.files.included' : 'sharing.files.excluded');
        },

        /**
         * When a link was made, as a sentence.
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        createdText(link) {
            return t('tokens.created', { date: momentText(link.created_at) });
        },

        /**
         * When a link was last opened, as a sentence.
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        lastUsedText(link) {
            return link.last_used_at
                ? t('tokens.last_used', { date: momentText(link.last_used_at) })
                : t('tokens.never_used');
        },

        /**
         * When a link expires, as a sentence.
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        expiresText(link) {
            return link.expires_at
                ? t('tokens.expires', { date: momentText(link.expires_at) })
                : t('tokens.never_expires');
        },

        /**
         * What the button that copies the address of a link says (it says "Copied" for a moment).
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        copyLabel(link) {
            return t(this.copied && this.copiedId === link.id ? 'tokens.copied' : 'tokens.copy');
        },

        /**
         * The name of the button that copies the address of a link (it says which one).
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        copyNamed(link) {
            return t('sharing.copy_named', { name: this.linkName(link) });
        },

        /**
         * The name of the button that revokes a link (it says which one).
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        revokeLabel(link) {
            return t('sharing.revoke_named', { name: this.linkName(link) });
        },

        /**
         * The question asked before a link is revoked.
         *
         * @param {ShareLink} link
         * @returns {string}
         */
        confirmText(link) {
            return t('sharing.confirm_revoke', { name: this.linkName(link) });
        },

        /**
         * Whether a field of the form was refused (for `aria-invalid`).
         *
         * @param {'label' | 'expires'} field
         * @returns {boolean}
         */
        isInvalid(field) {
            return Boolean(this.errors[field]);
        },

        /**
         * Tells why the creation was refused: under the field a validation message is about, in the alert of the form for the rest.
         *
         * Validation messages (422) are the server's, translated by it; the limit of links and sharing turned off (409) and the pace of
         * creations (429) are told by key. A cancelled request (not an HttpError) says nothing.
         *
         * @param {unknown} error
         * @returns {void}
         */
        fail(error) {
            if (!(error instanceof HttpError)) {
                return;
            }

            if (error.status === 422) {
                const messages = firstMessages(error.errors);

                this.errors = {
                    label: messages.label ?? '',
                    expires: messages.expires_in_days ?? '',
                };

                if (!Object.values(this.errors).some(Boolean)) {
                    this.formError = error.message;
                } else if (this.errors.label) {
                    this.focus('label');
                }
            } else if (error.status === 409 && error.code === 'share_link_limit_reached') {
                this.formError = t('sharing.error.limit', { count: error.body?.limit ?? this.limit });
            } else if (error.status === 409 && error.code === 'share_links_disabled') {
                this.enabled = false;
                this.creating = false;
                this.reset();
                notify('warning', t('sharing.error.disabled'));
            } else if (error.status === 429) {
                this.formError = throttledMessage(error.retryAfter);
            } else if (error.status === 419) {
                // Silent calls are not reloaded by the HTTP helper; the message says the page will be.
                this.formError = error.message;
                scheduleNavigation(reloadPage);
            } else {
                this.formError = error.message;
            }
        },

        /**
         * Empties the creation form, and its messages.
         *
         * @returns {void}
         */
        reset() {
            this.form = { label: '', includeFiles: true, expires: DEFAULT_EXPIRY };
            this.errors = {};
            this.formError = '';
        },

        /**
         * An element of the card the cursor can be put on, by the name its markup gives it (`data-focus`). It is looked up from the root
         * rather than with `$refs`, which is empty once the element that started the call (the button of a row that was just revoked)
         * has left the page.
         *
         * @param {'label' | 'open' | 'heading'} name
         * @returns {HTMLElement | null}
         */
        target(name) {
            return this.rootElement.querySelector(`[data-focus="${name}"]`);
        },

        /**
         * Puts the cursor on an element of the card once what was changed has been rendered.
         *
         * @param {'label' | 'open' | 'heading'} ref Name of the element (see {@link target}).
         * @returns {void}
         */
        focus(ref) {
            this.$nextTick(() => {
                this.target(ref)?.focus();
            });
        },

        /**
         * Puts the cursor on an element of the row of a link once what was changed has been rendered.
         *
         * @param {'confirm' | 'revoke' | 'url'} action What the element does (`data-share-action`).
         * @param {string | null} id The link (`data-link-id`).
         * @param {boolean} [select] Whether to select the text of a box as well.
         * @returns {void}
         */
        focusRow(action, id, select = false) {
            this.$nextTick(() => {
                const elements = this.rootElement.querySelectorAll(`[data-share-action="${action}"]`);
                const element = [...elements].find((candidate) => candidate.dataset.linkId === String(id));

                element?.focus();

                if (select) {
                    element?.select?.();
                }
            });
        },
    };
}
