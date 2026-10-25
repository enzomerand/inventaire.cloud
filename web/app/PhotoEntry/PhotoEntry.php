<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Models\Workspace;
use App\PhotoEntry\Exceptions\PhotoEntryUnavailableException;
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
    /**
     * Gets the settings of the instance, where the administrator's switch is.
     */
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
     * Records the choice of the owner of a workspace: whether its editors may use the photo entry. The caller checked that the person is an owner.
     *
     * Turning it off is always possible, whatever the instance offers (a workspace must be able to withdraw its consent). Turning it on needs
     * an instance that offers it: the owner is told so instead of ticking a switch that would do nothing.
     *
     * @throws PhotoEntryUnavailableException When turning it on while the instance does not offer it.
     */
    public function setConsent(Workspace $workspace, bool $enabled): void
    {
        if ($enabled && ! $this->availableOnInstance()) {
            throw new PhotoEntryUnavailableException;
        }

        $workspace->photo_entry_enabled = $enabled;
        $workspace->save();
    }

    /**
     * Withdraws the consent of a workspace: the photo entry is off for it until its owner turns it on again.
     *
     * The consent is the owner's, given by the person who owned the workspace at the time: when the ownership changes hands (the owner hands
     * it over, or an administrator deletes the account of the only owner and a member succeeds them), the new owner has to consent
     * himself, since the photos of their editors would go to a third party on a decision they did not take. The caller holds the lock of
     * the workspace and has judged; the model it passed is brought in line.
     */
    public function withdrawConsent(Workspace $workspace): void
    {
        Workspace::query()->whereKey($workspace->getKey())->where('photo_entry_enabled', true)->update(['photo_entry_enabled' => false]);

        $workspace->setAttribute('photo_entry_enabled', false);
        $workspace->syncOriginalAttribute('photo_entry_enabled');
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
