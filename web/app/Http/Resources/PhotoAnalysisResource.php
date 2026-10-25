<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PhotoAnalysis;
use App\PhotoEntry\PhotoAnalysisError;
use App\PhotoEntry\PhotoAnalysisStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A photo analysis as the API gives it: where it is up to and, once it ends, what Claude proposed or why it failed.
 *
 * ```
 * {"id", "status", "error", "proposals", "created_at", "completed_at"}
 * ```
 *
 * `status` is `pending`, `running`, `completed` or `failed`. `error` is the code of the failure of a failed analysis (`refused`, `truncated`, `invalid_answer`,
 * `unavailable`, `misconfigured`, `failed`, `stale` or `disabled`, see {@see PhotoAnalysisError}; `failed` for a failure with no known
 * code), and null otherwise: the words, and whether trying again can help, are the front's. `proposals` is a list of `{field, value, confidence, evidence}` (the ULID of the field, the value in the
 * canonical form the field stores, so that the form of the field takes it like any stored value; `low`, `medium` or `high`; where it was
 * read, one short line), empty until the analysis is `completed` and when it found nothing. The ids are the ULIDs; nothing numeric is
 * given out.
 *
 * @property PhotoAnalysis $resource
 */
final class PhotoAnalysisResource extends JsonResource
{
    /**
     * @param  PhotoAnalysis  $resource  The analysis.
     * @param  list<string>|null  $fieldIds  The ULIDs of the fields the inventory has now: a proposal for a field that was deleted since is left out. None given: every proposal is kept.
     */
    public function __construct(PhotoAnalysis $resource, private readonly ?array $fieldIds = null)
    {
        parent::__construct($resource);
    }

    /**
     * The analysis as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $analysis = $this->resource;

        return [
            'id' => $analysis->ulid,
            'status' => $analysis->status->value,
            // A code a later version wrote is told as the general failure.
            'error' => $analysis->status === PhotoAnalysisStatus::Failed ? ($analysis->failure() ?? PhotoAnalysisError::Failed)->value : null,
            'proposals' => $analysis->status === PhotoAnalysisStatus::Completed ? $this->proposals($analysis) : [],
            'created_at' => $analysis->created_at?->toIso8601String(),
            'completed_at' => $analysis->completed_at?->toIso8601String(),
        ];
    }

    /**
     * The proposals the job stored, for the fields that are still there. Each is rebuilt key by key so that the answer has the same
     * shape on every engine: MySQL hands a JSON object back with its keys in its own order.
     *
     * @return list<array{field: string, value: mixed, confidence: string, evidence: string}>
     */
    private function proposals(PhotoAnalysis $analysis): array
    {
        $kept = array_filter(
            $analysis->proposals ?? [],
            fn (array $proposal): bool => $this->fieldIds === null || in_array($proposal['field'], $this->fieldIds, true),
        );

        return array_values(array_map(fn (array $proposal): array => [
            'field' => $proposal['field'],
            'value' => $proposal['value'],
            'confidence' => $proposal['confidence'],
            'evidence' => $proposal['evidence'],
        ], $kept));
    }
}
