<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Auth\AccountDeletion;
use App\Auth\AccountDeletionCodes;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Auth\LoginCodeOutcome;
use App\Auth\LoginCodeVerdict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Http\Responses\ApiError;
use App\Mail\AccountDeletionCodeMail;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Deleting one's own account: a code is mailed to the address of the account, then typed back to confirm.
 *
 * Both steps are session actions (the person is signed out at the end), so they are for the web front only.
 * Whether the account can be deleted at all is judged by {@see AccountDeletion}; the person is told as soon as
 * they ask for the code, so they do not wait for a mail they cannot use.
 */
final class AccountDeletionController extends Controller
{
    public function __construct(
        private readonly AccountDeletionCodes $codes,
        private readonly AccountDeletion $deletion,
    ) {}

    /**
     * Mails a confirmation code to the address of the account, in its language, replacing the previous one.
     *
     * @throws Throwable When the mail cannot be sent; the code is dropped then.
     */
    public function code(Request $request): JsonResponse
    {
        $user = $this->user($request);

        try {
            $this->deletion->ensureDeletable($user);
        } catch (AccountDeletionRefused $refusal) {
            return $this->refusal($refusal);
        }

        $code = $this->codes->issue($user);

        try {
            Mail::to($user->email)->locale($user->locale ?? app()->getLocale())->send(new AccountDeletionCodeMail($code, $this->codes->lifetimeMinutes()));
        } catch (Throwable $exception) {
            $this->codes->forget($user);
            throw $exception;
        }

        return new JsonResponse(['data' => ['expires_in' => $this->codes->lifetimeMinutes() * 60]]);
    }

    /**
     * Deletes the account when the code is the right one, then ends the session (`204`).
     *
     * A code that is not right is a `422` with a `code` (`deletion_code_wrong`, `deletion_code_expired`,
     * `deletion_code_exhausted`); an account that cannot be deleted yet is a `409` (`last_administrator`,
     * `sole_owner_of_shared_workspaces`) and keeps its code, so that nothing has to be asked again; an account that
     * somebody else deleted in the meantime is a `404` (`account_not_found`).
     */
    public function destroy(DeleteAccountRequest $request): JsonResponse|Response
    {
        $user = $this->user($request);

        $verdict = $this->codes->verify($user, $request->code());

        if (! $verdict->accepted()) {
            return $this->wrongCode($verdict);
        }

        try {
            $this->deletion->delete($user);
        } catch (AccountDeletionRefused $refusal) {
            return $this->refusal($refusal);
        } catch (ModelNotFoundException) {
            // Somebody else deleted the account while this was being confirmed: there is nothing left to delete, and
            // this request must not report a deletion it did not make.
            return ApiError::notFound(__('This account no longer exists.'), 'account_not_found');
        }

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $guard->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Nothing in the rest of this request may still point at the account: the (empty) session saved when the
        // answer leaves would otherwise be stamped with its id, by the guard that authenticated the request.
        Auth::forgetGuards();
        app()->forgetInstance('auth.driver');

        return response()->noContent();
    }

    /**
     * The 409 for an account that cannot be deleted yet, saying why.
     */
    private function refusal(AccountDeletionRefused $refusal): JsonResponse
    {
        if ($refusal->reason === AccountDeletionRefused::LAST_ADMINISTRATOR) {
            return ApiError::conflict(
                __('You are the last administrator of this instance: make somebody else an administrator first (php artisan inventaire:make-admin).'),
                $refusal->reason,
            );
        }

        return ApiError::conflict(
            __('You are the only owner of workspaces that other people belong to: hand them over or delete them first.'),
            $refusal->reason,
            ['workspaces' => array_map(fn ($workspace): array => ['id' => $workspace->ulid, 'name' => $workspace->name], $refusal->workspaces)],
        );
    }

    /**
     * The 422 for a code that was not accepted.
     */
    private function wrongCode(LoginCodeVerdict $verdict): JsonResponse
    {
        return match ($verdict->outcome) {
            LoginCodeOutcome::Wrong => ApiError::invalid(trans_choice(
                'Incorrect code. :count attempt left.|Incorrect code. :count attempts left.',
                $verdict->attemptsLeft,
            ), 'code', 'deletion_code_wrong'),
            LoginCodeOutcome::TooManyAttempts => ApiError::invalid(__('Too many incorrect codes: request a new one.'), 'code', 'deletion_code_exhausted'),
            default => ApiError::invalid(__('This code has expired: request a new one.'), 'code', 'deletion_code_expired'),
        };
    }
}
