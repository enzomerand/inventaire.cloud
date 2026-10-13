<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Http\Requests\Install\InstallStepRequest;
use App\Http\Requests\Install\MailCodeRequest;
use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallerSteps;
use App\Installer\Mail\MailChallengeStore;
use App\Installer\Mail\MailCodeOutcome;
use App\Installer\Mail\MailVerification;
use App\Installer\Probes\ProbeResult;
use App\Installer\WizardState;
use Illuminate\Http\JsonResponse;
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
     * Tests the database connection and saves it.
     */
    public function database(InstallStepRequest $request): JsonResponse
    {
        return $this->saved(fn (): ProbeResult => $this->steps->saveDatabase($request->values()));
    }

    /**
     * Saves the application settings.
     */
    public function application(InstallStepRequest $request): JsonResponse
    {
        return $this->saved(function () use ($request): ProbeResult {
            $this->steps->saveApplication($request->values());

            return ProbeResult::success();
        });
    }

    /**
     * Sends the verification code to the test address. The step is saved once
     * the code is confirmed by {@see self::verifyMail()}.
     */
    public function mail(InstallStepRequest $request, MailChallengeStore $store): JsonResponse
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
     * Tests the file storage and saves it.
     */
    public function storage(InstallStepRequest $request): JsonResponse
    {
        return $this->saved(fn (): ProbeResult => $this->steps->saveStorage($request->values()));
    }

    /**
     * Tests the Documenso address and token and saves them.
     */
    public function documenso(InstallStepRequest $request): JsonResponse
    {
        return $this->saved(fn (): ProbeResult => $this->steps->saveDocumenso($request->values()));
    }

    /**
     * Skips the optional Documenso step.
     */
    public function skipDocumenso(): JsonResponse
    {
        $this->steps->skipDocumenso();

        return new JsonResponse($this->wizard->progress());
    }

    /**
     * Runs a step's save and answers with the new progress, or with the
     * probe's failure.
     *
     * @param  callable(): ProbeResult  $save
     */
    private function saved(callable $save): JsonResponse
    {
        $this->failUnless($this->saving($save));

        return new JsonResponse($this->wizard->progress());
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
