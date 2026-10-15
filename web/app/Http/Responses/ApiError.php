<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The error answers of the API that are about the state of things rather than about the shape of the request.
 *
 * Every error body has a human `message`, in the language of the request, and a machine-readable `code` that
 * clients switch on (the message is for people and may change). Validation failures (422) are the framework's, with
 * their `errors`; the refusals below are the conflicts the framework has no word for.
 */
final class ApiError
{
    /**
     * A 409: the request is well formed but the state of the account or of the data does not allow it.
     *
     * @param  string  $message  What happened, for a person.
     * @param  string  $code  Why, for a program.
     * @param  array<string, mixed>  $extra  More fields of the body, such as what stands in the way.
     */
    public static function conflict(string $message, string $code, array $extra = []): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code] + $extra, 409);
    }

    /**
     * A 422 about one field, with a machine-readable reason (the shape the framework gives validation failures, plus `code`).
     */
    public static function invalid(string $message, string $field, string $code): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'errors' => [$field => [$message]], 'code' => $code], 422);
    }
}
