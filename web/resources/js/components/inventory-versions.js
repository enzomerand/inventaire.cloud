/**
 * Alpine component of the page of the official versions of an inventory: the versions, newest first, with what each one holds,
 * where its PDF stands, the people asked to sign it and where each one stands; the files to download; and, for the people who
 * may, the dialog that freezes the inventory as a new version (a label, and optionally signers) and the actions on a version
 * (cancel its request for signatures, send it again, delete it).
 *
 * A version is made by a job, not by the request that asks for it: the API answers `202` with the version still `pending`, and
 * the page asks for each version that is on its way every two seconds (`show`), for as long as one is: its PDF is not written, or
 * its request for signatures is not with the signers yet (see `../versions/versions.js`). A reading that began before something done
 * here (a version created, cancelled, sent again or deleted) is dropped, so the list never shows a state older than the person's
 * last action. The readings are silent (the helper does not toast each failure of a request that comes every two seconds): a
 * failure stops them and the page offers to try again. Whatever changes while the person is not looking at it is said to one
 * polite live region that is always in the page.
 *
 * The list is read a page of twenty at a time ("Show more" asks for the next one and adds it to the end). The files are plain links
 * to the addresses the API gives (`download_url`): the browser downloads them with the session cookie, so a link must not carry
 * `rel=noreferrer` (Sanctum recognises the front by its Referer).
 *
 * The addresses, what the role allows, whether the instance can ask for signatures and how many signers a version takes come from
 * the markup (`data-*` attributes on the root), because the CSP build of Alpine does not allow Blade to inject values into
 * expressions. In the address of one version, `__id__` stands for its ULID.
 */
import { fillUrl } from '../lib/forms.js';
import { HttpError, http } from '../lib/http.js';
import { t, tn } from '../lib/i18n.js';
import { hideModal, onModalHidden, showModal } from '../lib/modal.js';
import { notify } from '../lib/notify.js';
import {
    MAX_LABEL_LENGTH,
    POLL_INTERVAL_MS,
    actionFailure,
    anyActive,
    appendPage,
    blankSigner,
    changeNotices,
    creationFailure,
    isActive,
    requestBody,
    rowOf,
    signerError,
    withVersion,
} from '../versions/versions.js';

/** The most signers a version takes when the page does not say. */
const DEFAULT_MAX_SIGNERS = 10;

/**
 * @typedef {import('../versions/versions.js').VersionRecord} VersionRecord
 */

/**
 * @returns {Record<string, any>}
 */
export default function inventoryVersions() {
    // Plumbing that is not state the page shows, so it stays out of the reactive data: the timer of the next poll and the
    // cancellation of the reading that is on its way.
    /** @type {ReturnType<typeof setTimeout> | null} */
    let pollTimer = null;
    /** @type {AbortController | null} */
    let polling = null;

    /**
     * Stops waiting for the next poll and cancels the reading that is on its way.
     *
     * @returns {void}
     */
    function cancelPolling() {
        if (pollTimer !== null) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }

        polling?.abort();
        polling = null;
    }

    return {
        /** The versions listed, newest first, as the API describes them. */
        versions: [],

        /** True until the first page has been read. */
        loading: true,

        /** True when the first page could not be read: the page offers to try again. */
        loadFailed: false,

        /** True while the next page is being read. */
        loadingMore: false,

        /** The last page read, the last page there is, and how many versions the inventory holds. */
        page: 0,
        lastPage: 1,
        total: 0,

        /** True when the readings that follow the versions on their way failed: the page offers to ask again. */
        pollFailed: false,

        /** The ids of the versions an action of the person is on its way for. */
        working: [],

        /** Counts the changes made here to the list: a reading that began before one is out of date. */
        revision: 0,

        /** What the live region says; changing it is how a screen reader is told. */
        status: '',

        /** Endpoint of the versions (GET the list, POST to freeze), from `data-url`. */
        url: '',

        /** Endpoint of one version, with `__id__` where its ULID goes (GET it, DELETE it), from `data-version-url`. */
        versionUrl: '',

        /** Endpoint that cancels the request for signatures of a version (POST), from `data-cancel-url`. */
        cancelUrl: '',

        /** Endpoint that sends the request for signatures of a version again (POST), from `data-retry-url`. */
        retryUrl: '',

        /** Whether the person may freeze the inventory (editors and owners), from `data-can-create`. */
        canCreate: false,

        /** Whether the instance has a signature service, from `data-signatures-available`. */
        signaturesAvailable: false,

        /** The most signers a version takes, from `data-max-signers`. */
        maxSigners: DEFAULT_MAX_SIGNERS,

        /** What the dialog that freezes the inventory holds: the label, whether signatures are asked for, and the lines of signers. */
        form: { label: '', sign: false, signers: [] },

        /** The messages of a refused form, by name of the input in the API (`label`, `signers`, `signers.0.email`...). */
        errors: {},

        /** Why the form was refused when it is not about one input ("" when it was not). */
        formError: '',

        /** True while the request that freezes the inventory is on its way. */
        busy: false,

        /** What identifies the next line of signers added (never reused, so a line keeps its inputs when others come and go). */
        nextSignerKey: 1,

        /** The action the confirmation dialog is about: `{ kind: 'cancel' | 'delete', id, number }`, or null. */
        confirming: null,

        /** The longest label a version takes, for the input. */
        maxLabelLength: MAX_LABEL_LENGTH,

        /** The versions as the page shows them. */
        get rows() {
            return this.versions.map((one) => rowOf(one, { working: this.working.includes(one.id) }));
        },

        /** Whether the inventory is known to have no version (not when the list could not be read). */
        get empty() {
            return !this.loading && !this.loadFailed && this.versions.length === 0;
        },

        /** Whether there are versions to list. */
        get hasVersions() {
            return this.versions.length > 0;
        },

        /** Whether another page of versions can be asked for. */
        get hasMore() {
            return this.page < this.lastPage;
        },

        /** How many of the versions are listed, out of how many there are: "20 of 45". */
        get summary() {
            return tn('versions.showing', this.total, { shown: this.versions.length });
        },

        /** Whether the list says how many versions are shown: only when it does not show them all. */
        get partial() {
            return this.hasVersions && this.versions.length < this.total;
        },

        /** Whether the form asks for signatures: the switch is on, and the instance can ask for them. */
        get signing() {
            return this.form.sign && this.signaturesAvailable;
        },

        /** Whether another line of signers can be added. */
        get canAddSigner() {
            return this.form.signers.length < this.maxSigners;
        },

        /** Whether a line of signers can be removed: one always stays while signatures are asked for. */
        get canRemoveSigner() {
            return this.form.signers.length > 1;
        },

        /** What the form says about how many signers a version takes. */
        get signersHint() {
            return tn('versions.form.signers_hint', this.maxSigners);
        },

        /** The title of the confirmation dialog. */
        get confirmTitle() {
            return this.confirming === null ? '' : t(`versions.confirm.${this.confirming.kind}_title`);
        },

        /** The question asked before an action is done. */
        get confirmText() {
            return this.confirming === null
                ? ''
                : t(`versions.confirm.${this.confirming.kind}`, { number: this.confirming.number });
        },

        /** What the button that confirms says. */
        get confirmButton() {
            return this.confirming === null ? '' : t(`versions.action.${this.confirming.kind}`);
        },

        /**
         * Reads the page the markup carries, binds the reset of the dialogs, and reads the first page of versions.
         *
         * @returns {Promise<void>}
         */
        async init() {
            const data = this.$root.dataset;

            this.url = data.url ?? '';
            this.versionUrl = data.versionUrl ?? '';
            this.cancelUrl = data.cancelUrl ?? '';
            this.retryUrl = data.retryUrl ?? '';
            this.canCreate = data.canCreate === '1';
            this.signaturesAvailable = data.signaturesAvailable === '1';
            this.maxSigners = Math.max(1, Number.parseInt(data.maxSigners ?? '', 10) || DEFAULT_MAX_SIGNERS);

            this.$nextTick(() => {
                onModalHidden(this.$refs.createModal, () => this.resetForm());
                onModalHidden(this.$refs.confirmModal, () => {
                    this.confirming = null;
                });
            });

            await this.load();
        },

        /**
         * Stops the readings when the page goes away.
         *
         * @returns {void}
         */
        destroy() {
            cancelPolling();
        },

        /**
         * Reads the first page of versions: when the page opens, and when the person asks to try again.
         *
         * @returns {Promise<void>}
         */
        async load() {
            cancelPolling();
            this.loading = true;
            this.loadFailed = false;
            this.pollFailed = false;

            try {
                const answer = await http.get(this.url);

                this.versions = answer.data;
                this.takePaging(answer.meta, 1);
                this.planPoll();
            } catch {
                this.loadFailed = true;
            } finally {
                this.loading = false;
            }
        },

        /**
         * Reads the next page of versions and adds it to the end of the list.
         *
         * @returns {Promise<void>}
         */
        async loadMore() {
            if (this.loadingMore || !this.hasMore) {
                return;
            }

            this.loadingMore = true;

            try {
                const answer = await http.get(this.url, { params: { page: this.page + 1 } });

                this.versions = appendPage(this.versions, answer.data);
                this.takePaging(answer.meta, this.page + 1);
                this.planPoll();
            } catch {
                // The helper told the person; the button is still there to try again.
            } finally {
                this.loadingMore = false;
            }
        },

        /**
         * Remembers where the list is up to, from the `meta` of a page.
         *
         * @param {{ current_page?: number, last_page?: number, total?: number } | undefined} meta
         * @param {number} fallback The page that was asked for, when the answer does not say.
         * @returns {void}
         */
        takePaging(meta, fallback) {
            this.page = meta?.current_page ?? fallback;
            this.lastPage = meta?.last_page ?? this.page;
            this.total = meta?.total ?? this.versions.length;
        },

        /**
         * Plans the next reading, two seconds from now, when a version is still on its way; plans nothing otherwise (and forgets the
         * reading that was planned).
         *
         * @returns {void}
         */
        planPoll() {
            if (pollTimer !== null) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }

            if (!this.pollFailed && anyActive(this.versions)) {
                pollTimer = setTimeout(() => {
                    pollTimer = null;
                    this.pollActive();
                }, POLL_INTERVAL_MS);
            }
        },

        /**
         * Reads again every version that is on its way, all at once, and puts what the server says in the list unless something was
         * done here since the readings began (they are then dropped: they would not tell what was just done). The next reading is
         * planned if a version is still on its way.
         *
         * @returns {Promise<void>}
         */
        async pollActive() {
            const ids = this.versions.filter(isActive).map((one) => one.id);

            if (ids.length === 0) {
                return;
            }

            cancelPolling();

            const mine = new AbortController();
            const revision = this.revision;

            polling = mine;

            try {
                const readings = await Promise.all(ids.map((id) => this.readOne(id, mine.signal)));

                if (polling !== mine) {
                    return;
                }

                this.pollFailed = false;

                if (revision === this.revision) {
                    this.applyReadings(readings);
                }
            } catch {
                // A cancelled reading is no longer the one in `polling` (the helper lets the abort through).
                if (polling === mine) {
                    this.pollFailed = true;
                }
            } finally {
                if (polling === mine) {
                    polling = null;
                    this.planPoll();
                }
            }
        },

        /**
         * Asks again after a failure of the readings.
         *
         * @returns {Promise<void>}
         */
        retryPolling() {
            this.pollFailed = false;

            return this.pollActive();
        },

        /**
         * Reads one version. One that no longer exists (deleted meanwhile) is told by `gone`, not as a failure.
         *
         * @param {string} id
         * @param {AbortSignal} [signal]
         * @returns {Promise<{ id: string, version?: VersionRecord, gone?: boolean }>}
         */
        async readOne(id, signal) {
            try {
                const answer = await http.get(fillUrl(this.versionUrl, id), { signal, silent: true });

                return { id, version: answer.data };
            } catch (error) {
                if (error instanceof HttpError && error.status === 404) {
                    return { id, gone: true };
                }

                throw error;
            }
        },

        /**
         * Puts what the readings say in the list: a version replaces the one it describes (unless an action of the person is on its
         * way for it), one that is gone leaves the list. The changes worth saying are said in the live region.
         *
         * @param {{ id: string, version?: VersionRecord, gone?: boolean }[]} readings
         * @returns {void}
         */
        applyReadings(readings) {
            const before = this.versions;
            let next = before;

            for (const reading of readings) {
                if (this.working.includes(reading.id)) {
                    continue;
                }

                if (reading.gone === true) {
                    next = next.filter((one) => one.id !== reading.id);
                    this.total = Math.max(0, this.total - 1);
                } else if (reading.version !== undefined && next.some((one) => one.id === reading.id)) {
                    next = next.map((one) => (one.id === reading.id ? reading.version : one));
                }
            }

            this.versions = next;

            const notices = changeNotices(before, next);

            if (notices.length > 0) {
                this.announce(notices.join(' '));
            }
        },

        /**
         * Opens the dialog that freezes the inventory, empty: no label, no signatures asked for.
         *
         * @returns {void}
         */
        startCreate() {
            if (!this.canCreate) {
                return;
            }

            this.form = { label: '', sign: false, signers: [this.newSigner()] };
            this.resetForm();
            showModal(this.$refs.createModal);
        },

        /**
         * Forgets the refusals of the form.
         *
         * @returns {void}
         */
        resetForm() {
            this.errors = {};
            this.formError = '';
        },

        /**
         * A new empty line of signers.
         *
         * @returns {ReturnType<typeof blankSigner>}
         */
        newSigner() {
            const signer = blankSigner(this.nextSignerKey);

            this.nextSignerKey += 1;

            return signer;
        },

        /**
         * The switch that asks for signatures was moved. It stays off where the instance cannot ask for them; turned on, there is a
         * line of signers to fill.
         *
         * @param {Event} event The change event of the switch.
         * @returns {void}
         */
        toggleSign(event) {
            const box = /** @type {HTMLInputElement} */ (event.target);

            this.form.sign = box.checked === true && this.signaturesAvailable;
            box.checked = this.form.sign;

            if (this.form.sign && this.form.signers.length === 0) {
                this.form.signers.push(this.newSigner());
            }
        },

        /**
         * Adds a line of signers and puts the cursor in its name.
         *
         * @returns {void}
         */
        addSigner() {
            if (!this.canAddSigner) {
                return;
            }

            const signer = this.newSigner();

            this.form.signers.push(signer);
            this.$nextTick(() => {
                this.$refs.createModal?.querySelector?.(`#${signer.nameId}`)?.focus();
            });
        },

        /**
         * Removes a line of signers. The messages that were about the lines are forgotten: the lines they named have moved.
         *
         * @param {number} index
         * @returns {void}
         */
        removeSigner(index) {
            if (!this.canRemoveSigner) {
                return;
            }

            this.form.signers.splice(index, 1);
            this.errors = {};
            this.formError = '';
        },

        /**
         * What the input of a line of signers is called for assistive technology: it says whose line it is.
         *
         * @param {number} index
         * @param {'name' | 'email' | 'remove'} input
         * @returns {string}
         */
        signerLabel(index, input) {
            return t(`versions.signer.${input}_n`, { number: index + 1 });
        },

        /**
         * The message under an input of a line of signers.
         *
         * @param {number} index
         * @param {'name' | 'email'} input
         * @returns {string}
         */
        signerError(index, input) {
            return signerError(this.errors, index, input);
        },

        /**
         * Freezes the inventory. The version is added to the top of the list at once, still waiting for its PDF, and the page starts
         * to follow it. A refusal is said under the inputs it is about, or under the buttons.
         *
         * @returns {Promise<void>}
         */
        async submit() {
            if (this.busy || !this.canCreate) {
                return;
            }

            this.busy = true;
            this.resetForm();

            try {
                const answer = await http.post(
                    this.url,
                    requestBody({ label: this.form.label, sign: this.signing, signers: this.form.signers }),
                );

                this.revision += 1;
                this.versions = withVersion(this.versions, answer.data);
                this.total += 1;
                hideModal(this.$refs.createModal);
                notify('success', t('versions.notice.started', { number: answer.data.number }));
                this.planPoll();
            } catch (error) {
                this.refuse(error);
            } finally {
                this.busy = false;
            }
        },

        /**
         * Says why the inventory was not frozen: under the inputs the refusal is about, or under the buttons.
         *
         * @param {unknown} error
         * @returns {void}
         */
        refuse(error) {
            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (!(error instanceof HttpError)) {
                return;
            }

            const failure = creationFailure(error);

            this.errors = failure.fieldErrors;
            this.formError = failure.message;
        },

        /**
         * Asks for the confirmation of the cancellation of a request for signatures.
         *
         * @param {{ id: string, number: number, working: boolean }} row
         * @returns {void}
         */
        askCancel(row) {
            this.ask('cancel', row);
        },

        /**
         * Asks for the confirmation of the deletion of a version.
         *
         * @param {{ id: string, number: number, working: boolean }} row
         * @returns {void}
         */
        askDelete(row) {
            this.ask('delete', row);
        },

        /**
         * Opens the confirmation dialog for an action on a version.
         *
         * @param {'cancel' | 'delete'} kind
         * @param {{ id: string, number: number, working: boolean }} row
         * @returns {void}
         */
        ask(kind, row) {
            if (row.working) {
                return;
            }

            this.confirming = { kind, id: row.id, number: row.number };
            showModal(this.$refs.confirmModal);
        },

        /**
         * The person confirmed: the dialog closes and the action is done.
         *
         * @returns {Promise<void>}
         */
        async confirmAction() {
            const choice = this.confirming;

            if (choice === null) {
                return;
            }

            hideModal(this.$refs.confirmModal);

            if (choice.kind === 'delete') {
                await this.remove(choice.id);
            } else {
                await this.cancelRequest(choice.id);
            }
        },

        /**
         * Sends the request for signatures of a version again (no confirmation: nothing is lost).
         *
         * @param {{ id: string, working: boolean }} row
         * @returns {Promise<void>}
         */
        async retryRequest(row) {
            await this.act(
                row.id,
                () => http.post(fillUrl(this.retryUrl, row.id)),
                (answer) => {
                    this.versions = withVersion(this.versions, answer.data);
                    notify('success', t('versions.notice.retried'));
                    this.planPoll();
                },
            );
        },

        /**
         * Cancels the request for signatures of a version: Documenso is asked at once, and the version answers with its request
         * cancelled.
         *
         * @param {string} id
         * @returns {Promise<void>}
         */
        async cancelRequest(id) {
            await this.act(
                id,
                () => http.post(fillUrl(this.cancelUrl, id)),
                (answer) => {
                    this.versions = withVersion(this.versions, answer.data);
                    notify('success', t('versions.notice.cancelled'));
                },
            );
        },

        /**
         * Deletes a version (its files and its request for signatures go with it), and puts the cursor on the title of the list: the
         * button that was pressed is gone with its card.
         *
         * @param {string} id
         * @returns {Promise<void>}
         */
        async remove(id) {
            // Taken now: once the card is gone, Alpine may no longer answer `$refs` to a call made from its scope.
            const refs = this.$refs;

            await this.act(
                id,
                () => http.delete(fillUrl(this.versionUrl, id)),
                () => {
                    this.drop(id);
                    notify('success', t('versions.notice.deleted'));
                    this.$nextTick(() => refs.title?.focus());
                },
            );
        },

        /**
         * Does an action on one version: no second one at the same time on it, the card is marked as busy while the request is on its
         * way, and a refusal is told.
         *
         * @param {string} id
         * @param {() => Promise<any>} request
         * @param {(answer: any) => void} done What to do with the answer.
         * @returns {Promise<void>}
         */
        async act(id, request, done) {
            if (this.working.includes(id)) {
                return;
            }

            this.working = [...this.working, id];

            try {
                const answer = await request();

                this.revision += 1;
                done(answer);
            } catch (error) {
                await this.failAction(error, id);
            } finally {
                this.working = this.working.filter((one) => one !== id);
            }
        },

        /**
         * Says why an action on a version failed. A version that is gone leaves the list; one that is not in the state the action
         * needs is read again.
         *
         * @param {unknown} error
         * @param {string} id
         * @returns {Promise<void>}
         */
        async failAction(error, id) {
            // Anything else than an HttpError is a cancelled request: nothing to tell.
            if (!(error instanceof HttpError)) {
                return;
            }

            const outcome = actionFailure(error);

            if (outcome.gone) {
                this.revision += 1;
                this.drop(id);

                return;
            }

            if (outcome.message !== '') {
                notify(outcome.refresh ? 'warning' : 'danger', outcome.message);
            }

            if (outcome.refresh) {
                await this.refreshOne(id);
            }
        },

        /**
         * Reads one version again and puts it in the list.
         *
         * @param {string} id
         * @returns {Promise<void>}
         */
        async refreshOne(id) {
            try {
                const reading = await this.readOne(id);

                this.revision += 1;

                if (reading.gone === true) {
                    this.drop(id);
                } else if (reading.version !== undefined) {
                    this.versions = withVersion(this.versions, reading.version);
                    this.planPoll();
                }
            } catch {
                // Not told: the person got a message about the action already, and the list is still usable.
            }
        },

        /**
         * Takes a version out of the list.
         *
         * @param {string} id
         * @returns {void}
         */
        drop(id) {
            this.versions = this.versions.filter((one) => one.id !== id);
            this.total = Math.max(0, this.total - 1);
            this.planPoll();
        },

        /**
         * Copies the whole SHA-256 of a version. Where the browser does not allow it (a page that is not served over https, a refused
         * permission), the value is shown in a toast that stays, so that it can be copied by hand.
         *
         * @param {{ sha256: string }} row
         * @returns {Promise<void>}
         */
        async copyHash(row) {
            try {
                await navigator.clipboard.writeText(row.sha256);
                this.announce(t('versions.hash.copied'));
            } catch {
                notify('warning', t('versions.hash.copy_failed', { hash: row.sha256 }), { timeout: 0 });
            }
        },

        /**
         * Says something in the live region. It is emptied first, so that the same sentence said twice in a row is announced twice.
         *
         * @param {string} message
         * @returns {void}
         */
        announce(message) {
            this.status = '';
            this.$nextTick(() => {
                this.status = message;
            });
        },
    };
}
