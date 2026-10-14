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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Second step of signing in: the code typed back.
 *
 * A right code signs the person in, remembered for 30 days. When the address
 * has no account yet the code is not spent until the name of the person comes
 * with it: the account is created at that moment, so signing up and signing
 * in are one flow. Whether an address has an account is only ever revealed to
 * somebody who holds a valid code for it, that is to its owner.
 */
final class VerifyCodeController extends Controller
{
    /**
     * Checks the code and signs the person in, creating the account if needed.
     */
    public function __invoke(VerifyLoginCodeRequest $request, LoginCodes $codes, Accounts $accounts, LocaleResolver $locales): JsonResponse
    {
        $email = $request->email();
        $name = $request->name();
        $user = User::query()->where('email', $email)->first();

        // A name is needed to create an account: until it is known, a right code is checked but kept.
        $verdict = $codes->verify($email, $request->code(), consume: $user !== null || $name !== null);

        if (! $verdict->accepted()) {
            return $this->refusal($verdict);
        }

        $created = false;

        if ($user === null) {
            if ($name === null) {
                return $this->failure(__('Enter your name to create your account.'), 'name', 'name_required');
            }

            [$user, $created] = $accounts->register($email, $name, $locales->resolved());
        }

        $this->signIn($user, $locales);

        return (new UserResource($user))
            ->response()
            ->setStatusCode($created ? 201 : 200)
            ->withCookie($locales->cookie((string) $user->locale));
    }

    /**
     * Opens the session, remembered for 30 days (config/auth.php), after
     * making sure the account carries a verified address and a language.
     */
    private function signIn(User $user, LocaleResolver $locales): void
    {
        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        // The account's language wins over the browser's; an account without one takes the language in use now.
        if ($user->locale === null || ! $locales->isSupported($user->locale)) {
            $user->locale = $locales->resolved();
        }

        $user->save();

        Auth::guard('web')->login($user, remember: true);
    }

    /**
     * The 422 for a code that was not accepted, with a machine-readable reason.
     */
    private function refusal(LoginCodeVerdict $verdict): JsonResponse
    {
        return match ($verdict->outcome) {
            LoginCodeOutcome::Wrong => $this->failure(trans_choice(
                'Incorrect code. :count attempt left.|Incorrect code. :count attempts left.',
                $verdict->attemptsLeft,
            ), 'code', 'login_code_wrong'),
            LoginCodeOutcome::TooManyAttempts => $this->failure(__('Too many incorrect codes: request a new one.'), 'code', 'login_code_exhausted'),
            default => $this->failure(__('This code has expired: request a new one.'), 'code', 'login_code_expired'),
        };
    }

    /**
     * A 422 about one field, with a machine-readable reason (the same shape as the installer's).
     */
    private function failure(string $message, string $field, string $code): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'errors' => [$field => [$message]], 'code' => $code], 422);
    }
}
