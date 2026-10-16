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
     * A 403: the caller is known but is not allowed to do this (any more).
     *
     * @param  string  $message  What happened, for a person.
     * @param  string  $code  Why, for a program.
     */
    public static function forbidden(string $message, string $code): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], 403);
    }

    /**
     * A 404: the thing the request is about is not there (any more).
     *
     * @param  string  $message  What happened, for a person.
     * @param  string  $code  Why, for a program.
     */
    public static function notFound(string $message, string $code): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], 404);
    }

    /**
     * A 503: something the request needs (the disk of the files, say) cannot be reached right now; trying again later may work.
     *
     * @param  string  $message  What happened, for a person.
     * @param  string  $code  Why, for a program.
     */
    public static function unavailable(string $message, string $code): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], 503);
    }

    /**
     * A 422 about one field, with a machine-readable reason (the shape the framework gives validation failures, plus `code`).
     */
    public static function invalid(string $message, string $field, string $code): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'errors' => [$field => [$message]], 'code' => $code], 422);
    }
}
