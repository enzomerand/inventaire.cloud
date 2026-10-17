<?php

declare(strict_types=1);

namespace App\Signatures;

use App\Installer\Settings\DocumensoSettings;

/**
 * What the instance is configured to do with Documenso, the e-signature service: whether signatures can be asked for, whether its
 * webhook is listened to, and where its API is.
 *
 * Everything comes from `services.documenso` (`DOCUMENSO_URL`, `DOCUMENSO_API_KEY`, `DOCUMENSO_WEBHOOK_SECRET`), all optional: an
 * instance without Documenso is complete, it just cannot ask for signatures. The values are read each time, never kept, so that a
 * test (or an administrator who edits the environment file and restarts the workers) is not served a stale answer.
 */
final class Documenso
{
    /**
     * Whether signatures can be asked for: the address of the instance and an API token are both set.
     */
    public static function configured(): bool
    {
        return self::setting('url') !== '' && self::setting('api_key') !== '';
    }

    /**
     * Whether the webhook is open: a secret is set. Without one `/webhooks/documenso` does not exist (404): a webhook that anybody
     * could call would make the server fetch from Documenso on demand, and the schedule resynchronises the requests anyway.
     */
    public static function webhookEnabled(): bool
    {
        return self::setting('webhook_secret') !== '';
    }

    /**
     * The root of Documenso's REST API (version 2), `{url}/api/v2`, whichever way the address was typed.
     */
    public static function apiBaseUrl(): string
    {
        return (new DocumensoSettings(self::setting('url'), self::setting('api_key')))->apiBaseUrl();
    }

    /**
     * The API token of the team, sent bare in the `Authorization` header of every call. Never to be written in a log, an answer or an
     * exception message.
     */
    public static function apiKey(): string
    {
        return self::setting('api_key');
    }

    /**
     * The secret Documenso is told to send in the `X-Documenso-Secret` header of its webhook; an empty text when there is none.
     */
    public static function webhookSecret(): string
    {
        return self::setting('webhook_secret');
    }

    /**
     * A value of `services.documenso`, trimmed; an empty text when it is not set.
     */
    private static function setting(string $key): string
    {
        $value = config("services.documenso.{$key}");

        return is_string($value) ? trim($value) : '';
    }
}
