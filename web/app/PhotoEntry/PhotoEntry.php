<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Models\Workspace;
use App\Settings\InstanceSettings;

/**
 * Whether the photo entry may be used: an editor sends photos of an object, Claude proposes the values of its fields, and the editor
 * reviews them before the item is created.
 *
 * Three conditions, all needed, because the photos leave for a third party (Anthropic) and every analysis is paid for by the instance:
 * the administrator turned the feature on ({@see InstanceSettings::photoEntryEnabled()}), the instance has an API key
 * (`ANTHROPIC_API_KEY`, `config('services.anthropic.key')`), and the owner of the workspace consented (`workspaces.photo_entry_enabled`).
 */
final class PhotoEntry
{
    public function __construct(private readonly InstanceSettings $settings) {}

    /**
     * Whether the instance offers the photo entry at all: the administrator turned it on and an API key is configured.
     */
    public function availableOnInstance(): bool
    {
        return $this->hasKey() && $this->settings->photoEntryEnabled();
    }

    /**
     * Whether the editors of this workspace may use it: the instance offers it and the owner of the workspace turned it on.
     */
    public function enabledFor(Workspace $workspace): bool
    {
        return $workspace->photo_entry_enabled && $this->availableOnInstance();
    }

    /**
     * Whether an API key is configured (its value is never shown: the administration only says whether there is one).
     */
    public function hasKey(): bool
    {
        $key = config('services.anthropic.key');

        return is_string($key) && trim($key) !== '';
    }
}
