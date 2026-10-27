/**
 * The page of the official versions at the level of the component: what it asks the API for and with which body, how the list
 * follows the versions that are on their way, what the dialogs leave, and what a refusal says.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryVersions from '../../../resources/js/components/inventory-versions.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { hideModal, showModal } from '../../../resources/js/lib/modal.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { deferred } from '../support/inventory.js';
import { dismiss, forgetDialogs } from '../support/modal.js';
import { VERSIONS_URL, onItsWay, signature, signer, version } from '../support/versions.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', async () => import('../support/modal.js'));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

/** A page of versions as the API answers it. */
function page(versions, { current = 1, last = 1, total = versions.length } = {}) {
    return { data: versions, meta: { current_page: current, last_page: last, total } };
}

/** An HTTP failure, as the helper throws it. */
function failure(
    status,
    { code = null, errors = {}, body = null, message = 'Server wording', reported = false, retryAfter = null } = {},
) {
    const error = new HttpError(status, message, { errors, body, code, retryAfter });

    error.reported = reported;

    return error;
}

describe('the page of the official versions', () => {
    let component;

    /** The readings of the list (the pages), and of one version. */
    const listReads = () => http.get.mock.calls.filter((call) => call[0] === VERSIONS_URL);
    const oneReads = (id) => http.get.mock.calls.filter((call) => call[0] === `${VERSIONS_URL}/${id}`);

    /**
     * Builds the component the way Alpine hands it its root, its refs and `$nextTick`, with what the markup says. The list is not read
     * until `init()` is called.
     */
    function build({ canCreate = true, signaturesAvailable = true, maxSigners = 3 } = {}) {
        component = inventoryVersions();

        const root = document.createElement('div');

        Object.assign(root.dataset, {
            url: VERSIONS_URL,
            versionUrl: `${VERSIONS_URL}/__id__`,
            cancelUrl: `${VERSIONS_URL}/__id__/signature/cancel`,
            retryUrl: `${VERSIONS_URL}/__id__/signature/retry`,
            canCreate: canCreate ? '1' : '0',
            signaturesAvailable: signaturesAvailable ? '1' : '0',
            maxSigners: String(maxSigners),
        });

        component.$root = root;
        component.$refs = {
            createModal: document.createElement('div'),
            confirmModal: document.createElement('div'),
            title: document.createElement('h1'),
        };
        component.$refs.title.focus = vi.fn();
        component.$nextTick = (callback) => callback();
    }

    /** Builds the component and reads the first page, which is what the API answers to the list. */
    async function mount(first = page([]), options = {}) {
        build(options);
        http.get.mockImplementation(async (url) => {
            if (url === VERSIONS_URL) {
                return first;
            }

            throw new Error(`unexpected reading of ${url}`);
        });

        await component.init();
    }

    beforeEach(() => {
        http.get.mockReset();
        http.post.mockReset();
        http.delete.mockReset();
        notify.mockReset();
        forgetDialogs();
        vi.useFakeTimers();
    });

    afterEach(() => {
        component?.destroy();
        vi.useRealTimers();
    });

    describe('reading the list', () => {
        it('reads what the markup says and asks for the first page', async () => {
            await mount(page([version('v1')], { total: 1 }), {
                canCreate: true,
                signaturesAvailable: true,
                maxSigners: 5,
            });

            expect(http.get).toHaveBeenCalledWith(VERSIONS_URL);
            expect(component.canCreate).toBe(true);
            expect(component.signaturesAvailable).toBe(true);
            expect(component.maxSigners).toBe(5);
            expect(component.versions.map((one) => one.id)).toEqual(['v1']);
            expect(component.loading).toBe(false);
            expect(component.loadFailed).toBe(false);
            expect(component.empty).toBe(false);
            expect(component.rows.map((row) => row.title)).toEqual(['Version no. 3']);
        });

        it('knows an inventory with no version', async () => {
            await mount(page([]));

            expect(component.empty).toBe(true);
            expect(component.hasVersions).toBe(false);
        });

        it('offers to try again when the list cannot be read, and does not say the inventory is empty', async () => {
            build();
            http.get.mockRejectedValue(failure(500, { reported: true }));

            await component.init();

            expect(component.loadFailed).toBe(true);
            expect(component.loading).toBe(false);
            expect(component.empty).toBe(false);

            http.get.mockResolvedValue(page([version('v1')]));
            await component.load();

            expect(component.loadFailed).toBe(false);
            expect(component.versions).toHaveLength(1);
        });

        it('falls back on a limit of ten signers and none of the powers when the markup says nothing', async () => {
            build();
            delete component.$root.dataset.maxSigners;
            delete component.$root.dataset.canCreate;
            http.get.mockResolvedValue(page([]));

            await component.init();

            expect(component.maxSigners).toBe(10);
            expect(component.canCreate).toBe(false);
        });

        it('shows how many of the versions are listed when it is not all of them', async () => {
            await mount(page([version('v1'), version('v2')], { current: 1, last: 3, total: 45 }));

            expect(component.partial).toBe(true);
            expect(component.summary).toBe('2 of 45 versions');
            expect(component.hasMore).toBe(true);
        });

        it('does not count the versions when it shows them all', async () => {
            await mount(page([version('v1')], { total: 1 }));

            expect(component.partial).toBe(false);
            expect(component.hasMore).toBe(false);
        });
    });

    describe('showing more', () => {
        it('asks for the next page and adds it to the end, leaving out the versions already listed', async () => {
            await mount(page([version('c'), version('b')], { current: 1, last: 2, total: 3 }));
            http.get.mockResolvedValue(page([version('b'), version('a')], { current: 2, last: 2, total: 3 }));

            await component.loadMore();

            expect(http.get).toHaveBeenLastCalledWith(VERSIONS_URL, { params: { page: 2 } });
            expect(component.versions.map((one) => one.id)).toEqual(['c', 'b', 'a']);
            expect(component.hasMore).toBe(false);
            expect(component.loadingMore).toBe(false);
        });

        it('does nothing when there is no other page', async () => {
            await mount(page([version('a')], { current: 1, last: 1, total: 1 }));

            await component.loadMore();

            expect(listReads()).toHaveLength(1);
        });

        it('does not ask twice for a page that is on its way', async () => {
            await mount(page([version('a')], { current: 1, last: 2, total: 2 }));
            const slow = deferred();

            http.get.mockReturnValue(slow.promise);
            const first = component.loadMore();

            await component.loadMore();
            expect(listReads()).toHaveLength(2);

            slow.resolve(page([version('b')], { current: 2, last: 2, total: 2 }));
            await first;

            expect(component.versions).toHaveLength(2);
        });

        it('keeps the list and the button when the page cannot be read', async () => {
            await mount(page([version('a')], { current: 1, last: 2, total: 2 }));
            http.get.mockRejectedValue(failure(500, { reported: true }));

            await component.loadMore();

            expect(component.versions).toHaveLength(1);
            expect(component.hasMore).toBe(true);
            expect(component.loadingMore).toBe(false);
        });
    });

    describe('following the versions that are on their way', () => {
        it('asks for each of them every two seconds, silently, and stops when none is left', async () => {
            await mount(page([onItsWay('a', 'running', { number: 2 }), version('b', { number: 1 })]));
            http.get.mockResolvedValue({ data: version('a', { number: 2 }) });

            expect(oneReads('a')).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(1999);
            expect(oneReads('a')).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(1);
            expect(oneReads('a')).toHaveLength(1);
            expect(oneReads('a')[0][1]).toMatchObject({ silent: true });
            expect(oneReads('b')).toHaveLength(0);
            expect(component.versions[0].status).toBe('completed');

            await vi.advanceTimersByTimeAsync(10000);
            expect(oneReads('a')).toHaveLength(1);
        });

        it('keeps asking while the PDF is not written or the request for signatures is not with the signers', async () => {
            await mount(page([onItsWay('a', 'pending'), version('b', { signature: signature('sending') })]));
            http.get.mockImplementation(async (url) => ({
                data: url.endsWith('/a') ? onItsWay('a', 'running') : version('b', { signature: signature('sending') }),
            }));

            await vi.advanceTimersByTimeAsync(2000);
            expect(oneReads('a')).toHaveLength(1);
            expect(oneReads('b')).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(2000);
            expect(oneReads('a')).toHaveLength(2);
            expect(oneReads('b')).toHaveLength(2);
        });

        it('does not ask at all when nothing is on its way, even for a request that awaits its signers', async () => {
            await mount(page([version('a'), version('b', { signature: signature('awaiting') })]));

            await vi.advanceTimersByTimeAsync(20000);

            expect(http.get).toHaveBeenCalledTimes(1);
        });

        it('says in the live region what ended', async () => {
            await mount(page([onItsWay('a', 'running', { number: 4 })]));
            http.get.mockResolvedValue({ data: version('a', { number: 4 }) });

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.status).toBe('Version no. 4 is ready.');
        });

        it('says when the request for signatures reached the signers', async () => {
            await mount(page([version('a', { number: 4, signature: signature('sending') })]));
            http.get.mockResolvedValue({ data: version('a', { number: 4, signature: signature('awaiting') }) });

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.status).toBe('The signers of version no. 4 were emailed.');
            expect(component.rows[0].signatureLabel).toBe('Awaiting signatures');
        });

        it('lets a version that was deleted elsewhere leave the list', async () => {
            await mount(page([onItsWay('a'), version('b')], { total: 2 }));
            http.get.mockRejectedValue(failure(404, { reported: true }));

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.versions.map((one) => one.id)).toEqual(['b']);
            expect(component.total).toBe(1);
            expect(component.pollFailed).toBe(false);
        });

        it('stops and offers to ask again when a reading fails, then follows the version again', async () => {
            await mount(page([onItsWay('a')]));
            http.get.mockRejectedValue(failure(500));

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.pollFailed).toBe(true);

            await vi.advanceTimersByTimeAsync(20000);
            expect(oneReads('a')).toHaveLength(1);

            http.get.mockResolvedValue({ data: onItsWay('a', 'running') });
            await component.retryPolling();

            expect(component.pollFailed).toBe(false);
            expect(oneReads('a')).toHaveLength(2);

            await vi.advanceTimersByTimeAsync(2000);
            expect(oneReads('a')).toHaveLength(3);
        });

        it('drops a reading that began before something was done here', async () => {
            await mount(page([onItsWay('a')]));
            const slow = deferred();

            http.get.mockReturnValue(slow.promise);
            await vi.advanceTimersByTimeAsync(2000);

            // The person did something meanwhile (an action on another version, say): what the reading says is older than that.
            component.revision += 1;
            slow.resolve({ data: version('a') });
            await vi.advanceTimersByTimeAsync(0);

            expect(component.versions[0].status).toBe('pending');

            // It is asked again.
            http.get.mockResolvedValue({ data: version('a') });
            await vi.advanceTimersByTimeAsync(2000);

            expect(component.versions[0].status).toBe('completed');
        });

        it('leaves a version alone while an action of the person is on its way for it', async () => {
            await mount(page([onItsWay('a')]));
            component.working = ['a'];
            http.get.mockResolvedValue({ data: version('a') });

            await vi.advanceTimersByTimeAsync(2000);

            expect(component.versions[0].status).toBe('pending');
        });

        it('stops asking when the page goes away', async () => {
            await mount(page([onItsWay('a')]));

            component.destroy();
            await vi.advanceTimersByTimeAsync(20000);

            expect(oneReads('a')).toHaveLength(0);
        });
    });

    describe('freezing the inventory', () => {
        it('opens the dialog on an empty form, with one line of signers ready', async () => {
            await mount();

            component.startCreate();

            expect(showModal).toHaveBeenCalledWith(component.$refs.createModal);
            expect(component.form.label).toBe('');
            expect(component.form.sign).toBe(false);
            expect(component.form.signers).toHaveLength(1);
        });

        it('is not offered to somebody who may not', async () => {
            await mount(page([]), { canCreate: false });

            component.startCreate();
            await component.submit();

            expect(showModal).not.toHaveBeenCalled();
            expect(http.post).not.toHaveBeenCalled();
        });

        it('sends the label, adds the version at the top, closes the dialog and tells it', async () => {
            await mount(page([version('old', { number: 1 })], { total: 1 }));
            http.post.mockResolvedValue({ data: onItsWay('new', 'pending', { number: 2 }) });
            component.startCreate();
            component.form.label = ' Year-end ';

            await component.submit();

            expect(http.post).toHaveBeenCalledWith(VERSIONS_URL, { label: 'Year-end' });
            expect(component.versions.map((one) => one.id)).toEqual(['new', 'old']);
            expect(component.total).toBe(2);
            expect(hideModal).toHaveBeenCalledWith(component.$refs.createModal);
            expect(notify).toHaveBeenCalledWith('success', 'Version no. 2 is being created.');
            expect(component.busy).toBe(false);
        });

        it('sends an empty request when nothing was typed', async () => {
            await mount();
            http.post.mockResolvedValue({ data: onItsWay('new') });
            component.startCreate();

            await component.submit();

            expect(http.post).toHaveBeenCalledWith(VERSIONS_URL, {});
        });

        it('follows the new version until its PDF is written', async () => {
            await mount();
            http.post.mockResolvedValue({ data: onItsWay('new', 'pending', { number: 1 }) });
            component.startCreate();
            await component.submit();
            http.get.mockResolvedValue({ data: version('new', { number: 1 }) });

            await vi.advanceTimersByTimeAsync(2000);

            expect(oneReads('new')).toHaveLength(1);
            expect(component.versions[0].status).toBe('completed');
        });

        it('asks for signatures with every line of signers', async () => {
            await mount();
            http.post.mockResolvedValue({ data: onItsWay('new', 'pending', { signature: signature('requested') }) });
            component.startCreate();
            component.toggleSign({ target: { checked: true } });
            component.form.signers[0].name = 'Ada Lovelace';
            component.form.signers[0].email = 'ada@example.test';
            component.addSigner();
            component.form.signers[1].name = 'Grace Hopper';
            component.form.signers[1].email = 'grace@example.test';

            await component.submit();

            expect(http.post).toHaveBeenCalledWith(VERSIONS_URL, {
                signers: [
                    { name: 'Ada Lovelace', email: 'ada@example.test' },
                    { name: 'Grace Hopper', email: 'grace@example.test' },
                ],
            });
        });

        it('sends no signer when the switch is off, whatever the lines hold', async () => {
            await mount();
            http.post.mockResolvedValue({ data: onItsWay('new') });
            component.startCreate();
            component.form.signers[0].name = 'Ada Lovelace';

            await component.submit();

            expect(http.post).toHaveBeenCalledWith(VERSIONS_URL, {});
        });

        it('keeps the switch off where the instance cannot ask for signatures', async () => {
            await mount(page([]), { signaturesAvailable: false });
            component.startCreate();
            const box = { checked: true };

            component.toggleSign({ target: box });

            expect(component.form.sign).toBe(false);
            expect(box.checked).toBe(false);
            expect(component.signing).toBe(false);
        });

        it('has one line of signers ready when the switch is turned on, and no more than the instance takes', async () => {
            await mount(page([]), { maxSigners: 2 });
            component.startCreate();
            component.form.signers = [];
            component.toggleSign({ target: { checked: true } });

            expect(component.form.signers).toHaveLength(1);
            expect(component.canAddSigner).toBe(true);

            component.addSigner();
            expect(component.form.signers).toHaveLength(2);
            expect(component.canAddSigner).toBe(false);

            component.addSigner();
            expect(component.form.signers).toHaveLength(2);
        });

        it('puts the cursor in the name of the line that was added', async () => {
            await mount();
            component.startCreate();
            const input = document.createElement('input');

            component.$refs.createModal.append(input);
            input.focus = vi.fn();
            component.newSigner = () => ({ key: 99, name: '', email: '', nameId: 'name-99', emailId: 'email-99' });
            input.id = 'name-99';

            component.addSigner();

            expect(input.focus).toHaveBeenCalled();
        });

        it('removes a line of signers, but always keeps one', async () => {
            await mount();
            component.startCreate();
            component.toggleSign({ target: { checked: true } });
            component.addSigner();
            component.form.signers[0].name = 'First';
            component.form.signers[1].name = 'Second';

            component.removeSigner(0);

            expect(component.form.signers.map((one) => one.name)).toEqual(['Second']);
            expect(component.canRemoveSigner).toBe(false);

            component.removeSigner(0);
            expect(component.form.signers).toHaveLength(1);
        });

        it('gives every line a key of its own, even after others were removed', async () => {
            await mount();
            component.startCreate();
            component.addSigner();
            const keys = component.form.signers.map((one) => one.key);

            component.removeSigner(0);
            component.addSigner();

            expect(new Set([...keys, ...component.form.signers.map((one) => one.key)]).size).toBe(3);
        });

        it('names a line of signers for assistive technology', async () => {
            await mount();

            expect(component.signerLabel(1, 'name')).toBe('Name of signer 2');
            expect(component.signerLabel(0, 'email')).toBe('Email address of signer 1');
            expect(component.signerLabel(2, 'remove')).toBe('Remove signer 3');
            expect(component.signersHint).toBe('Up to 3 signers.');
        });

        it('shows the messages of a refusal under the inputs they are about', async () => {
            await mount();
            http.post.mockRejectedValue(
                failure(422, {
                    errors: { label: ['Too long.'], 'signers.0.email': ['Not valid.'] },
                }),
            );
            component.startCreate();

            await component.submit();

            expect(component.errors.label).toBe('Too long.');
            expect(component.signerError(0, 'email')).toBe('Not valid.');
            expect(component.signerError(0, 'name')).toBe('');
            expect(component.formError).toBe('');
            expect(hideModal).not.toHaveBeenCalled();
            expect(component.versions).toHaveLength(0);
            expect(component.busy).toBe(false);
        });

        it('says the limit, the full storage and the wait under the buttons', async () => {
            await mount();
            component.startCreate();

            http.post.mockRejectedValueOnce(failure(409, { code: 'version_limit_reached', body: { limit: 200 } }));
            await component.submit();
            expect(component.formError).toBe('An inventory cannot hold more than 200 official versions.');

            http.post.mockRejectedValueOnce(failure(409, { code: 'storage_limit_reached' }));
            await component.submit();
            expect(component.formError).toBe('The storage of this workspace is full.');

            http.post.mockRejectedValueOnce(failure(429, { retryAfter: 45 }));
            await component.submit();
            expect(component.formError).toBe('Too many attempts. Try again in 45 seconds.');
        });

        it('says a refusal about the inventory itself under the buttons', async () => {
            await mount();
            http.post.mockRejectedValue(failure(422, { errors: { inventory: ['Too many items for a PDF.'] } }));
            component.startCreate();

            await component.submit();

            expect(component.formError).toBe('Too many items for a PDF.');
            expect(component.errors).toEqual({});
        });

        describe('when the refusal lies outside what the dialog shows', () => {
            let scroll;

            /** Puts in the page what the dialog renders (the markup is Alpine's job): the inputs, and the alert under the buttons. */
            function render({ label = '', email = '', formError = '' } = {}) {
                const dialog = component.$refs.createModal;

                dialog.innerHTML = `
                    <div class="modal-body">
                        <input id="version-label" class="form-control${label ? ' is-invalid' : ''}">
                        <div class="invalid-feedback">${label}</div>
                        <fieldset>
                            <input id="signer-name-0" class="form-control">
                            <input id="signer-email-0" class="form-control${email ? ' is-invalid' : ''}">
                            <div class="invalid-feedback">${email}</div>
                        </fieldset>
                    </div>
                    <div class="modal-footer">
                        <div class="alert alert-danger" role="alert"${formError ? '' : ' hidden'}>${formError}</div>
                    </div>
                `;
                document.body.append(dialog);

                return dialog;
            }

            beforeEach(() => {
                // jsdom has no layout, hence no scrollIntoView.
                scroll = vi.fn();
                Element.prototype.scrollIntoView = scroll;
            });

            afterEach(() => {
                delete Element.prototype.scrollIntoView;
                component.$refs.createModal.remove();
            });

            it('scrolls to the first input that was refused and puts the cursor in it', async () => {
                await mount();
                http.post.mockRejectedValue(
                    failure(422, { errors: { label: ['Too long.'], 'signers.0.email': ['Not valid.'] } }),
                );
                const dialog = render({ label: 'Too long.', email: 'Not valid.' });
                component.startCreate();

                await component.submit();

                expect(scroll).toHaveBeenCalledTimes(1);
                expect(scroll.mock.contexts[0]).toBe(dialog.querySelector('#version-label'));
                expect(document.activeElement).toBe(dialog.querySelector('#version-label'));
            });

            it('does not look for a refusal when the request was cancelled', async () => {
                await mount();
                http.post.mockRejectedValue(new Error('cancelled'));
                render({ label: 'Too long.' });
                component.startCreate();

                await component.submit();

                expect(scroll).not.toHaveBeenCalled();
            });
        });

        it('forgets a refusal when the dialog is closed, however it was', async () => {
            await mount();
            http.post.mockRejectedValue(failure(422, { errors: { label: ['Too long.'] } }));
            component.startCreate();
            await component.submit();

            expect(component.errors.label).toBe('Too long.');

            dismiss(component.$refs.createModal);
            await vi.advanceTimersByTimeAsync(1);

            expect(component.errors).toEqual({});
            expect(component.formError).toBe('');
        });

        it('forgets the refusals on the lines when one of them is removed (they have moved)', async () => {
            await mount();
            http.post.mockRejectedValue(failure(422, { errors: { 'signers.1.email': ['Not valid.'] } }));
            component.startCreate();
            component.toggleSign({ target: { checked: true } });
            component.addSigner();
            await component.submit();

            expect(component.signerError(1, 'email')).toBe('Not valid.');

            component.removeSigner(0);

            expect(component.signerError(1, 'email')).toBe('');
        });

        it('does not send a second request while the first is on its way', async () => {
            await mount();
            const slow = deferred();

            http.post.mockReturnValue(slow.promise);
            component.startCreate();

            const first = component.submit();
            const second = component.submit();

            slow.resolve({ data: onItsWay('new') });
            await Promise.all([first, second]);

            expect(http.post).toHaveBeenCalledTimes(1);
        });

        it('tells nothing of a request that was cancelled', async () => {
            await mount();
            http.post.mockRejectedValue(new DOMException('Aborted', 'AbortError'));
            component.startCreate();

            await component.submit();

            expect(component.formError).toBe('');
            expect(notify).not.toHaveBeenCalled();
        });
    });

    describe('cancelling the request for signatures', () => {
        /** A version whose request awaits its signers, and that the person may cancel. */
        const awaiting = (id = 'a') =>
            version(id, {
                number: 6,
                signature: signature('awaiting'),
                abilities: { delete: true, cancel_signature: true, retry_signature: false },
            });

        it('asks first, naming the version', async () => {
            await mount(page([awaiting()]));

            component.askCancel(component.rows[0]);

            expect(showModal).toHaveBeenCalledWith(component.$refs.confirmModal);
            expect(component.confirmTitle).toBe('Cancel the signature request');
            expect(component.confirmText).toBe(
                'Cancel the signature request of version no. 6? The signers can no longer sign it.',
            );
            expect(component.confirmButton).toBe('Cancel the request');
            expect(http.post).not.toHaveBeenCalled();
        });

        it('cancels it once confirmed, and shows the version as the API answers it', async () => {
            await mount(page([awaiting()]));
            http.post.mockResolvedValue({
                data: {
                    ...awaiting(),
                    signature: signature('cancelled'),
                    abilities: { delete: true, cancel_signature: false, retry_signature: false },
                },
            });
            component.askCancel(component.rows[0]);

            await component.confirmAction();

            expect(http.post).toHaveBeenCalledWith(`${VERSIONS_URL}/a/signature/cancel`);
            expect(hideModal).toHaveBeenCalledWith(component.$refs.confirmModal);
            expect(component.rows[0].signatureLabel).toBe('Cancelled');
            expect(component.rows[0].canCancel).toBe(false);
            expect(notify).toHaveBeenCalledWith('success', 'Signature request cancelled.');
            expect(component.working).toEqual([]);
        });

        it('does nothing when the dialog is dismissed', async () => {
            await mount(page([awaiting()]));
            component.askCancel(component.rows[0]);

            dismiss(component.$refs.confirmModal);
            await vi.advanceTimersByTimeAsync(1);

            expect(component.confirming).toBeNull();
            expect(http.post).not.toHaveBeenCalled();
        });

        it('reads the version again and says so when the request was not waiting for its signers any more', async () => {
            await mount(page([awaiting()]));
            http.post.mockRejectedValue(failure(409, { code: 'signature_not_pending' }));
            http.get.mockResolvedValue({ data: version('a', { number: 6, signature: signature('completed') }) });
            component.askCancel(component.rows[0]);

            await component.confirmAction();

            expect(notify).toHaveBeenCalledWith('warning', 'This signature request is not waiting for its signers.');
            expect(oneReads('a')).toHaveLength(1);
            expect(component.rows[0].signatureLabel).toBe('Signed by everyone');
        });

        it('leaves the helper to tell a service that cannot be reached', async () => {
            await mount(page([awaiting()]));
            http.post.mockRejectedValue(failure(503, { code: 'documenso_unavailable', reported: true }));
            component.askCancel(component.rows[0]);

            await component.confirmAction();

            expect(notify).not.toHaveBeenCalled();
            expect(component.rows[0].signatureLabel).toBe('Awaiting signatures');
            expect(component.working).toEqual([]);
        });

        it('does not ask while an action is on its way for the version', async () => {
            await mount(page([awaiting()]));
            component.working = ['a'];

            component.askCancel(component.rows[0]);

            expect(showModal).not.toHaveBeenCalled();
        });
    });

    describe('sending the request for signatures again', () => {
        /** A version whose request failed, and that the person may send again. */
        const failed = () =>
            version('a', {
                number: 6,
                signature: signature('failed', { error: 'The signature service could not be reached.' }),
                abilities: { delete: true, cancel_signature: false, retry_signature: true },
            });

        it('sends it at once, with no question, and follows the request', async () => {
            await mount(page([failed()]));
            http.post.mockResolvedValue({ data: { ...failed(), signature: signature('requested') } });

            await component.retryRequest(component.rows[0]);

            expect(http.post).toHaveBeenCalledWith(`${VERSIONS_URL}/a/signature/retry`);
            expect(showModal).not.toHaveBeenCalled();
            expect(component.rows[0].signatureLabel).toBe('Waiting to be sent');
            expect(notify).toHaveBeenCalledWith('success', 'Signature request sent again.');

            http.get.mockResolvedValue({ data: { ...failed(), signature: signature('awaiting') } });
            await vi.advanceTimersByTimeAsync(2000);

            expect(oneReads('a')).toHaveLength(1);
            expect(component.rows[0].signatureLabel).toBe('Awaiting signatures');
        });

        it('says the instance has no signature service', async () => {
            await mount(page([failed()]));
            http.post.mockRejectedValue(failure(409, { code: 'signature_unavailable' }));

            await component.retryRequest(component.rows[0]);

            expect(notify).toHaveBeenCalledWith('danger', 'Signatures cannot be asked for.');
            expect(component.rows[0].signatureLabel).toBe('Failed');
        });

        it('reads the version again when the request had not failed', async () => {
            await mount(page([failed()]));
            http.post.mockRejectedValue(failure(409, { code: 'signature_not_failed' }));
            http.get.mockResolvedValue({ data: { ...failed(), signature: signature('awaiting') } });

            await component.retryRequest(component.rows[0]);

            expect(notify).toHaveBeenCalledWith('warning', 'This signature request did not fail.');
            expect(component.rows[0].signatureLabel).toBe('Awaiting signatures');
        });

        it('does not send a second request while the first is on its way', async () => {
            await mount(page([failed()]));
            const slow = deferred();

            http.post.mockReturnValue(slow.promise);
            const first = component.retryRequest(component.rows[0]);

            await component.retryRequest(component.rows[0]);
            slow.resolve({ data: { ...failed(), signature: signature('requested') } });
            await first;

            expect(http.post).toHaveBeenCalledTimes(1);
        });
    });

    describe('deleting a version', () => {
        it('asks first, naming the version', async () => {
            await mount(page([version('a', { number: 6 })]));

            component.askDelete(component.rows[0]);

            expect(showModal).toHaveBeenCalledWith(component.$refs.confirmModal);
            expect(component.confirmTitle).toBe('Delete the version');
            expect(component.confirmText).toBe('Delete version no. 6? Its files go with it. This cannot be undone.');
            expect(component.confirmButton).toBe('Delete');
            expect(http.delete).not.toHaveBeenCalled();
        });

        it('deletes it once confirmed, takes it out of the list, tells it and puts the cursor on the title', async () => {
            await mount(page([version('b', { number: 7 }), version('a', { number: 6 })], { total: 2 }));
            http.delete.mockResolvedValue(null);
            component.askDelete(component.rows[1]);

            await component.confirmAction();

            expect(http.delete).toHaveBeenCalledWith(`${VERSIONS_URL}/a`);
            expect(component.versions.map((one) => one.id)).toEqual(['b']);
            expect(component.total).toBe(1);
            expect(notify).toHaveBeenCalledWith('success', 'Version deleted.');
            expect(component.$refs.title.focus).toHaveBeenCalled();
            expect(component.working).toEqual([]);
        });

        it('leaves the list as it was when the version cannot be deleted, and says why', async () => {
            await mount(page([version('a')]));
            http.delete.mockRejectedValue(failure(409, { message: 'Not now' }));
            component.askDelete(component.rows[0]);

            await component.confirmAction();

            expect(component.versions).toHaveLength(1);
            expect(notify).toHaveBeenCalledWith('danger', 'Not now');
            expect(component.working).toEqual([]);
        });

        it('lets a version that was already deleted leave the list without another word', async () => {
            await mount(page([version('a')], { total: 1 }));
            http.delete.mockRejectedValue(failure(404, { reported: true }));
            component.askDelete(component.rows[0]);

            await component.confirmAction();

            expect(component.versions).toHaveLength(0);
            expect(component.total).toBe(0);
            expect(notify).not.toHaveBeenCalled();
        });

        it('does nothing when there is nothing to confirm', async () => {
            await mount(page([version('a')]));

            await component.confirmAction();

            expect(http.delete).not.toHaveBeenCalled();
            expect(hideModal).not.toHaveBeenCalled();
        });
    });

    describe('copying the SHA-256', () => {
        afterEach(() => {
            vi.unstubAllGlobals();
        });

        it('copies the whole value and says so', async () => {
            await mount(page([version('a')]));
            const writeText = vi.fn().mockResolvedValue(undefined);

            vi.stubGlobal('navigator', { clipboard: { writeText } });

            await component.copyHash(component.rows[0]);

            expect(writeText).toHaveBeenCalledWith('a'.repeat(64));
            expect(component.status).toBe('SHA-256 copied.');
        });

        it('shows the value in a toast that stays when the browser does not let the page copy', async () => {
            await mount(page([version('a')]));
            vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });

            await component.copyHash(component.rows[0]);

            expect(notify).toHaveBeenCalledWith(
                'warning',
                `Copying is not possible here. The SHA-256 is ${'a'.repeat(64)}`,
                { timeout: 0 },
            );
        });
    });

    describe('the signers of the cards', () => {
        it('shows every signer with where they stand', async () => {
            await mount(
                page([
                    version('a', {
                        signature: signature('awaiting', {
                            signers: [
                                signer('Ada Lovelace', { status: 'signed', signed_at: '2026-10-16T09:00:00Z' }),
                                signer('Grace Hopper', { status: 'rejected', rejection_reason: 'Wrong totals' }),
                            ],
                        }),
                    }),
                ]),
            );

            const [ada, grace] = component.rows[0].signers;

            expect(ada.statusLabel).toBe('Signed');
            expect(grace.statusLabel).toBe('Refused');
            expect(grace.detail).toBe('Reason: Wrong totals');
        });
    });
});
