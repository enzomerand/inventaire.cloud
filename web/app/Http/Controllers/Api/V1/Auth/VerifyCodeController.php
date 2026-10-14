<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\Accounts;
use App\Auth\LoginCodeOutcome;
use App\Auth\LoginCodes;
use App\Auth\LoginCodeVerdict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyLoginCodeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\LocaleResolver;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Second step of signing in: the code typed back.
 *
 * A right code signs the person in, remembered for 30 days. When the address
 * has no account yet the code is not spent until the name of the person comes
 * with it: the account is created at that moment, so signing up and signing
 * in are one flow. Whether an address has an account is only ever revealed to
 * somebody who holds a valid code for it.
 *
 * Spending the code and everything that is saved because of it (the account,
 * its verified address, its language) is one transaction: if any of it fails,
 * the code is still there for the next try. The session is opened once the
 * transaction has committed.
 */
final class VerifyCodeController extends Controller
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Checks the code and signs the person in, creating the account if needed.
     */
    public function __invoke(VerifyLoginCodeRequest $request, LoginCodes $codes, Accounts $accounts, LocaleResolver $locales): JsonResponse
    {
        $email = $request->email();
        $code = $request->code();
        $name = $request->name();

        $outcome = $this->db->transaction(fn (): JsonResponse|User => $this->accept($email, $code, $name, $codes, $accounts, $locales));

        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        Auth::guard('web')->login($outcome, remember: true);

        return (new UserResource($outcome))
            ->response()
            ->setStatusCode($outcome->wasRecentlyCreated ? 201 : 200)
            ->withCookie($locales->cookie((string) $outcome->locale));
    }

    /**
     * Judges the code and, when it is right, spends it and returns the account
     * (created if it did not exist), ready to be signed in. A refusal comes
     * back as its response: the transaction then commits what the refusal
     * itself changed (an attempt counted).
     */
    private function accept(string $email, string $code, ?string $name, LoginCodes $codes, Accounts $accounts, LocaleResolver $locales): JsonResponse|User
    {
        // Before anything is read: requests for one address queue here, and each one then reads the database as
        // the previous one left it (see LoginCodes::lock()).
        $codes->lock($email);

        $user = User::query()->where('email', $email)->first();

        // A name is needed to create an account: until it is known, a right code is checked but kept.
        $verdict = $codes->verify($email, $code, consume: $user !== null || $name !== null);

        if (! $verdict->accepted()) {
            return $this->refusal($verdict);
        }

        if ($user === null) {
            if ($name === null) {
                return $this->failure(__('Enter your name to create your account.'), 'name', 'name_required');
            }

            [$user] = $accounts->register($email, $name, $locales->resolved());
        }

        $this->prepareSession($user, $locales);

        return $user;
    }

    /**
     * Makes sure the account carries a verified address, a language and a
     * "remember me" token, so that opening the session afterwards saves nothing.
     */
    private function prepareSession(User $user, LocaleResolver $locales): void
    {
        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        // The account's language wins over the browser's; an account without one takes the language in use now.
        if ($user->locale === null || ! $locales->isSupported($user->locale)) {
            $user->locale = $locales->resolved();
        }

        // The guard would create it (and save the account) while logging in.
        if (empty($user->getRememberToken())) {
            $user->setRememberToken(Str::random(60));
        }

        $user->save();
    }

    /**
     * The response for a code that was not accepted, with a machine-readable
     * reason: a 422, or a 429 when the address has to wait.
     */
    private function refusal(LoginCodeVerdict $verdict): JsonResponse
    {
        return match ($verdict->outcome) {
            LoginCodeOutcome::Wrong => $this->failure(trans_choice(
                'Incorrect code. :count attempt left.|Incorrect code. :count attempts left.',
                $verdict->attemptsLeft,
            ), 'code', 'login_code_wrong'),
            LoginCodeOutcome::TooManyAttempts => $this->failure(__('Too many incorrect codes: request a new one.'), 'code', 'login_code_exhausted'),
            LoginCodeOutcome::Locked => $this->locked($verdict->retryAfter),
            default => $this->failure(__('This code has expired: request a new one.'), 'code', 'login_code_expired'),
        };
    }

    /**
     * The 429 for an address that collected too many wrong codes: a new code will not help, only waiting does.
     */
    private function locked(int $retryAfter): JsonResponse
    {
        return new JsonResponse(
            ['message' => __('Too many incorrect codes for this address: try again later.'), 'code' => 'login_code_locked'],
            429,
            ['Retry-After' => (string) $retryAfter],
        );
    }

    /**
     * A 422 about one field, with a machine-readable reason (the same shape as the installer's).
     */
    private function failure(string $message, string $field, string $code): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'errors' => [$field => [$message]], 'code' => $code], 422);
    }
}
