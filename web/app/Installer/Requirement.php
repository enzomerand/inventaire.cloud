<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * The outcome of one requirement check.
 */
final readonly class Requirement
{
    /**
     * @param  string  $id  Stable identifier, e.g. "php" or "ext-mbstring".
     * @param  string  $label  Translated description of what is required.
     * @param  bool  $ok  Whether the requirement is met.
     * @param  string|null  $detail  Translated explanation (found version, missing item, how to fix).
     */
    public function __construct(
        public string $id,
        public string $label,
        public bool $ok,
        public ?string $detail = null,
    ) {}

    /**
     * @return array{id: string, label: string, ok: bool, detail: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'ok' => $this->ok, 'detail' => $this->detail];
    }
}
