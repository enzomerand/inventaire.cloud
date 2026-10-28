<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Billing\WorkspaceLimits;
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
 * On an instance that sells plans, a fourth: the plan of the workspace includes it ({@see WorkspaceLimits::photoEntry()}).
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
     * Whether the editors of this workspace may use it: the instance offers it, the owner of the workspace turned it on, and the plan of
     * the workspace allows it (see {@see WorkspaceLimits::photoEntry()}: always, on an instance that sells no plan).
     *
     * The consent is the owner's own and is kept as it was whatever the plan: a workspace that goes back to the Free plan keeps it, and the
     * photo entry is back as soon as the plan allows it again. Everything that offers the photo entry or runs it asks this.
     */
    public function enabledFor(Workspace $workspace): bool
    {
        return $workspace->photo_entry_enabled && $this->availableOnInstance() && WorkspaceLimits::photoEntry($workspace);
    }

    /**
     * Whether the plan of the workspace is what keeps it from the photo entry: the instance offers the feature, and the plan, which only
     * exists where billing is on, does not include it. The one refusal that subscribing mends, so it is told apart from the others
     * (the switch of the administrator, the API key), which no workspace can mend. The consent of the owner is not looked at: the plan
     * comes first, since turning the consent on would not be enough.
     */
    public function refusedByPlan(Workspace $workspace): bool
    {
        return $this->availableOnInstance() && ! WorkspaceLimits::photoEntry($workspace);
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
