/**
 * Builders for the tests of the official versions: a version and its signers as `InventoryVersionResource` describes them.
 */

export const INVENTORY_URL = '/api/v1/inventories/tools';
export const VERSIONS_URL = `${INVENTORY_URL}/versions`;

/** A SHA-256 (64 hexadecimal characters) made of one character. */
export const hash = (character = 'a') => character.repeat(64);

/** A person asked to sign. */
export function signer(name, extra = {}) {
    return {
        name,
        email: `${name.toLowerCase().replace(/\s+/g, '.')}@example.test`,
        status: 'pending',
        signed_at: null,
        rejection_reason: null,
        ...extra,
    };
}

/** A request for signatures. */
export function signature(status = 'awaiting', extra = {}) {
    return {
        status,
        error: null,
        requested_at: '2026-10-15T10:01:00Z',
        completed_at: null,
        signers: [signer('Ada Lovelace'), signer('Grace Hopper')],
        signed_pdf: null,
        ...extra,
    };
}

/** An official version: completed, with its PDF, and nobody asked to sign, unless the test says otherwise. */
export function version(id, extra = {}) {
    const number = extra.number ?? 3;

    return {
        id,
        number,
        label: 'Year-end 2026',
        status: 'completed',
        error: null,
        frozen_at: '2026-10-15T10:00:00Z',
        created_at: '2026-10-15T10:00:00Z',
        author: { name: 'Ada Lovelace' },
        items_count: 12,
        fields_count: 4,
        snapshot: { sha256: hash('a'), size: 4096, download_url: `${VERSIONS_URL}/${id}/snapshot` },
        pdf: { sha256: hash('b'), size: 2048, pages: 3, download_url: `${VERSIONS_URL}/${id}/pdf` },
        signature: null,
        abilities: { delete: true, cancel_signature: false, retry_signature: false },
        ...extra,
    };
}

/** A version whose PDF is not written yet. */
export function onItsWay(id, status = 'pending', extra = {}) {
    return version(id, { status, pdf: null, ...extra });
}
