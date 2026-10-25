<?php

declare(strict_types=1);

namespace App\PhotoEntry;

/**
 * What an analyzer read in the photos, as it gave it: texts that are not trusted yet ({@see Proposals} converts and checks them), and what
 * the request cost.
 */
final readonly class PhotoReading
{
    /**
     * @param  list<array{field: string, values: list<string>, confidence: string, evidence: string}>  $proposals  For each field the analyzer filled: its ULID, the values it read as texts, how sure it is (`low`, `medium`, `high`) and where it read them.
     * @param  string|null  $model  The model that answered (it may not be the one asked for: a refusal can fall back to another).
     * @param  int|null  $inputTokens  Tokens the request was billed for.
     * @param  int|null  $outputTokens  Tokens the answer was billed for.
     */
    public function __construct(
        public array $proposals,
        public ?string $model = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}
}
