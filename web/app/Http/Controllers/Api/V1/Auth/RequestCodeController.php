<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\LoginCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestLoginCodeRequest;
use App\Mail\AfterResponse;
use App\Mail\LoginCodeMail;
use App\Models\User;
use App\Settings\InstanceSettings;
use App\Workspaces\Invitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

/**
 * First step of signing in (or signing up): emails a 6-digit code to an address.
 *
 * The answer is the same whether the address has an account or not, and so is
 * the work done before it: nobody can use this endpoint to find out who has an
 * account, nor, when an administrator has closed registration, to tell the
 * addresses that may sign in from those that may not.
 *
 * What differs between addresses comes after the answer, where nobody can see it: the mail is sent then
 * ({@see AfterResponse}), and it is there that the accounts are looked up when registration is closed. An address
 * without an account that cannot create one is given a code nobody receives, which is judged like any other when it is
 * typed back: were it not stored, "expired" would tell it from an address that has an account and a code, and the
 * sign-in step would reveal who has an account.
 *
 * Registration closed, an address without an account is still given its code when an invitation to join a workspace waits for it
 * ({@see Invitations}): the invitation is its ticket. That, too, is looked up after the answer.
 */
final class RequestCodeController extends Controller
{
    /**
     * Issues a code for the address (replacing the previous one) and mails it once the answer has gone, in the
     * language of the request. If the mail cannot be sent, the code is dropped (while registration is open) and the
     * failure is logged: the person asks for another one.
     */
    public function __invoke(RequestLoginCodeRequest $request, LoginCodes $codes, InstanceSettings $settings, Invitations $invitations): JsonResponse
    {
        $email = $request->email();
        $code = $codes->issue($email);
        $locale = app()->getLocale();
        $minutes = $codes->lifetimeMinutes();

        AfterResponse::run(
            'a sign-in code',
            function () use ($email, $code, $locale, $minutes, $settings, $invitations): void {
                // Registration closed: only an address that has an account, or that an invitation waits for, gets its code
                // (the lookups are made here, after the answer, and only then).
                if (! $settings->registrationOpen() && ! User::query()->where('email', $email)->exists() && ! $invitations->hasPendingFor($email)) {
                    return;
                }

                Mail::to($email)->locale($locale)->send(new LoginCodeMail($code, $minutes));
            },
            function () use ($codes, $settings, $email, $code): void {
                // Closed, the code stays: it is deleted for an address whose mail failed, and an address that has no
                // account (and no invitation) has nothing to fail, so deleting would tell them apart (typing a code back would answer
                // "expired" to the one and "incorrect" to the other) for whoever probes while the mail server is down.
                // An undelivered code does no harm: nobody knows it, and it lapses.
                if ($settings->registrationOpen()) {
                    $codes->forget($email, $code);
                }
            },
        );

        return new JsonResponse(['data' => ['email' => $email, 'expires_in' => $minutes * 60]]);
    }
}
