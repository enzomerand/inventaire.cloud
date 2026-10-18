<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\Versions\SignatureStatus;
use App\Inventories\Versions\SignerStatus;
use App\Inventories\Versions\VersionError;
use App\Inventories\Versions\VersionStatus;
use App\Models\InventoryVersion;
use App\Policies\InventoryVersionPolicy;
use App\Workspaces\WorkspaceRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An official version as the API gives it: what was frozen, where its PDF is up to, the files to download and, when signatures were
 * asked for, where each signer stands.
 *
 * ```
 * {"id", "number", "label", "status", "error", "frozen_at", "created_at", "author": {"name"}, "items_count", "fields_count",
 *  "snapshot": {"sha256", "size", "download_url": null | string},
 *  "pdf": null | {"sha256", "size", "pages", "download_url"},
 *  "signature": null | {"status", "error", "requested_at", "completed_at",
 *                       "signers": [{"name", "email", "status", "signed_at", "rejection_reason"}],
 *                       "signed_pdf": null | {"sha256", "size", "download_url"}},
 *  "abilities": {"delete", "cancel_signature", "retry_signature"}}
 * ```
 *
 * `status` is `pending`, `running`, `completed` or `failed`; `error` is the message to show for a failed version, in the language of the
 * request, and null otherwise. `snapshot` describes the canonical JSON that was frozen (its SHA-256 is what the PDF and the signatures
 * vouch for); its `download_url` is null until the version is completed, as the download answers `409` before that. `pdf` is null
 * until the PDF is written. `signature` is null for a version that no signature was asked for; its
 * `status` is a {@see SignatureStatus}, its `error` the message for a request that failed or was lost, and `signed_pdf` is null until
 * the signed document is stored. The addresses (`download_url`) are paths of the application, to be followed with the session or the
 * token of the reader; the downloads answer `409` until the version is completed.
 *
 * `abilities` is what the reader may do with this version right now: deleting is for owners; cancelling a request is for editors and
 * owners while the signers are awaited, sending it again when it failed on a completed version. The ids are the ULIDs; nothing numeric is
 * given out.
 *
 * @property InventoryVersion $resource
 */
final class InventoryVersionResource extends JsonResource
{
    /**
     * Remembers the version, the ULID of its inventory (the addresses of its files are made of it) and the role of the reader in the
     * workspace of the inventory (what they may do with it).
     */
    public function __construct($resource, private readonly string $inventoryUlid, private readonly WorkspaceRole $role)
    {
        parent::__construct($resource);
    }

    /**
     * The version as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->resource;
        $abilities = InventoryVersionPolicy::abilities($this->role);
        $signature = $version->signature_status;
        $completed = $version->status === VersionStatus::Completed;

        return [
            'id' => $version->ulid,
            'number' => $version->number,
            'label' => $version->label,
            'status' => $version->status->value,
            'error' => $version->status === VersionStatus::Failed ? ($version->error ?? VersionError::GenerationFailed)->message() : null,
            'frozen_at' => $version->frozen_at->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
            'author' => ['name' => $version->author_name],
            'items_count' => $version->items_count,
            'fields_count' => $version->fields_count,
            'snapshot' => [
                'sha256' => $version->snapshot_sha256,
                'size' => $version->snapshot_size,
                // Like the download itself, which answers 409 until the version is completed: no address to follow before.
                'download_url' => $completed ? $this->address('snapshot') : null,
            ],
            'pdf' => $completed && $version->pdf_sha256 !== null ? [
                'sha256' => $version->pdf_sha256,
                'size' => (int) $version->pdf_size,
                'pages' => (int) $version->pdf_pages,
                'download_url' => $this->address('pdf'),
            ] : null,
            'signature' => $signature === null ? null : [
                'status' => $signature->value,
                'error' => $version->signature_error?->message(),
                'requested_at' => $version->signature_requested_at?->toIso8601String(),
                'completed_at' => $version->signature_completed_at?->toIso8601String(),
                'signers' => $this->signers(),
                'signed_pdf' => $version->signed_pdf_sha256 !== null ? [
                    'sha256' => $version->signed_pdf_sha256,
                    'size' => (int) $version->signed_pdf_size,
                    'download_url' => $this->address('signed-pdf'),
                ] : null,
            ],
            'abilities' => [
                'delete' => $abilities['delete'],
                'cancel_signature' => $abilities['cancel_signature'] && $signature === SignatureStatus::Awaiting,
                'retry_signature' => $abilities['retry_signature'] && $signature === SignatureStatus::Failed && $completed,
            ],
        ];
    }

    /**
     * The people asked to sign, as they are kept with the version but for what only the application needs (the id Documenso gave them,
     * the place of their box on the page).
     *
     * @return list<array{name: mixed, email: mixed, status: string, signed_at: string|null, rejection_reason: mixed}>
     */
    private function signers(): array
    {
        $signers = [];

        foreach ($this->resource->signers ?? [] as $signer) {
            $signers[] = [
                'name' => $signer['name'] ?? '',
                'email' => $signer['email'] ?? '',
                'status' => (SignerStatus::tryFrom((string) ($signer['status'] ?? '')) ?? SignerStatus::Pending)->value,
                // Kept as an ISO 8601 text in UTC, which is how `SignerStates` writes it.
                'signed_at' => $signer['signed_at'] ?? null,
                'rejection_reason' => $signer['rejection_reason'] ?? null,
            ];
        }

        return $signers;
    }

    /**
     * The path of a file of the version, relative to the host (`snapshot`, `pdf` or `signed-pdf`).
     */
    private function address(string $file): string
    {
        return route('api.v1.inventories.versions.'.$file, ['inventory' => $this->inventoryUlid, 'version' => $this->resource->ulid], false);
    }
}
