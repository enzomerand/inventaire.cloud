/**
 * What the photo entry shows of an analysis: the rows of the review and the words of the failures.
 */
import { describe, expect, it, vi } from 'vitest';

import { field } from '../support/inventory.js';
import {
    confidenceOf,
    failureOf,
    isEnded,
    requestFailure,
    reviewRows,
} from '../../../resources/js/photo-entry/proposals.js';

vi.mock('../../../resources/js/lib/i18n.js', async () => import('../support/messages.js'));

const FIELDS = [
    field('01hzx0000000000000000000na', 'text', {}, { name: 'Name' }),
    field('01hzx0000000000000000000ph', 'photo', {}, { name: 'Photo' }),
];

describe('the state of an analysis', () => {
    it.each([
        ['pending', false],
        ['running', false],
        ['completed', true],
        ['failed', true],
    ])('%s: ended is %s', (status, ended) => {
        expect(isEnded({ id: 'a1', status, error: null, proposals: [] })).toBe(ended);
    });
});

describe('the confidence of a proposal', () => {
    it.each([
        ['low', 'Low confidence', 'badge bg-orange-lt'],
        ['medium', 'Medium confidence', 'badge bg-yellow-lt'],
        ['high', 'High confidence', 'badge bg-green-lt'],
    ])('%s is a badge that says so', (level, text, badge) => {
        expect(confidenceOf(level)).toEqual({ level, text, class: badge });
    });

    it('is the lowest for a level the interface does not know', () => {
        expect(confidenceOf('certain').level).toBe('low');
        expect(confidenceOf(undefined).level).toBe('low');
    });
});

describe('the rows of the review', () => {
    it('is nothing for an answer that is not a list', () => {
        expect(reviewRows(undefined, FIELDS)).toEqual([]);
        expect(reviewRows(null, FIELDS)).toEqual([]);
    });

    it('gives each row the ids its checkbox and its name are tied by', () => {
        const [row] = reviewRows(
            [{ field: '01HZX0000000000000000000NA', value: 'Hammer', confidence: 'high', evidence: 'label' }],
            FIELDS,
        );

        expect(row).toMatchObject({
            key: '01hzx0000000000000000000na',
            checkId: 'photo-check-01hzx0000000000000000000na',
            nameId: 'photo-name-01hzx0000000000000000000na',
            valueId: 'photo-value-01hzx0000000000000000000na',
            levelId: 'photo-level-01hzx0000000000000000000na',
            evidenceId: 'photo-evidence-01hzx0000000000000000000na',
            labelledBy: 'photo-name-01hzx0000000000000000000na photo-value-01hzx0000000000000000000na',
            describedBy: 'photo-level-01hzx0000000000000000000na photo-evidence-01hzx0000000000000000000na',
            name: 'Name',
            value: 'Hammer',
            text: 'Hammer',
            evidence: 'label',
        });
    });

    it('keeps the evidence as text, whatever it holds, and an evidence that is no text as nothing', () => {
        const [first] = reviewRows(
            [{ field: FIELDS[0].id, value: 'x', confidence: 'low', evidence: '<img src=x onerror=alert(1)>' }],
            FIELDS,
        );
        const [second] = reviewRows([{ field: FIELDS[0].id, value: 'x', confidence: 'low', evidence: 42 }], FIELDS);

        expect(first.evidence).toBe('<img src=x onerror=alert(1)>');
        expect(second.evidence).toBe('');
    });
});

describe('the words of a failure', () => {
    it('says the analysis failed when it gives no reason', () => {
        expect(failureOf({ id: 'a1', status: 'failed', error: null, proposals: [] })).toEqual({
            text: 'The analysis failed. Please try again.',
            retry: true,
            resume: false,
        });
    });

    it.each([
        // Worth sending the photos again: a shorter or a later try may end differently.
        ['truncated', 'The answer was too long to be read. Try again, with fewer photos if you can.', true],
        ['invalid_answer', 'The answer of the analysis could not be understood. Please try again.', true],
        ['unavailable', 'The analysis service is unavailable at the moment. Please try again in a moment.', true],
        ['failed', 'The analysis failed. Please try again.', true],
        ['stale', 'The analysis took too long and was given up. Please try again.', true],
        // Not worth it: the same photos are declined again, only the administrator can mend it, or the feature is off.
        ['refused', 'The photos were refused by the analysis service. Try other photos.', false],
        [
            'misconfigured',
            'The analysis service is not set up correctly. Ask the administrator of this instance.',
            false,
        ],
        [
            'disabled',
            'Adding items from photos was turned off before the analysis could start. The photos were deleted without being sent.',
            false,
        ],
    ])(
        'tells the code %s of a failed analysis in words, and offers a retry only when it can help',
        (error, text, retry) => {
            expect(failureOf({ id: 'a1', status: 'failed', error, proposals: [] })).toEqual({
                text,
                retry,
                resume: false,
            });
        },
    );

    it('tells a code it does not know as the general failure, with a retry', () => {
        const general = { text: 'The analysis failed. Please try again.', retry: true, resume: false };

        for (const error of ['from_a_later_version', 'constructor', '__proto__', 'toString', '']) {
            expect(failureOf({ id: 'a1', status: 'failed', error, proposals: [] })).toEqual(general);
        }
    });

    it('says what the API said of a request that was refused, and the general words for a refusal it does not explain', () => {
        expect(requestFailure({ status: 422, message: 'Invalid.', errors: {} }).text).toBe('Invalid.');
        expect(requestFailure({ status: 409, code: 'something_else', message: 'Nope.' })).toEqual({
            text: 'Nope.',
            retry: true,
            resume: false,
        });
    });

    it('says the instance is out of analyses, without offering to try again, when the API says so (429)', () => {
        expect(
            requestFailure({ status: 429, code: 'photo_analyses_instance_limit', message: 'Slow', retryAfter: 600 }),
        ).toEqual({
            text: 'The instance reached its daily number of photo analyses; try again tomorrow.',
            retry: false,
            resume: false,
        });
    });

    it('tells how long to wait when the API says (429)', () => {
        expect(requestFailure({ status: 429, message: 'Slow', retryAfter: 600 }).text).toBe(
            'Too many attempts. Try again in 10 minutes.',
        );
        expect(requestFailure({ status: 429, message: 'Slow' }).text).toBe(
            'Too many attempts. Please wait a moment before trying again.',
        );
    });
});
