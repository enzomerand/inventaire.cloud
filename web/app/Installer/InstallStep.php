<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * The configuration steps of the install wizard, in the order they are shown.
 *
 * The requirements check and the final "install" action are not steps: they
 * store nothing, so they are not tracked in the progress file.
 */
enum InstallStep: string
{
    case Database = 'database';
    case Application = 'application';
    case Mail = 'mail';
    case Storage = 'storage';
    case Documenso = 'documenso';

    /**
     * Whether the instance can be installed without this step (it may be
     * skipped).
     */
    public function isOptional(): bool
    {
        return $this === self::Documenso;
    }

    /**
     * Steps that must be completed before the installation can be finalized.
     *
     * @return list<self>
     */
    public static function required(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $step): bool => ! $step->isOptional()));
    }
}
