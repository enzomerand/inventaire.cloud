<?php

declare(strict_types=1);

namespace App\Http\Requests\Install;

use App\Installer\InstallerSettings;
use App\Installer\InstallStep;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The submitted values of one wizard step.
 *
 * The step is named by the route (`->defaults('step', 'database')`), so one
 * request class validates all of them with the rules the console installer
 * uses too. Before validation, the form is completed with what is already
 * configured (see {@see InstallerSettings::resolve()}): a value imposed by the
 * environment replaces what was posted, and an empty secret means "unchanged".
 */
final class InstallStepRequest extends FormRequest
{
    /**
     * The route is already protected by the setup token middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The step this request is about.
     */
    public function step(): InstallStep
    {
        $step = $this->route()?->defaults['step'] ?? null;

        return InstallStep::from(is_string($step) ? $step : '');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->step()->rules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->step()->attributes();
    }

    /**
     * The validated values, one entry per field of the step.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->validated();
    }

    /**
     * Completes the posted form before it is validated.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(app(InstallerSettings::class)->resolve($this->step(), $this->all()));
    }
}
