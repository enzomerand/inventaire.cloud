<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Mail\MailChallengeStore;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * Describes the wizard to the browser: its steps, their fields and values,
 * which are done, and which values are imposed by the environment.
 *
 * Everything shown is built here, translated, so the script never contains
 * display text. Secrets are never included: a field only says whether one is
 * already set.
 */
final class WizardState
{
    public function __construct(
        private readonly InstallationState $state,
        private readonly InstallerSettings $settings,
        private readonly RequirementsChecker $requirements,
        private readonly MailChallengeStore $mailChallenges,
    ) {}

    /**
     * The whole state for the page and for the initial render of the script.
     *
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $requirements = $this->requirements();
        $steps = [$this->requirementsStep($requirements)];

        foreach (InstallStep::cases() as $step) {
            $steps[] = $this->configurationStep($step, $request);
        }

        $steps[] = [
            'id' => 'finalize',
            'title' => __('Installation'),
            'intro' => __('Everything is ready. The tables are created and the instance is switched on.'),
            'status' => InstallationState::STATUS_PENDING,
            'optional' => false,
            'submit' => __('Install'),
            'fields' => [],
        ];

        return [
            'current' => $this->currentStepId($steps),
            'requirements' => $requirements,
            'steps' => $steps,
            'mailPending' => $this->mailChallengeIsPending($request->session()),
            'canFinalize' => $this->state->missingRequiredSteps() === [],
        ];
    }

    /**
     * Status of every step, for the responses of the step endpoints.
     *
     * @return array{statuses: array<string, string>, canFinalize: bool}
     */
    public function progress(): array
    {
        return [
            'statuses' => $this->state->progress(),
            'canFinalize' => $this->state->missingRequiredSteps() === [],
        ];
    }

    /**
     * The requirements check, ready to serialize.
     *
     * @return array{passed: bool, items: list<array{id: string, label: string, ok: bool, detail: ?string}>}
     */
    public function requirements(): array
    {
        $items = $this->requirements->check();

        return [
            'passed' => RequirementsChecker::passed($items),
            'items' => array_map(static fn (Requirement $item): array => $item->toArray(), $items),
        ];
    }

    /**
     * @param  array{passed: bool, items: list<array<string, mixed>>}  $requirements
     * @return array<string, mixed>
     */
    private function requirementsStep(array $requirements): array
    {
        return [
            'id' => 'requirements',
            'title' => __('Requirements'),
            'intro' => __('Checking that this server can run inventaire.cloud.'),
            'status' => $requirements['passed'] ? InstallationState::STATUS_DONE : InstallationState::STATUS_PENDING,
            'optional' => false,
            'submit' => __('Continue'),
            'fields' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationStep(InstallStep $step, Request $request): array
    {
        $values = $this->settings->current($step);
        $fields = [];

        foreach ($step->fields() as $field) {
            $locked = $this->settings->isLocked($field);
            $byUrl = $this->settings->isProvidedByUrl($field);
            $value = $field->secret || $byUrl ? '' : $values[$field->name];

            // The example's address is a development one; the address the administrator is using right now is a better suggestion.
            if ($step === InstallStep::Application && $field->name === 'url' && ! $locked && $this->state->status($step) === InstallationState::STATUS_PENDING) {
                $value = rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
            }

            $fields[] = [
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->type,
                'value' => $value,
                'secret' => $field->secret,
                'secretSet' => $field->secret && $this->settings->hasValue($field),
                'locked' => $locked,
                'masked' => $byUrl,
                'help' => $field->help,
                'placeholder' => $field->placeholder,
                'options' => $field->options,
                'showWhen' => $field->showWhen,
                'error' => '',
            ];
        }

        return [
            'id' => $step->value,
            'title' => $step->title(),
            'intro' => $step->intro(),
            'status' => $this->state->status($step),
            'optional' => $step->isOptional(),
            'notice' => $this->settings->providedUrl($step) === null ? '' : __('The connection is provided by the :variable setting: the fields below are read-only and the checks use it as is.', ['variable' => $step->urlKey()]),
            'submit' => match ($step) {
                InstallStep::Database => __('Test the connection and continue'),
                InstallStep::Application => __('Continue'),
                InstallStep::Mail => __('Send the code'),
                InstallStep::Storage => __('Test the storage and continue'),
                InstallStep::Documenso => __('Test and continue'),
            },
            'fields' => $fields,
        ];
    }

    /**
     * The step to show: the first one that still needs the administrator.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private function currentStepId(array $steps): string
    {
        foreach ($steps as $step) {
            if ($step['status'] === InstallationState::STATUS_PENDING && $step['id'] !== 'finalize') {
                return (string) $step['id'];
            }
        }

        return 'finalize';
    }

    /**
     * Whether a verification code is waiting to be typed (so a reloaded page
     * shows the code form instead of asking for the settings again).
     */
    private function mailChallengeIsPending(Session $session): bool
    {
        $challenge = $this->mailChallenges->get($session);

        return $challenge !== null && $challenge->expiresAt >= time();
    }
}
