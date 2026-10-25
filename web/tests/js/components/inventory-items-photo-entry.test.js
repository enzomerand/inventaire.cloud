/**
 * Adding an item from photos on the page of an inventory, at the level of the component: who is offered it, the choice of the
 * photos, what is sent and how the analysis is followed, the review of what it proposes (nothing checked for the person), the
 * failures, and the form of the new item that "Continue" opens with the accepted values and the photos.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import inventoryItems from '../../../resources/js/components/inventory-items.js';
import { toInput } from '../../../resources/js/fields/index.js';
import { HttpError, http } from '../../../resources/js/lib/http.js';
import { hideModal, showModal } from '../../../resources/js/lib/modal.js';
import { notify } from '../../../resources/js/lib/notify.js';
import { redirectTo, reloadPage } from '../../../resources/js/lib/page.js';
import { jpegName, prepareAnalysisPhoto, UnreadablePhoto } from '../../../resources/js/photo-entry/photos.js';
import { MAX_WAIT_MS, POLL_INTERVAL_MS } from '../../../resources/js/photo-entry/proposals.js';
import {
    FIELDS,
    INVENTORY,
    ITEMS,
    PHOTO_ANALYSES,
    field,
    inventoryAnswer,
    mountComponent,
    page,
} from '../support/inventory.js';
import { dismiss, forgetDialogs } from '../support/modal.js';

vi.mock('../../../resources/js/lib/http.js', async (importOriginal) => {
    const original = await importOriginal();

    return { ...original, http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});
vi.mock('../../../resources/js/lib/notify.js', () => ({ notify: vi.fn() }));
vi.mock('../../../resources/js/lib/page.js', () => ({ redirectTo: vi.fn(), reloadPage: vi.fn() }));
vi.mock('../../../resources/js/lib/modal.js', async () => import('../support/modal.js'));
vi.mock('../../../resources/js/lib/offcanvas.js', () => ({ showOffcanvas: vi.fn(), hideOffcanvas: vi.fn() }));
vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));
// jsdom decodes no image and draws nothing: the redrawing of a photo is `photos.test.js`; here it gives a JPEG of its own.
vi.mock('../../../resources/js/photo-entry/photos.js', async (importOriginal) => {
    const original = await importOriginal();

    return {
        ...original,
        prepareAnalysisPhoto: vi.fn(
            async (file) => new File(['small'], original.jpegName(file.name), { type: 'image/jpeg' }),
        ),
    };
});

const PHOTOS = field('photos', 'photo', { max_files: 3 }, { name: 'Photos', position: 1 });
const MANUAL = field('manual', 'file', {}, { name: 'Manual', position: 2 });
const WITH_PHOTOS = [...FIELDS, PHOTOS, MANUAL];

/** A photo the person picked. */
const photo = (name = 'front.jpg', type = 'image/jpeg') => new File(['x'.repeat(16)], name, { type });

/** An analysis, as the API describes it. */
function analysis(status, extra = {}) {
    return {
        data: {
            id: 'a1',
            status,
            error: null,
            proposals: [],
            created_at: '2026-10-25T10:00:00Z',
            completed_at: null,
            ...extra,
        },
    };
}

/** An analysis that ended well, with proposals. */
const completed = (proposals) => analysis('completed', { proposals, completed_at: '2026-10-25T10:00:09Z' });

/** An analysis that ended badly, for a reason. */
const failed = (error) => analysis('failed', { error, completed_at: '2026-10-25T10:00:09Z' });

/** A proposal, as the API describes it. */
const proposal = (fieldId, value, confidence = 'high', evidence = 'on the label') => ({
    field: fieldId,
    value,
    confidence,
    evidence,
});

/** The proposals of the tests: a name, a state (a choice), a weight, and a yes / no. */
const PROPOSALS = [
    proposal('name', 'Hammer', 'high', 'printed on the handle'),
    proposal('state', 'aaaaaaaa', 'medium', 'looks unused'),
    proposal('qty', '1.50', 'low', 'sticker'),
    proposal('fragile', false, 'high', 'no warning'),
];

/** Lets the pending promises run. */
const settle = () => vi.advanceTimersByTimeAsync(0);

/** Waits for the fade of a dialog to be over, so that its `hidden.bs.modal` has been fired (the timers are fake here). */
const fadeOut = settle;

/** Lets the time of one reading of the analysis pass, and the reading with it. */
const nextReading = () => vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);

/** The event a file chooser fires when a person picks files. */
function picked(...files) {
    return { target: { files, value: 'C:\\fakepath\\' + files[0]?.name } };
}

/** The event a checkbox fires when it is clicked. */
const checkbox = (checked) => ({ target: { checked } });

/** The readings of the analysis so far. */
const readings = () => http.get.mock.calls.filter((call) => call[0].startsWith(PHOTO_ANALYSES));

describe('adding an item from photos', () => {
    let component;
    /** What the next readings of the analysis answer: a value, or an error to throw. */
    let answers;

    /** Answers the reads of the page, and the readings of the analysis from `answers`. */
    function answerReads(fields) {
        http.get.mockImplementation(async (url) => {
            if (url === INVENTORY) {
                return inventoryAnswer(fields, 0);
            }

            if (url === ITEMS) {
                return page([]);
            }

            const next = answers.length > 1 ? answers.shift() : answers[0];

            if (next instanceof Error) {
                throw next;
            }

            return next;
        });
    }

    /** Opens the page of an inventory, then the dialog of the photos. */
    async function open({ fields = WITH_PHOTOS, mounting = { photoEntry: true } } = {}) {
        component = mountComponent(inventoryItems, { ...mounting, laterTick: true });
        answerReads(fields);
        await component.init();
        await settle();
        http.get.mockClear();
        component.openPhotoEntry();
    }

    /** Chooses photos, and sends them: the dialog then waits for the analysis. */
    async function analyse(...files) {
        component.choosePhotos(picked(...(files.length > 0 ? files : [photo()])));
        await component.analysePhotos();
        await settle();
    }

    /** Takes the analysis to its review. */
    async function review(proposals = PROPOSALS, ...files) {
        http.post.mockResolvedValue(analysis('pending'));
        answers = [completed(proposals)];
        await analyse(...files);
        await nextReading();
    }

    beforeEach(() => {
        vi.useFakeTimers();
        forgetDialogs();
        showModal.mockClear();
        hideModal.mockClear();
        document.body.innerHTML = '';
        answers = [analysis('pending')];
        URL.createObjectURL = vi.fn((file) => `blob:${file.name}`);
        URL.revokeObjectURL = vi.fn();
    });

    afterEach(() => {
        component.destroy();
        vi.useRealTimers();
        delete URL.createObjectURL;
        delete URL.revokeObjectURL;
        window.history.replaceState(null, '', '/');
    });

    describe('who is offered it', () => {
        it('is offered when the page says so, to a person who may add items, in an inventory that has a field to fill', async () => {
            await open();

            expect(component.photoEnabled).toBe(true);
            expect(component.photoEntryUrl).toBe(PHOTO_ANALYSES);
            expect(component.canPhotoEntry).toBe(true);
        });

        it.each([
            ['the page says nothing about it', {}],
            ['the instance or the workspace turned it off', { photoEntry: false }],
            ['the person may not edit the items', { photoEntry: true, canEdit: false }],
        ])('is not offered when %s', async (_why, mounting) => {
            await open({ mounting });

            expect(component.canPhotoEntry).toBe(false);

            component.openPhotoEntry();

            expect(showModal).not.toHaveBeenCalled();
            expect(component.photoOpen).toBe(false);
        });

        it('is not offered in an inventory whose only fields are photos and files (nothing to read into)', async () => {
            await open({ fields: [PHOTOS, MANUAL] });

            expect(component.photoFillable).toBe(false);
            expect(component.canPhotoEntry).toBe(false);
        });

        it('is not offered in an inventory with no field at all', async () => {
            await open({ fields: [] });

            expect(component.canPhotoEntry).toBe(false);
        });
    });

    describe('choosing the photos', () => {
        it('opens its own dialog on the choice, with the cursor in the file chooser', async () => {
            await open();

            expect(component.photoOpen).toBe(true);
            expect(component.photoStep).toBe('choose');
            expect(showModal).toHaveBeenCalledTimes(1);
            expect(showModal).toHaveBeenCalledWith(component.$refs.photoModal, { focus: ['#photo-entry-pick'] });
            expect(component.canAnalysePhotos).toBe(false);
        });

        it('lists the photos with a thumbnail, in the order they were chosen', async () => {
            await open();

            component.choosePhotos(picked(photo('a.jpg'), photo('b.jpg')));

            expect(component.photoPhotos.map((one) => [one.name, one.url])).toEqual([
                ['a.jpg', 'blob:a.jpg'],
                ['b.jpg', 'blob:b.jpg'],
            ]);
            expect(component.photoPhotos[0].removeLabel).toBe('Remove the photo a.jpg');
            expect(component.photoCountText).toBe('2 of 5 photos');
            expect(component.canAnalysePhotos).toBe(true);
        });

        it('empties the chooser, so that the same photo can be chosen again', async () => {
            await open();

            const event = picked(photo());

            component.choosePhotos(event);

            expect(event.target.value).toBe('');
        });

        it('takes five photos at most, and says the others were left out', async () => {
            await open();

            component.choosePhotos(picked(...['1', '2', '3', '4', '5', '6', '7'].map((n) => photo(`${n}.jpg`))));

            expect(component.photoPhotos.map((one) => one.name)).toEqual(['1.jpg', '2.jpg', '3.jpg', '4.jpg', '5.jpg']);
            expect(component.photoMessage).toBe('An analysis takes 5 photos at most: the others were left out.');
            expect(component.photoRoom).toBe(0);
            expect(component.canChoosePhotos).toBe(false);

            component.choosePhotos(picked(photo('8.jpg')));

            expect(component.photoPhotos).toHaveLength(5);
        });

        it('takes as many photos as the page says (data-photo-entry-max)', async () => {
            await open();
            component.rootElement.dataset.photoEntryMax = '2';

            component.choosePhotos(picked(photo('1.jpg'), photo('2.jpg'), photo('3.jpg')));

            expect(component.photoMax).toBe(2);
            expect(component.photoPhotos).toHaveLength(2);
            expect(component.photoMessage).toBe('An analysis takes 2 photos at most: the others were left out.');
        });

        it('leaves out a file that is no image, and says which', async () => {
            await open();

            component.choosePhotos(picked(photo('manual.pdf', 'application/pdf'), photo('ok.jpg')));

            expect(component.photoPhotos.map((one) => one.name)).toEqual(['ok.jpg']);
            expect(component.photoMessage).toBe('manual.pdf is not an image.');
        });

        it('takes a file that has no type (a camera roll on some browsers): the redrawing decides', async () => {
            await open();

            component.choosePhotos(picked(photo('IMG_0001.HEIC', '')));

            expect(component.photoPhotos).toHaveLength(1);
            expect(component.photoMessage).toBe('');
        });

        it('takes a photo back, frees its address and its room, and forgets the message', async () => {
            await open();
            component.choosePhotos(picked(photo('a.jpg'), photo('b.jpg')));

            component.removePhoto(component.photoPhotos[0]);

            expect(component.photoPhotos.map((one) => one.name)).toEqual(['b.jpg']);
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:a.jpg');
            expect(component.photoRoom).toBe(4);
        });

        it('lets go of the photos and their addresses when the dialog is closed', async () => {
            await open();
            component.choosePhotos(picked(photo('a.jpg'), photo('b.jpg')));

            dismiss(component.$refs.photoModal);
            await fadeOut();

            expect(component.photoOpen).toBe(false);
            expect(component.photoPhotos).toEqual([]);
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:a.jpg');
            expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:b.jpg');

            component.openPhotoEntry();

            expect(component.photoPhotos).toEqual([]);
            expect(component.photoStep).toBe('choose');
        });
    });

    describe('sending the photos', () => {
        it('redraws each photo, then sends them all in one multipart request, as photos[]', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));

            await analyse(photo('a.jpg'), photo('b.HEIC', 'image/heic'));

            expect(prepareAnalysisPhoto).toHaveBeenCalledTimes(2);
            expect(http.post).toHaveBeenCalledTimes(1);

            const [url, body, options] = http.post.mock.calls[0];

            expect(url).toBe(PHOTO_ANALYSES);
            expect(body).toBeInstanceOf(FormData);
            expect(body.getAll('photos[]').map((file) => [file.name, file.type])).toEqual([
                ['a.jpg', 'image/jpeg'],
                ['b.jpg', 'image/jpeg'],
            ]);
            expect(options.signal).toBeInstanceOf(AbortSignal);
            expect(options.timeout).toBeGreaterThanOrEqual(120_000);
        });

        it('sends nothing without a photo', async () => {
            await open();

            await component.analysePhotos();

            expect(http.post).not.toHaveBeenCalled();
            expect(component.photoStep).toBe('choose');
        });

        it('waits for the analysis, with a spinner that tells what is going on', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));

            await analyse();

            expect(component.photoStep).toBe('working');
            expect(component.photoWorking).toBe(true);
            expect(component.photoBusy).toBe('analysing');
            expect(component.photoWorkingText).toBe('Claude is reading the photos…');
            expect(component.photoStatus).toBe('Claude is reading the photos…');
            expect(component.canAnalysePhotos).toBe(false);
            expect(component.canChoosePhotos).toBe(false);
        });

        it('tells the person while the photos are being redrawn and sent', async () => {
            await open();

            let release;

            prepareAnalysisPhoto.mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        release = () => resolve(new File(['small'], 'front.jpg', { type: 'image/jpeg' }));
                    }),
            );
            http.post.mockResolvedValue(analysis('pending'));
            component.choosePhotos(picked(photo()));

            const sending = component.analysePhotos();

            await settle();

            expect(component.photoBusy).toBe('preparing');
            expect(component.photoWorkingText).toBe('Preparing the photos…');

            release();
            await sending;

            expect(component.photoBusy).toBe('analysing');
        });

        it('does not let the photos change while they are on their way', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            await analyse(photo('a.jpg'));

            component.choosePhotos(picked(photo('b.jpg')));
            component.removePhoto(component.photoPhotos[0]);

            expect(component.photoPhotos.map((one) => one.name)).toEqual(['a.jpg']);
        });

        it('asks about the analysis every two seconds, silently, until it ends', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [analysis('pending'), analysis('running'), completed(PROPOSALS)];

            await analyse();

            expect(readings()).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS - 1);

            expect(readings()).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(1);

            expect(readings()).toHaveLength(1);
            expect(readings()[0][0]).toBe(`${PHOTO_ANALYSES}/a1`);
            expect(readings()[0][1].silent).toBe(true);
            expect(readings()[0][1].signal).toBeInstanceOf(AbortSignal);
            expect(component.photoStep).toBe('working');

            await nextReading();

            expect(readings()).toHaveLength(2);
            expect(component.photoStep).toBe('working');

            await nextReading();

            expect(readings()).toHaveLength(3);
            expect(component.photoStep).toBe('review');

            await vi.advanceTimersByTimeAsync(10 * POLL_INTERVAL_MS);

            expect(readings()).toHaveLength(3);
        });

        it('stops asking, and cancels the reading on its way, when the dialog is closed', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            http.get.mockImplementation(
                (url, options) =>
                    new Promise((_resolve, reject) => {
                        options.signal.addEventListener('abort', () =>
                            reject(Object.assign(new Error('aborted'), { name: 'AbortError' })),
                        );
                        expect(url).toBe(`${PHOTO_ANALYSES}/a1`);
                    }),
            );
            await analyse();
            await nextReading();

            const { signal } = readings()[0][1];

            expect(signal.aborted).toBe(false);

            dismiss(component.$refs.photoModal);
            await fadeOut();

            expect(signal.aborted).toBe(true);

            await vi.advanceTimersByTimeAsync(10 * POLL_INTERVAL_MS);

            expect(readings()).toHaveLength(1);
            expect(component.photoStep).toBe('choose');
            expect(notify).not.toHaveBeenCalled();
        });

        it('stops asking when the page is taken apart', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            await analyse();

            component.destroy();
            await vi.advanceTimersByTimeAsync(10 * POLL_INTERVAL_MS);

            expect(readings()).toHaveLength(0);
        });

        it('gives up the analysis when the person cancels: the photos stay chosen, a late answer is dropped', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));

            const late = [];

            http.get.mockImplementation(
                (_url, options) =>
                    new Promise((resolve) => {
                        late.push({ resolve, signal: options.signal });
                    }),
            );
            await analyse(photo('a.jpg'), photo('b.jpg'));
            await nextReading();

            component.backToPhotos();

            expect(late[0].signal.aborted).toBe(true);
            expect(component.photoStep).toBe('choose');
            expect(component.photoWorking).toBe(false);
            expect(component.photoPhotos.map((one) => one.name)).toEqual(['a.jpg', 'b.jpg']);

            late[0].resolve(completed(PROPOSALS));
            await settle();

            expect(component.photoStep).toBe('choose');
            expect(component.photoRows).toEqual([]);
        });

        it('gives up an upload that is on its way when the person cancels', async () => {
            await open();

            let signal;

            http.post.mockImplementation((_url, _body, options) => {
                signal = options.signal;

                return new Promise(() => {});
            });
            component.choosePhotos(picked(photo()));
            component.analysePhotos();
            await settle();

            expect(component.photoBusy).toBe('uploading');

            component.backToPhotos();

            expect(signal.aborted).toBe(true);
            expect(component.photoStep).toBe('choose');
        });

        it('shows the review at once when the analysis is already over', async () => {
            await open();
            http.post.mockResolvedValue(completed(PROPOSALS));

            await analyse();

            expect(component.photoStep).toBe('review');
            expect(readings()).toHaveLength(0);
        });
    });

    describe('the review', () => {
        it('has one row for each proposal, as the table would show the value, with the confidence and the evidence', async () => {
            await open();

            await review();

            expect(component.photoStep).toBe('review');
            expect(component.photoStatus).toBe('Claude found 4 values.');
            expect(component.photoHasRows).toBe(true);
            expect(
                component.photoRows.map((row) => [row.key, row.name, row.kind, row.text, row.levelText, row.evidence]),
            ).toEqual([
                ['name', 'Name', 'text', 'Hammer', 'High confidence', 'printed on the handle'],
                ['state', 'State', 'badges', '', 'Medium confidence', 'looks unused'],
                ['qty', 'Weight', 'text', '1.50 kg', 'Low confidence', 'sticker'],
                ['fragile', 'Fragile', 'badges', '', 'High confidence', 'no warning'],
            ]);
            expect(component.photoRows[1].badges.map((badge) => badge.text)).toEqual(['New']);
            expect(component.photoRows[3].badges.map((badge) => badge.text)).toEqual(['No']);
            expect(component.photoRows.map((row) => row.levelClass)).toEqual([
                'badge bg-green-lt',
                'badge bg-yellow-lt',
                'badge bg-orange-lt',
                'badge bg-green-lt',
            ]);
        });

        it('starts with every row unchecked: nothing is accepted for the person', async () => {
            await open();

            await review();

            expect(component.photoRows.map((row) => component.isProposalChecked(row))).toEqual([
                false,
                false,
                false,
                false,
            ]);
            expect(component.photoCheckedCount).toBe(0);
            expect(component.photoSummary).toBe('0 of 4 values checked');
        });

        it('checks and unchecks a row, and counts them', async () => {
            await open();
            await review();

            const [name, state] = component.photoRows;

            component.toggleProposal(name, checkbox(true));
            component.toggleProposal(state, checkbox(true));

            expect(component.isProposalChecked(name)).toBe(true);
            expect(component.photoCheckedCount).toBe(2);
            expect(component.photoSummary).toBe('2 of 4 values checked');

            component.toggleProposal(name, checkbox(false));

            expect(component.isProposalChecked(name)).toBe(false);
            expect(component.photoCheckedCount).toBe(1);
        });

        it('is the same whatever the case of the id of the field', async () => {
            await open();

            await review([proposal('NAME', 'Hammer')]);

            expect(component.photoRows.map((row) => row.key)).toEqual(['name']);
        });

        it('leaves out what is not about the form: an unknown field, a photo or file field, a blank value, a field proposed twice', async () => {
            await open();

            await review([
                proposal('gone', 'Ghost'),
                proposal('photos', 'a1'),
                proposal('manual', 'a2'),
                proposal('name', ''),
                proposal('state', []),
                proposal('qty', '2'),
                proposal('qty', '3'),
                proposal('fragile', true),
            ]);

            expect(component.photoRows.map((row) => row.key)).toEqual(['qty', 'fragile']);
            expect(component.photoRows[0].text).toBe('2.00 kg');
            expect(component.photoRows[1].badges.map((badge) => badge.text)).toEqual(['Yes']);
        });

        it('shows a link as text: nobody has checked the address yet', async () => {
            await open({ fields: [field('site', 'url', {}, { name: 'Site' })] });

            await review([proposal('site', 'https://example.com')]);

            expect(component.photoRows[0].kind).toBe('link');
            expect(component.photoRows[0].plain).toBe(true);
        });

        it('says so when nothing was found on the photos', async () => {
            await open();

            await review([]);

            expect(component.photoStep).toBe('review');
            expect(component.photoHasRows).toBe(false);
            expect(component.photoStatus).toBe('Claude found 0 values.');
        });

        it('is forgotten when the person goes back to the photos', async () => {
            await open();
            await review();
            component.toggleProposal(component.photoRows[0], checkbox(true));

            component.backToPhotos();

            expect(component.photoStep).toBe('choose');
            expect(component.photoRows).toEqual([]);
            expect(component.photoChecked).toEqual({});
            expect(component.photoPhotos).toHaveLength(1);
        });

        it('starts a new analysis from the same photos without redrawing them again', async () => {
            await open();
            await review();
            component.backToPhotos();
            prepareAnalysisPhoto.mockClear();
            answers = [completed([proposal('name', 'Saw')])];

            await component.analysePhotos();
            await nextReading();

            expect(prepareAnalysisPhoto).not.toHaveBeenCalled();
            expect(http.post).toHaveBeenCalledTimes(2);
            expect(component.photoRows.map((row) => row.text)).toEqual(['Saw']);
            expect(component.photoCheckedCount).toBe(0);
        });
    });

    describe('when the analysis failed', () => {
        // The words of every code are `proposals.test.js`'s: one that can be tried again and one that cannot are enough to see the
        // dialog show them.
        it.each([
            ['refused', 'The photos were refused by the analysis service. Try other photos.', false],
            ['truncated', 'The answer was too long to be read. Try again, with fewer photos if you can.', true],
        ])('says what %s means, and whether trying again can help', async (code, text, retry) => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [failed(code)];

            await analyse();
            await nextReading();

            expect(component.photoStep).toBe('error');
            expect(component.photoFailure).toEqual({ text, retry, resume: false });
            expect(component.photoCanRetry).toBe(retry);
            expect(component.photoStatus).toBe(text);
            expect(component.photoWorking).toBe(false);
        });

        it('sends the photos again on a retry, and keeps them chosen when the person goes back', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [failed('unavailable'), completed(PROPOSALS)];
            await analyse(photo('a.jpg'));
            await nextReading();
            prepareAnalysisPhoto.mockClear();

            component.retryPhotoAnalysis();
            await settle();
            await nextReading();

            expect(http.post).toHaveBeenCalledTimes(2);
            expect(prepareAnalysisPhoto).not.toHaveBeenCalled();
            expect(component.photoStep).toBe('review');
        });

        it('goes back to the photos, which stay chosen', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [failed('refused')];
            await analyse(photo('a.jpg'));
            await nextReading();

            component.backToPhotos();

            expect(component.photoStep).toBe('choose');
            expect(component.photoPhotos.map((one) => one.name)).toEqual(['a.jpg']);
            expect(component.photoFailure.text).toBe('');
        });
    });

    describe('when a request is refused', () => {
        /** Sends one photo, the API answering the request with an error. */
        async function refused(status, message, details = {}) {
            await open();
            http.post.mockRejectedValue(new HttpError(status, message, details));
            await analyse();
        }

        it('says to wait when the analyses are throttled (429), and offers to try again', async () => {
            await refused(429, 'Slow down', { retryAfter: 30 });

            expect(component.photoStep).toBe('error');
            // The wording of the throttling the pages share: seconds, then minutes.
            expect(component.photoFailure.text).toBe('Too many attempts. Try again in 30 seconds.');
            expect(component.photoCanRetry).toBe(true);
        });

        it('shows the words of the helper for anything else (the server, the network), and offers to try again', async () => {
            await refused(500, 'The server hit an error.', {});

            expect(component.photoFailure).toEqual({ text: 'The server hit an error.', retry: true, resume: false });
        });

        it.each([
            ['the network', 0, 'Network down', { kind: 'network' }],
            ['the server', 500, 'The server hit an error.', {}],
            ['an ended session', 401, 'Please sign in', {}],
            ['a stale page', 419, 'Session expired', {}],
            ['a right that is gone', 403, 'Not allowed', {}],
        ])(
            'does not tell twice what the helper already told with a toast (%s): the photos stay chosen, to send again',
            async (_what, status, message, details) => {
                await open();
                const error = new HttpError(status, message, details);
                error.reported = true;
                http.post.mockRejectedValue(error);

                await analyse(photo('a.jpg'));

                expect(component.photoStep).toBe('choose');
                expect(component.photoBusy).toBe('');
                expect(component.photoWorking).toBe(false);
                expect(component.photoFailure.text).toBe('');
                expect(component.photoCanRetry).toBe(false);
                expect(component.photoPhotos.map((one) => one.name)).toEqual(['a.jpg']);
                expect(component.canAnalysePhotos).toBe(true);
            },
        );

        it('tells the wait of the throttling in the dialog although the helper toasted it too (429)', async () => {
            await open();
            const error = new HttpError(429, 'Slow down', { retryAfter: 30 });
            error.reported = true;
            http.post.mockRejectedValue(error);

            await analyse();

            expect(component.photoStep).toBe('error');
            expect(component.photoFailure.text).toBe('Too many attempts. Try again in 30 seconds.');
            expect(component.photoCanRetry).toBe(true);
        });

        it('sends the person to the workspace when the inventory is gone (404)', async () => {
            await refused(404, 'Gone');

            expect(redirectTo).toHaveBeenCalledWith('/app/w/shop');
            expect(component.photoStep).not.toBe('error');
        });

        it('sends the photos again on a retry', async () => {
            await refused(500, 'The server hit an error.');
            http.post.mockResolvedValue(analysis('pending'));
            answers = [completed(PROPOSALS)];

            component.retryPhotoAnalysis();
            await settle();
            await nextReading();

            expect(http.post).toHaveBeenCalledTimes(2);
            expect(component.photoStep).toBe('review');
        });

        it('goes back to the choice when a photo cannot be read by the browser, naming it, and sends nothing', async () => {
            await open();
            prepareAnalysisPhoto.mockRejectedValueOnce(new UnreadablePhoto('front.heic'));

            await analyse(photo('front.heic', 'image/heic'));

            expect(http.post).not.toHaveBeenCalled();
            expect(component.photoStep).toBe('choose');
            expect(component.photoMessage).toBe(
                'front.heic could not be read by your browser. Remove it, then try again.',
            );
            expect(component.photoPhotos).toHaveLength(1);
            expect(component.photoWorking).toBe(false);
        });
    });

    describe('when the analysis cannot be followed', () => {
        it('says the analysis is gone when it is not found (404): the photos have to be sent again', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [new HttpError(404, 'Gone')];

            await analyse();
            await nextReading();

            expect(component.photoStep).toBe('error');
            expect(component.photoFailure).toEqual({
                text: 'This analysis is no longer available. Send the photos again.',
                retry: true,
                resume: false,
            });

            answers = [completed(PROPOSALS)];
            component.retryPhotoAnalysis();
            await settle();
            await nextReading();

            expect(http.post).toHaveBeenCalledTimes(2);
            expect(component.photoStep).toBe('review');
        });

        it('forgives a reading that fails now and then', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [
                new HttpError(0, 'Network', { kind: 'network' }),
                new HttpError(500, 'Server'),
                analysis('running'),
                new HttpError(0, 'Network', { kind: 'network' }),
                new HttpError(0, 'Network', { kind: 'network' }),
                completed(PROPOSALS),
            ];
            await analyse();

            for (let reading = 0; reading < 6; reading += 1) {
                await nextReading();
            }

            expect(component.photoStep).toBe('review');
        });

        it('gives up after three readings in a row fail, and follows the same analysis again on a retry', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [new HttpError(0, 'Network', { kind: 'network' })];
            await analyse();

            await nextReading();
            await nextReading();
            await nextReading();

            expect(component.photoStep).toBe('error');
            expect(component.photoFailure).toEqual({
                text: 'The connection to the analysis was lost.',
                retry: true,
                resume: true,
            });

            await vi.advanceTimersByTimeAsync(10 * POLL_INTERVAL_MS);

            expect(readings()).toHaveLength(3);

            answers = [completed(PROPOSALS)];
            component.retryPhotoAnalysis();
            await settle();

            expect(http.post).toHaveBeenCalledTimes(1);
            expect(component.photoStep).toBe('review');
            expect(readings().at(-1)[0]).toBe(`${PHOTO_ANALYSES}/a1`);
        });

        it.each([401, 403, 419])(
            'gives up at once when the reading is refused (%i), with no retry that could only fail',
            async (status) => {
                await open();
                http.post.mockResolvedValue(analysis('pending'));
                answers = [new HttpError(status, 'No')];
                await analyse();

                await nextReading();

                expect(component.photoStep).toBe('error');
                expect(component.photoFailure).toEqual({ text: 'No', retry: false, resume: false });
                expect(component.photoCanRetry).toBe(false);
                expect(readings()).toHaveLength(1);
                expect(component.photoStatus).toBe('No');

                // Nothing is read any more.
                await vi.advanceTimersByTimeAsync(10 * POLL_INTERVAL_MS);

                expect(readings()).toHaveLength(1);
            },
        );

        it('reloads the page, as the helper does for a call that is not silent, when the reading finds the page stale (419)', async () => {
            await open();
            reloadPage.mockClear();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [new HttpError(419, 'Session expired')];
            await analyse();
            await nextReading();

            expect(reloadPage).not.toHaveBeenCalled();

            // After the time to read what the dialog says.
            await vi.advanceTimersByTimeAsync(1500);

            expect(reloadPage).toHaveBeenCalledTimes(1);
        });

        it('sends the person to the login page when the reading finds the session over (401)', async () => {
            document.head.insertAdjacentHTML('beforeend', '<meta name="login-url" content="/login">');
            await open();
            redirectTo.mockClear();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [new HttpError(401, 'Please sign in')];
            await analyse();
            await nextReading();
            await vi.advanceTimersByTimeAsync(1500);

            expect(redirectTo).toHaveBeenCalledWith('/login');
            document.head.querySelector('meta[name="login-url"]').remove();
        });

        it('does not reload the page when the reading is only refused (403): the person keeps what they read', async () => {
            await open();
            reloadPage.mockClear();
            redirectTo.mockClear();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [new HttpError(403, 'Not allowed')];
            await analyse();
            await nextReading();
            await vi.advanceTimersByTimeAsync(5000);

            expect(reloadPage).not.toHaveBeenCalled();
            expect(redirectTo).not.toHaveBeenCalled();
        });

        it('announces that the analysis is followed again when the person retries after the connection was lost', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [new HttpError(0, 'Network', { kind: 'network' })];
            await analyse();
            await nextReading();
            await nextReading();
            await nextReading();

            expect(component.photoStatus).toBe('The connection to the analysis was lost.');

            answers = [analysis('running')];
            component.retryPhotoAnalysis();

            expect(component.photoStep).toBe('working');
            expect(component.photoStatus).toBe('Claude is reading the photos…');
        });

        it('announces again words that did not change: the live region is emptied, then filled', async () => {
            await open();
            component.photoStatus = 'Claude is reading the photos…';

            component.announcePhoto('Claude is reading the photos…');

            expect(component.photoStatus).toBe('');

            await settle();

            expect(component.photoStatus).toBe('Claude is reading the photos…');

            component.announcePhoto('Something else');

            expect(component.photoStatus).toBe('Something else');
        });

        it('lets the last thing said win over a repeat that was still waiting for the region to be drawn', async () => {
            await open();
            component.photoStatus = 'Claude is reading the photos…';

            component.announcePhoto('Claude is reading the photos…');
            component.announcePhoto('Something else');
            await settle();

            expect(component.photoStatus).toBe('Something else');

            component.announcePhoto('Something else');
            component.announcePhoto('Something else');
            await settle();

            expect(component.photoStatus).toBe('Something else');
        });

        it('does not say again after the dialog was reset what a repeat was waiting to say', async () => {
            await open();
            component.photoStatus = 'Claude is reading the photos…';

            component.announcePhoto('Claude is reading the photos…');
            component.resetPhotoEntry();
            await settle();

            expect(component.photoStatus).toBe('');
        });

        it('stops following an analysis that takes more than a few minutes', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [analysis('running')];
            await analyse();

            await vi.advanceTimersByTimeAsync(MAX_WAIT_MS + 2 * POLL_INTERVAL_MS);

            expect(component.photoStep).toBe('error');
            expect(component.photoFailure).toEqual({
                text: 'The analysis is taking too long.',
                retry: true,
                resume: true,
            });
        });

        it('lets a bug in the code show, not a failure of the request', async () => {
            await open();
            http.post.mockResolvedValue(analysis('pending'));
            answers = [new TypeError('a bug')];
            await analyse();

            const reading = component.readPhotoAnalysis();

            await expect(reading).rejects.toThrow('a bug');
        });
    });

    describe('continuing', () => {
        const PHOTO_FIELD_ID = 'photos';

        /** Takes the review to the point where the person checked some rows and pressed "Continue". */
        async function goOn(checkedKeys, ...files) {
            await review(PROPOSALS, ...files);

            for (const row of component.photoRows.filter((one) => checkedKeys.includes(one.key))) {
                component.toggleProposal(row, checkbox(true));
            }

            component.continuePhotoEntry();
        }

        it('closes its dialog first, and opens the form of the new item only once that dialog has been hidden', async () => {
            await open();

            // Listens after the component does, so what it sees is what the component has done when the dialog has been hidden.
            let shownWhenHidden = null;

            component.$refs.photoModal.addEventListener('hidden.bs.modal', () => {
                shownWhenHidden = showModal.mock.calls.length;
            });
            await goOn(['name']);

            expect(hideModal).toHaveBeenCalledWith(component.$refs.photoModal);
            expect(showModal).toHaveBeenCalledTimes(1);
            expect(component.values).toEqual({});

            await fadeOut();
            await settle();

            expect(shownWhenHidden).toBe(1);
            expect(showModal).toHaveBeenCalledTimes(2);
            expect(showModal.mock.calls[1][0]).toBe(component.$refs.modal);
            expect(component.mode).toBe('create');
            expect(component.itemId).toBeNull();
        });

        it('puts only the checked values in the form, as the controls hold them', async () => {
            await open();

            await goOn(['name', 'qty', 'fragile']);
            await fadeOut();
            await settle();

            const [name, state, qty, fragile] = ['name', 'state', 'qty', 'fragile'].map((id) =>
                component.fields.find((one) => one.id === id),
            );

            expect(component.values.name).toBe('Hammer');
            expect(component.values.qty).toBe(toInput(qty, '1.50'));
            expect(component.values.fragile).toBe(toInput(fragile, false));
            // The row that stayed unchecked is not in the form.
            expect(component.values.state).toBe(toInput(state, null));
            expect(component.values.state).not.toBe('aaaaaaaa');
            expect(component.values.name).toBe(toInput(name, 'Hammer'));
        });

        it('opens the form empty when nothing was checked', async () => {
            await open();

            await goOn([]);
            await fadeOut();
            await settle();

            expect(component.mode).toBe('create');
            expect(component.values.name).toBe('');
            expect(component.values.state).toBe(toInput(component.fields[3], null));
        });

        it('keeps the original photos, not the redrawn ones, for the first photo field', async () => {
            await open();
            const front = photo('front.jpg');
            const side = photo('side.png', 'image/png');

            await goOn(['name'], front, side);
            await fadeOut();
            await settle();

            expect(component.staged.map((one) => [one.field, one.file])).toEqual([
                [PHOTO_FIELD_ID, front],
                [PHOTO_FIELD_ID, side],
            ]);
            expect(notify).not.toHaveBeenCalled();
        });

        it('keeps the redrawn photo in place of an original the photo field would refuse (a HEIC photo)', async () => {
            await open();
            const heic = photo('IMG_1.HEIC', 'image/heic');
            const front = photo('front.jpg');

            await goOn([], heic, front);
            await fadeOut();
            await settle();

            expect(component.staged).toHaveLength(2);
            expect(component.staged[0].file.name).toBe(jpegName('IMG_1.HEIC'));
            expect(component.staged[0].file.type).toBe('image/jpeg');
            expect(component.staged[1].file).toBe(front);
            expect(component.uploads).toEqual([]);
        });

        it('says so when some photos do not fit in the field', async () => {
            await open({ fields: [...FIELDS, field('photos', 'photo', { max_files: 2 }, { name: 'Photos' })] });
            component.choosePhotos(picked(photo('1.jpg'), photo('2.jpg'), photo('3.jpg')));
            http.post.mockResolvedValue(analysis('pending'));
            answers = [completed(PROPOSALS)];
            await component.analysePhotos();
            await nextReading();

            component.continuePhotoEntry();
            await fadeOut();
            await settle();

            expect(component.staged.map((one) => one.file.name)).toEqual(['1.jpg', '2.jpg']);
            expect(notify).toHaveBeenCalledWith(
                'warning',
                '1 photo did not fit in the field Photos (2 at most) and was left out.',
            );
        });

        it('uses the first photo field when there are several', async () => {
            await open({
                fields: [...FIELDS, field('second', 'photo', {}, { name: 'Second' }), PHOTOS],
            });

            await goOn([], photo('a.jpg'));
            await fadeOut();
            await settle();

            expect(component.staged.map((one) => one.field)).toEqual(['second']);
        });

        it('keeps no photo when the inventory has no photo field', async () => {
            await open({ fields: FIELDS });

            await goOn(['name'], photo('a.jpg'));
            await fadeOut();
            await settle();

            expect(component.mode).toBe('create');
            expect(component.values.name).toBe('Hammer');
            expect(component.staged).toEqual([]);
            expect(notify).not.toHaveBeenCalled();
        });

        it('opens no form when the dialog is closed by hand instead (Escape, the cross): nothing was accepted', async () => {
            await open();
            await review();
            component.toggleProposal(component.photoRows[0], checkbox(true));

            dismiss(component.$refs.photoModal);
            await fadeOut();
            await settle();

            expect(showModal).toHaveBeenCalledTimes(1);
            expect(component.values).toEqual({});
        });

        it('does nothing before there is a review to continue from', async () => {
            await open();
            component.choosePhotos(picked(photo()));

            component.continuePhotoEntry();

            expect(hideModal).not.toHaveBeenCalled();
        });

        it('opens the dialog again, clean, after it was used', async () => {
            await open();
            await goOn(['name'], photo('a.jpg'));
            await fadeOut();
            await settle();

            component.openPhotoEntry();

            expect(component.photoStep).toBe('choose');
            expect(component.photoPhotos).toEqual([]);
            expect(component.photoRows).toEqual([]);
            expect(component.photoChecked).toEqual({});
        });
    });
});
