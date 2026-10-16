<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * The outcome of one requirement check.
 *
 * A requirement is met (`ok`) or not; a met one can still be a `warning`: the application runs, but a part of it is degraded
 * (the explanation is in `detail`). A warning never stops the installation.
 */
final readonly class Requirement
{
    /**
     * @param  string  $id  Stable identifier, e.g. "php" or "ext-mbstring".
     * @param  string  $label  Translated description of what is required.
     * @param  bool  $ok  Whether the requirement is met.
     * @param  string|null  $detail  Translated explanation (found version, missing item, how to fix).
     * @param  bool  $warning  Whether the requirement is met (`ok`) in a degraded way that the administrator should know about.
     */
    public function __construct(
        public string $id,
        public string $label,
        public bool $ok,
        public ?string $detail = null,
        public bool $warning = false,
    ) {}

    /**
     * @return array{id: string, label: string, ok: bool, warning: bool, detail: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'ok' => $this->ok, 'warning' => $this->warning, 'detail' => $this->detail];
    }
}
