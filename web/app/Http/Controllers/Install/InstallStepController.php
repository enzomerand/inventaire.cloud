<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Http\Requests\Install\InstallStepRequest;
use App\Http\Requests\Install\MailCodeRequest;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallerSteps;
use App\Installer\InstallStep;
use App\Installer\Mail\MailChallengeStore;
use App\Installer\Mail\MailCodeOutcome;
use App\Installer\Mail\MailVerification;
use App\Installer\Probes\ProbeResult;
use App\Installer\WizardState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One endpoint per wizard step. Each validates the form, probes the real
 * service, and only then saves, so a step that returns 200 is a step that works.
 *
 * Failures come back as 422 with the usual `errors` map; a failure that does
 * not belong to one field is reported under `general`.
 */
final class InstallStepController extends Controller
{
    public function __construct(
        private readonly InstallerSteps $steps,
        private readonly WizardState $wizard,
    ) {}

    /**
     * Saves one configuration step: validates the form, tests the real service
     * and, only when it works, writes `.env` and records the step. The step is
     * named by the route, so every step goes through here.
     *
     * The mail step does not save yet: it sends a verification code, and is
     * saved once the code is confirmed by {@see self::verifyMail()}.
     */
    public function save(InstallStepRequest $request, MailChallengeStore $store): JsonResponse
    {
        $step = $request->step();

        if ($step->sendsEmail()) {
            return $this->sendCode($request, $store);
        }

        return $this->saved(fn (): ProbeResult => $this->steps->save($step, $request->values()));
    }

    /**
     * Skips an optional step.
     */
    public function skip(Request $request): JsonResponse
    {
        $step = $request->route()?->defaults['step'] ?? null;

        $this->saving(fn () => $this->steps->skip(InstallStep::from(is_string($step) ? $step : '')));

        return new JsonResponse($this->wizard->progress());
    }

    /**
     * Sends the verification code of the mail step.
     */
    private function sendCode(InstallStepRequest $request, MailChallengeStore $store): JsonResponse
    {
        [$result, $challenge] = $this->steps->beginMail($request->values());

        $this->failUnless($result);

        if ($challenge !== null) {
            $store->put($request->session(), $challenge);
        }

        return new JsonResponse($this->wizard->progress() + ['mailPending' => true]);
    }

    /**
     * Checks the code typed back and, if right, saves the mail settings.
     *
     * An expired or exhausted code answers with a `code` so the page returns to
     * the form, where a new code can be requested.
     */
    public function verifyMail(MailCodeRequest $request, MailChallengeStore $store, MailVerification $verification): JsonResponse
    {
        $session = $request->session();
        $challenge = $store->get($session);

        if ($challenge === null) {
            return $this->codeFailure(__('There is no pending code: request a new one.'), 'mail_code_missing');
        }

        [$outcome, $kept] = $verification->confirm($challenge, $request->string('code')->toString());

        switch ($outcome) {
            case MailCodeOutcome::Accepted:
                $this->saving(fn () => $this->steps->completeMail($kept));
                $store->forget($session);
                $store->rememberVerified($session, $kept->recipient);

                return new JsonResponse($this->wizard->progress() + ['mailPending' => false]);

            case MailCodeOutcome::Wrong:
                $store->put($session, $kept);

                return $this->codeFailure(trans_choice(
                    'Incorrect code. :count attempt left.|Incorrect code. :count attempts left.',
                    $verification->attemptsLeft($kept),
                ), 'mail_code_wrong');

            case MailCodeOutcome::Expired:
                $store->forget($session);

                return $this->codeFailure(__('This code has expired: request a new one.'), 'mail_code_expired');

            case MailCodeOutcome::TooManyAttempts:
                $store->forget($session);

                return $this->codeFailure(__('Too many incorrect codes: request a new one.'), 'mail_code_exhausted');
        }
    }

    /**
     * Runs a step's save and answers with the new progress, or with the
     * probe's failure.
     *
     * @param  callable(): ProbeResult  $save
     */
    private function saved(callable $save): JsonResponse
    {
        $result = $this->saving($save);

        $this->failUnless($result);

        // Warnings go as codes: the page translates them, it never shows text taken from a response.
        return new JsonResponse($this->wizard->progress() + ($result->warnings === [] ? [] : ['warnings' => array_keys($result->warnings)]));
    }

    /**
     * Runs something that writes .env, turning its failure into a form error.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     *
     * @throws ValidationException
     */
    private function saving(callable $write): mixed
    {
        try {
            return $write();
        } catch (InstallationException $exception) {
            throw ValidationException::withMessages(['general' => $exception->userMessage()]);
        }
    }

    /**
     * @throws ValidationException When the probe failed.
     */
    private function failUnless(ProbeResult $result): void
    {
        if (! $result->ok) {
            throw ValidationException::withMessages([$result->field ?? 'general' => (string) $result->message]);
        }
    }

    /**
     * A 422 about the verification code, with a machine-readable reason.
     */
    private function codeFailure(string $message, string $code): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'errors' => ['code' => [$message]], 'code' => $code], 422);
    }
}
