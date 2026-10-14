<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Auth\LoginCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestLoginCodeRequest;
use App\Mail\LoginCodeMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * First step of signing in (or signing up): emails a 6-digit code to an address.
 *
 * The answer is the same whether the address has an account or not, and so is
 * the work done: nobody can use this endpoint to find out who has an account.
 */
final class RequestCodeController extends Controller
{
    /**
     * Issues a code for the address (replacing the previous one) and mails it
     * at once, in the language of the request, so that an instance without a
     * queue worker still delivers it.
     *
     * @throws Throwable When the mail cannot be sent; the code is dropped then.
     */
    public function __invoke(RequestLoginCodeRequest $request, LoginCodes $codes): JsonResponse
    {
        $email = $request->email();
        $code = $codes->issue($email);

        try {
            Mail::to($email)->locale(app()->getLocale())->send(new LoginCodeMail($code, $codes->lifetimeMinutes()));
        } catch (Throwable $exception) {
            $codes->forget($email);

            throw $exception;
        }

        return new JsonResponse(['data' => ['email' => $email, 'expires_in' => $codes->lifetimeMinutes() * 60]]);
    }
}
