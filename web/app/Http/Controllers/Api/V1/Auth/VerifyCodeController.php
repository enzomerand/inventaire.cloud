<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\Accounts;
use App\Auth\LoginCodeOutcome;
use App\Auth\LoginCodes;
use App\Auth\LoginCodeVerdict;
use App\Database\DeadlockRetries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyLoginCodeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\LocaleResolver;
use App\Settings\InstanceSettings;
use App\Workspaces\Invitations;
use App\Workspaces\Workspaces;
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
 * When an administrator has closed registration, an address that has no account cannot create one: it is judged like
 * any other address (wrong codes are counted, attempts run out), but whatever it types is refused as a wrong code,
 * even the right one, so a code issued before registration closed opens nothing and nothing tells the two apart. The exception is an
 * address that an invitation to join a workspace waits for: the invitation is its ticket, and the right code creates the account.
 *
 * Spending the code and everything that is saved because of it (the account,
 * its verified address, its language, its workspace) is one transaction: if any of it fails,
 * the code is still there for the next try. The session is opened once the
 * transaction has committed.
 */
final class VerifyCodeController extends Controller
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Workspaces $workspaces,
        private readonly InstanceSettings $settings,
        private readonly Invitations $invitations,
    ) {}

    /**
     * Checks the code and signs the person in, creating the account if needed.
     */
    public function __invoke(VerifyLoginCodeRequest $request, LoginCodes $codes, Accounts $accounts, LocaleResolver $locales): JsonResponse
    {
        $email = $request->email();
        $code = $request->code();
        $name = $request->name();

        // Retried when the database picks it as the loser of a deadlock: accounts found without a workspace that sign
        // in at the same moment can wait for each other's place among the memberships (see Workspaces::ensure()); a
        // new account does not, it is given its workspace without that read. Nothing of the attempt that lost is
        // kept, the code included, so the next one starts from the same state.
        $outcome = DeadlockRetries::run(fn (): JsonResponse|User => $this->db->transaction(fn (): JsonResponse|User => $this->accept($email, $code, $name, $codes, $accounts, $locales)));

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
        // Locked before anything is read (see LoginCodes::lock() for why).
        $codes->lock($email);

        // Read for every address, so that the work is the same whether it has an account or not (and so is the look for an
        // invitation, which only matters while registration is closed).
        $registrationOpen = $this->settings->registrationOpen();
        $invited = ! $registrationOpen && $this->invitations->hasPendingFor($email);

        $user = User::query()->where('email', $email)->first();

        // A name is needed to create an account: until it is known, a right code is checked but kept. And when no
        // account can be created (registration closed, and no invitation waiting for the address), the code of an address
        // without one is never the right one.
        $verdict = $codes->verify($email, $code, consume: $user !== null || $name !== null, acceptable: $user !== null || $registrationOpen || $invited);

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
     * Makes sure the account carries a verified address, a language, a
     * "remember me" token and a workspace, so that opening the session
     * afterwards saves nothing and the person's space always has somewhere to
     * work (an account that predates workspaces, or lost its last one, gets
     * its personal workspace here).
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

        $this->workspaces->ensure($user);
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
