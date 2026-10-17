/**
 * What the page of the official versions knows about versions apart from the page: the statuses, how a version and its signers are
 * told in a card, what the request that freezes an inventory carries, how a refusal of the API is read, and which changes are said aloud.
 */
import { describe, expect, it, vi } from 'vitest';

import { formatSize } from '../../../resources/js/fields/files.js';
import { momentText } from '../../../resources/js/grid/rows.js';
import { HttpError } from '../../../resources/js/lib/http.js';
import {
    POLL_INTERVAL_MS,
    actionFailure,
    anyActive,
    appendPage,
    blankSigner,
    changeNotices,
    creationFailure,
    isActive,
    isGenerating,
    isSigning,
    requestBody,
    rowOf,
    shortHash,
    signerError,
    signerRowOf,
    versionTitle,
    withVersion,
} from '../../../resources/js/versions/versions.js';
import { hash, onItsWay, signature, signer, version } from '../support/versions.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

/** An HTTP failure, as the helper throws it. */
function failure(
    status,
    { code = null, errors = {}, body = null, message = 'Server wording', reported = false, retryAfter = null } = {},
) {
    const error = new HttpError(status, message, { errors, body, code, retryAfter });

    error.reported = reported;

    return error;
}

describe('the pace of the readings', () => {
    it('asks again every two seconds', () => {
        expect(POLL_INTERVAL_MS).toBe(2000);
    });
});

describe('what is still on its way', () => {
    it('knows a version whose PDF is not written', () => {
        expect(isGenerating(onItsWay('v', 'pending'))).toBe(true);
        expect(isGenerating(onItsWay('v', 'running'))).toBe(true);
        expect(isGenerating(version('v'))).toBe(false);
        expect(isGenerating(version('v', { status: 'failed' }))).toBe(false);
    });

    it('knows a request for signatures that is not with the signers yet', () => {
        expect(isSigning(version('v', { signature: signature('requested') }))).toBe(true);
        expect(isSigning(version('v', { signature: signature('sending') }))).toBe(true);

        for (const status of ['awaiting', 'completed', 'rejected', 'cancelled', 'failed']) {
            expect(isSigning(version('v', { signature: signature(status) })), status).toBe(false);
        }

        expect(isSigning(version('v'))).toBe(false);
    });

    it('is active while either is going on', () => {
        expect(isActive(onItsWay('a'))).toBe(true);
        expect(isActive(version('b', { signature: signature('sending') }))).toBe(true);
        expect(isActive(version('c', { signature: signature('awaiting') }))).toBe(false);
        expect(isActive(version('d'))).toBe(false);
    });

    it('keeps asking while any version of the list is active, and stops when none is', () => {
        expect(anyActive([version('a'), onItsWay('b', 'running')])).toBe(true);
        expect(anyActive([version('a'), version('b', { signature: signature('awaiting') })])).toBe(false);
        expect(anyActive([])).toBe(false);
    });
});

describe('the short SHA-256', () => {
    it('keeps the first twelve characters', () => {
        expect(shortHash(hash('a'))).toBe('aaaaaaaaaaaa…');
    });

    it('leaves a short value alone, and says nothing for what is not a hash', () => {
        expect(shortHash('abc')).toBe('abc');
        expect(shortHash('')).toBe('');
        expect(shortHash(null)).toBe('');
        expect(shortHash(undefined)).toBe('');
    });
});

describe('a version told for its card', () => {
    it('names it, labels it, and says when, by whom and what it holds', () => {
        const row = rowOf(version('v1', { number: 7 }));

        expect(row.id).toBe('v1');
        expect(row.title).toBe('Version no. 7');
        expect(versionTitle(7)).toBe('Version no. 7');
        expect(row.titleId).toBe('version-v1-title');
        expect(row.label).toBe('Year-end 2026');
        expect(row.details).toBe(
            [
                `Frozen on ${momentText('2026-10-15T10:00:00Z')}`,
                'by Ada Lovelace',
                '12 items',
                '4 fields',
                formatSize(2048),
            ].join(' · '),
        );
    });

    it('leaves out what is not known', () => {
        const row = rowOf(
            version('v1', { label: null, author: { name: '' }, items_count: 1, fields_count: 1, pdf: null }),
        );

        expect(row.label).toBe('');
        expect(row.details).toBe([`Frozen on ${momentText('2026-10-15T10:00:00Z')}`, '1 item', '1 field'].join(' · '));
    });

    it('gives a completed version its PDF, its data and a badge that says it is ready', () => {
        const row = rowOf(version('v1'));

        expect(row.statusLabel).toBe('PDF ready');
        expect(row.badgeClass).toBe('bg-green-lt');
        expect(row.pdfUrl).toBe('/api/v1/inventories/tools/versions/v1/pdf');
        expect(row.dataUrl).toBe('/api/v1/inventories/tools/versions/v1/snapshot');
        expect(row.signedUrl).toBeNull();
        expect(row.error).toBe('');
    });

    it('offers no data when the record gives no address for them: a completed version with none, whatever else it holds', () => {
        const row = rowOf(version('v1', { snapshot: { sha256: 'a'.repeat(64), size: 10, download_url: null } }));

        expect(row.dataUrl).toBeNull();
        // The rest of the card is as it was.
        expect(row.pdfUrl).toBe('/api/v1/inventories/tools/versions/v1/pdf');
        expect(row.sha256).toBe('a'.repeat(64));
    });

    it('takes a record that has no snapshot at all for one with no data to download', () => {
        const record = version('v1');

        delete record.snapshot;

        const row = rowOf(record);

        expect(row.dataUrl).toBeNull();
        expect(row.sha256).toBe('');
    });

    it('gets from the API no address for the data of a version that is not completed, and shows none', () => {
        for (const status of ['pending', 'running', 'failed']) {
            const record = version('v1', { status, pdf: null });

            expect(record.snapshot.download_url).toBeNull();
            expect(rowOf(record).dataUrl).toBeNull();
        }
    });

    it.each([
        ['pending', 'Waiting', 'bg-secondary-lt'],
        ['running', 'In progress', 'bg-blue-lt'],
    ])('offers no file while a version is %s', (status, label, badge) => {
        const row = rowOf(onItsWay('v1', status));

        expect(row.statusLabel).toBe(label);
        expect(row.badgeClass).toBe(badge);
        expect(row.pdfUrl).toBeNull();
        expect(row.dataUrl).toBeNull();
    });

    it('says why a version failed, and offers no file', () => {
        const row = rowOf(version('v1', { status: 'failed', pdf: null, error: 'The PDF could not be written.' }));

        expect(row.statusLabel).toBe('Failed');
        expect(row.badgeClass).toBe('bg-red-lt');
        expect(row.error).toBe('The PDF could not be written.');
        expect(row.pdfUrl).toBeNull();
        expect(row.dataUrl).toBeNull();
    });

    it('does not show an error on a version that did not fail', () => {
        expect(rowOf(version('v1', { error: 'stale' })).error).toBe('');
    });

    it('carries the whole SHA-256 for the title and the copy button, and a short one to read', () => {
        const row = rowOf(version('v1'));

        expect(row.sha256).toBe(hash('a'));
        expect(row.shortHash).toBe('aaaaaaaaaaaa…');
        expect(row.hashLabel).toBe('Copy the SHA-256 of version no. 3');
    });

    it('names the version in the label of each action, so that the buttons of two cards are told apart', () => {
        const row = rowOf(version('v1', { number: 9 }));

        expect(row.pdfLabel).toBe('Download the PDF of version no. 9');
        expect(row.dataLabel).toBe('Download the data (JSON) of version no. 9');
        expect(row.signedLabel).toBe('Download the signed PDF of version no. 9');
        expect(row.cancelLabel).toBe('Cancel the signature request of version no. 9');
        expect(row.retryLabel).toBe('Send the signature request of version no. 9 again');
        expect(row.deleteLabel).toBe('Delete version no. 9');
    });

    it('offers what the abilities of the version allow, and nothing without them', () => {
        const owner = rowOf(
            version('v1', { abilities: { delete: true, cancel_signature: true, retry_signature: false } }),
        );
        const viewer = rowOf(
            version('v2', { abilities: { delete: false, cancel_signature: false, retry_signature: false } }),
        );
        const editor = rowOf(
            version('v3', { abilities: { delete: false, cancel_signature: false, retry_signature: true } }),
        );

        expect([owner.canDelete, owner.canCancel, owner.canRetry]).toEqual([true, true, false]);
        expect([viewer.canDelete, viewer.canCancel, viewer.canRetry]).toEqual([false, false, false]);
        expect([editor.canDelete, editor.canCancel, editor.canRetry]).toEqual([false, false, true]);
    });

    it('offers nothing when the version brings no abilities', () => {
        const row = rowOf({ ...version('v1'), abilities: undefined });

        expect([row.canDelete, row.canCancel, row.canRetry]).toEqual([false, false, false]);
    });

    it('marks a version an action is on its way for', () => {
        expect(rowOf(version('v1')).working).toBe(false);
        expect(rowOf(version('v1'), { working: true }).working).toBe(true);
    });

    it('treats a status it does not know as waiting rather than failing to draw the card', () => {
        const row = rowOf(version('v1', { status: 'something-new' }));

        expect(row.statusLabel).toBe('Waiting');
        expect(row.badgeClass).toBe('bg-secondary-lt');
    });
});

describe('the request for signatures of a version, told for its card', () => {
    it('is absent from a version nobody was asked to sign', () => {
        const row = rowOf(version('v1'));

        expect(row.hasSignature).toBe(false);
        expect(row.signers).toEqual([]);
        expect(row.signatureLabel).toBe('');
    });

    it('says where it stands, how many have signed and when it was asked', () => {
        const row = rowOf(
            version('v1', {
                signature: signature('awaiting', {
                    signers: [
                        signer('Ada Lovelace', { status: 'signed', signed_at: '2026-10-16T09:00:00Z' }),
                        signer('Grace Hopper'),
                    ],
                }),
            }),
        );

        expect(row.hasSignature).toBe(true);
        expect(row.signatureLabel).toBe('Awaiting signatures');
        expect(row.signatureBadgeClass).toBe('bg-yellow-lt');
        expect(row.signatureSummary).toBe('Signatures received: 1 of 2');
        expect(row.signatureDates).toBe(`Requested on ${momentText('2026-10-15T10:01:00Z')}`);
        expect(row.signatureError).toBe('');
        expect(row.signers).toHaveLength(2);
    });

    it('says when it was completed, and offers the signed PDF', () => {
        const row = rowOf(
            version('v1', {
                signature: signature('completed', {
                    completed_at: '2026-10-17T08:00:00Z',
                    signers: [signer('Ada Lovelace', { status: 'signed', signed_at: '2026-10-16T09:00:00Z' })],
                    signed_pdf: {
                        sha256: hash('c'),
                        size: 3000,
                        download_url: '/api/v1/inventories/tools/versions/v1/signed-pdf',
                    },
                }),
            }),
        );

        expect(row.signatureLabel).toBe('Signed by everyone');
        expect(row.signatureBadgeClass).toBe('bg-green-lt');
        expect(row.signatureDates).toBe(
            `Requested on ${momentText('2026-10-15T10:01:00Z')} · Completed on ${momentText('2026-10-17T08:00:00Z')}`,
        );
        expect(row.signedUrl).toBe('/api/v1/inventories/tools/versions/v1/signed-pdf');
    });

    it.each([
        ['requested', 'Waiting to be sent', 'bg-blue-lt'],
        ['sending', 'Being sent', 'bg-blue-lt'],
        ['rejected', 'Refused by a signer', 'bg-orange-lt'],
        ['cancelled', 'Cancelled', 'bg-secondary-lt'],
        ['failed', 'Failed', 'bg-red-lt'],
    ])('words the status %s and gives it its color', (status, label, badge) => {
        const row = rowOf(version('v1', { signature: signature(status) }));

        expect(row.signatureLabel).toBe(label);
        expect(row.signatureBadgeClass).toBe(badge);
    });

    it('does not count the signers of a request that has not reached them', () => {
        expect(rowOf(version('v1', { signature: signature('requested') })).signatureSummary).toBe('');
        expect(rowOf(version('v1', { signature: signature('cancelled') })).signatureSummary).toBe('');
    });

    it('says why a request failed, and only then', () => {
        const failed = rowOf(
            version('v1', { signature: signature('failed', { error: 'The signature service refused the request.' }) }),
        );
        const other = rowOf(version('v2', { signature: signature('awaiting', { error: 'stale' }) }));

        expect(failed.signatureError).toBe('The signature service refused the request.');
        expect(other.signatureError).toBe('');
    });
});

describe('a signer told for a card', () => {
    it('says who they are and that they have not answered', () => {
        const row = signerRowOf(signer('Ada Lovelace'), 0);

        expect(row).toMatchObject({
            name: 'Ada Lovelace',
            email: 'ada.lovelace@example.test',
            statusLabel: 'Pending',
            badgeClass: 'bg-secondary-lt',
            detail: '',
        });
    });

    it('says when they signed', () => {
        const row = signerRowOf(signer('Ada Lovelace', { status: 'signed', signed_at: '2026-10-16T09:00:00Z' }), 0);

        expect(row.statusLabel).toBe('Signed');
        expect(row.badgeClass).toBe('bg-green-lt');
        expect(row.detail).toBe(`Signed on ${momentText('2026-10-16T09:00:00Z')}`);
    });

    it('says why they refused', () => {
        const row = signerRowOf(signer('Ada Lovelace', { status: 'rejected', rejection_reason: 'Wrong totals' }), 0);

        expect(row.statusLabel).toBe('Refused');
        expect(row.badgeClass).toBe('bg-red-lt');
        expect(row.detail).toBe('Reason: Wrong totals');
    });

    it('says nothing more when the moment or the reason is missing', () => {
        expect(signerRowOf(signer('Ada', { status: 'signed' }), 0).detail).toBe('');
        expect(signerRowOf(signer('Ada', { status: 'rejected' }), 0).detail).toBe('');
    });

    it('gives each line a key of its own, even for two people with the same address', () => {
        const same = { ...signer('Ada'), email: 'same@example.test' };

        expect(signerRowOf(same, 0).key).not.toBe(signerRowOf(same, 1).key);
    });
});

describe('the lines of signers of the form', () => {
    it('starts a line empty, with the ids its two labels point to', () => {
        expect(blankSigner(4)).toEqual({
            key: 4,
            name: '',
            email: '',
            nameId: 'version-signer-4-name',
            emailId: 'version-signer-4-email',
        });
    });
});

describe('the request that freezes the inventory', () => {
    it('sends nothing when nothing was typed', () => {
        expect(requestBody({ label: '', sign: false, signers: [] })).toEqual({});
        expect(requestBody({ label: '   ', sign: false, signers: [] })).toEqual({});
    });

    it('sends the label without the spaces around it', () => {
        expect(requestBody({ label: '  Year-end 2026 ', sign: false, signers: [] })).toEqual({
            label: 'Year-end 2026',
        });
    });

    it('sends no signer when signatures are not asked for, whatever the lines hold', () => {
        const body = requestBody({ label: '', sign: false, signers: [{ name: 'Ada', email: 'ada@example.test' }] });

        expect(body).toEqual({});
        expect('signers' in body).toBe(false);
    });

    it('sends every line, in order, when they are asked for, so that the API names the line it refuses', () => {
        const body = requestBody({
            label: 'Handover',
            sign: true,
            signers: [
                { key: 1, name: ' Ada Lovelace ', email: ' ada@example.test ', nameId: 'x', emailId: 'y' },
                { key: 2, name: '', email: '', nameId: 'x', emailId: 'y' },
            ],
        });

        expect(body).toEqual({
            label: 'Handover',
            signers: [
                { name: 'Ada Lovelace', email: 'ada@example.test' },
                { name: '', email: '' },
            ],
        });
    });
});

describe('the message under an input of a line of signers', () => {
    it('is the one the API gave for that line and that input', () => {
        const errors = { 'signers.1.email': 'Not valid', label: 'Too long' };

        expect(signerError(errors, 1, 'email')).toBe('Not valid');
        expect(signerError(errors, 1, 'name')).toBe('');
        expect(signerError(errors, 0, 'email')).toBe('');
    });
});

describe('a refusal of the request that freezes the inventory', () => {
    it('puts the messages of a 422 under the inputs they are about', () => {
        const result = creationFailure(
            failure(422, {
                errors: {
                    label: ['The label is too long.'],
                    'signers.0.name': ['Give a name.'],
                    'signers.0.email': ['Give a valid address.', 'second message'],
                },
            }),
        );

        expect(result.fieldErrors).toEqual({
            label: 'The label is too long.',
            'signers.0.name': 'Give a name.',
            'signers.0.email': 'Give a valid address.',
        });
        expect(result.message).toBe('');
    });

    it('says a refusal about the inventory itself (too many items for a PDF) under the buttons', () => {
        const result = creationFailure(
            failure(422, { errors: { inventory: ['This inventory is too big for a PDF.'] } }),
        );

        expect(result.fieldErrors).toEqual({});
        expect(result.message).toBe('This inventory is too big for a PDF.');
    });

    it('says a refusal of the list of signers under the list', () => {
        const result = creationFailure(failure(422, { errors: { signers: ['At least one signer.'] } }));

        expect(result.fieldErrors).toEqual({ signers: 'At least one signer.' });
        expect(result.message).toBe('');
    });

    it('does not lose a 422 that names no input of the form', () => {
        const result = creationFailure(
            failure(422, { errors: { other: ['Something else.'] }, message: 'Something else.' }),
        );

        expect(result.message).toBe('Something else.');
    });

    it('words the limit of versions from its code and the limit the API gave', () => {
        const many = creationFailure(failure(409, { code: 'version_limit_reached', body: { limit: 200 } }));
        const one = creationFailure(failure(409, { code: 'version_limit_reached', body: { limit: 1 } }));

        expect(many.message).toBe('An inventory cannot hold more than 200 official versions.');
        expect(one.message).toBe('An inventory cannot hold more than 1 official version.');
        expect(many.fieldErrors).toEqual({});
    });

    it('words the full storage and the lack of a signature service from their codes', () => {
        expect(creationFailure(failure(409, { code: 'storage_limit_reached' })).message).toBe(
            'The storage of this workspace is full.',
        );
        expect(creationFailure(failure(409, { code: 'signature_unavailable' })).message).toBe(
            'Signatures cannot be asked for.',
        );
    });

    it('tells the wait of a 429 from Retry-After', () => {
        expect(creationFailure(failure(429, { retryAfter: 30 })).message).toBe(
            'Too many attempts. Try again in 30 seconds.',
        );
    });

    it('tells a 429 without a wait in general words', () => {
        expect(creationFailure(failure(429, { reported: true })).message).toBe(
            'Too many attempts. Please wait a moment before trying again.',
        );
    });

    it('does not say again what the helper already reported', () => {
        expect(creationFailure(failure(500, { message: 'The server hit an error.', reported: true })).message).toBe('');
    });

    it('says what the helper did not report', () => {
        expect(creationFailure(failure(409, { code: 'something_else', message: 'Not reported' })).message).toBe(
            'Not reported',
        );
    });
});

describe('a refusal of an action on a version', () => {
    it('lets a version that is gone leave the list, without a message of its own', () => {
        expect(actionFailure(failure(404, { reported: true }))).toEqual({ gone: true, refresh: false, message: '' });
    });

    it('reads again a request that was not waiting for its signers, and says so', () => {
        expect(actionFailure(failure(409, { code: 'signature_not_pending' }))).toEqual({
            gone: false,
            refresh: true,
            message: 'This signature request is not waiting for its signers.',
        });
    });

    it('reads again a request that did not fail, and says so', () => {
        expect(actionFailure(failure(409, { code: 'signature_not_failed' }))).toEqual({
            gone: false,
            refresh: true,
            message: 'This signature request did not fail.',
        });
    });

    it('says the instance cannot ask for signatures, without reading anything again', () => {
        expect(actionFailure(failure(409, { code: 'signature_unavailable' }))).toEqual({
            gone: false,
            refresh: false,
            message: 'Signatures cannot be asked for.',
        });
    });

    it('does not repeat what the helper reported (the service that cannot be reached, a refusal of the role)', () => {
        expect(actionFailure(failure(503, { code: 'documenso_unavailable', reported: true })).message).toBe('');
        expect(actionFailure(failure(403, { reported: true })).message).toBe('');
    });

    it('says what the helper did not report', () => {
        expect(actionFailure(failure(409, { code: 'other', message: 'Nope' }))).toEqual({
            gone: false,
            refresh: false,
            message: 'Nope',
        });
    });
});

describe('the list of versions', () => {
    it('puts a version that is new at the top, and one that is known where it stands', () => {
        const list = [version('b', { number: 2 }), version('a', { number: 1 })];
        const top = version('c', { number: 3 });
        const changed = version('a', { number: 1, label: 'Changed' });

        expect(withVersion(list, top).map((one) => one.id)).toEqual(['c', 'b', 'a']);
        expect(withVersion(list, changed).map((one) => one.id)).toEqual(['b', 'a']);
        expect(withVersion(list, changed)[1].label).toBe('Changed');
        expect(list).toHaveLength(2);
    });

    it('adds a page to the end, leaving out the versions the list already holds', () => {
        const list = [version('c'), version('b')];
        const page = [version('b'), version('a')];

        expect(appendPage(list, page).map((one) => one.id)).toEqual(['c', 'b', 'a']);
    });
});

describe('the changes worth saying aloud', () => {
    it('tells a PDF that was written, and one that failed', () => {
        const before = [onItsWay('a', 'running', { number: 4 }), onItsWay('b', 'pending', { number: 5 })];
        const after = [version('a', { number: 4 }), version('b', { number: 5, status: 'failed', pdf: null })];

        expect(changeNotices(before, after)).toEqual([
            'Version no. 4 is ready.',
            'Version no. 5 could not be completed.',
        ]);
    });

    it('tells a request that reached the signers, and one that failed', () => {
        const before = [
            version('a', { number: 4, signature: signature('sending') }),
            version('b', { number: 5, signature: signature('requested') }),
        ];
        const after = [
            version('a', { number: 4, signature: signature('awaiting') }),
            version('b', { number: 5, signature: signature('failed') }),
        ];

        expect(changeNotices(before, after)).toEqual([
            'The signers of version no. 4 were emailed.',
            'The signature request of version no. 5 failed.',
        ]);
    });

    it('says nothing of what did not change, of a version still on its way, or of one that was not listed', () => {
        const same = [onItsWay('a', 'pending'), version('b')];

        expect(changeNotices(same, [onItsWay('a', 'running'), version('b')])).toEqual([]);
        expect(changeNotices([], [version('c')])).toEqual([]);
        expect(changeNotices(same, [version('b', { signature: signature('awaiting') })])).toEqual([]);
    });
});
