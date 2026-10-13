<?php

declare(strict_types=1);

namespace App\Installer\Exceptions;

use RuntimeException;

/**
 * A failure of the installer that people can act on (unwritable .env, steps
 * missing, migration error...).
 *
 * The message is translated lazily, when it is shown ({@see self::userMessage()}),
 * not when the exception is thrown: some failures happen while the application
 * is still registering its providers, before the translator exists. The
 * English source text is what `getMessage()` returns, which suits logs.
 */
final class InstallationException extends RuntimeException
{
    /**
     * @param  string  $source  English message, also the key of its translation in lang/*.json.
     * @param  array<string, string|int>  $replace  Values for the `:placeholders` of the message.
     */
    public function __construct(private readonly string $source, private readonly array $replace = [])
    {
        parent::__construct(strtr($source, array_map(strval(...), array_combine(
            array_map(static fn (string $key): string => ':'.$key, array_keys($replace)),
            $replace,
        ))));
    }

    /**
     * The message in the current language, ready to show to people.
     */
    public function userMessage(): string
    {
        return __($this->source, $this->replace);
    }
}
