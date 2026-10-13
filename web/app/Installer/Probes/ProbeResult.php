<?php

declare(strict_types=1);

namespace App\Installer\Probes;

/**
 * The verdict of a probe: a live check of a setting against the real service.
 *
 * A failure carries the (translated) reason and, when it can be tied to one
 * input, the name of that input so the web form can flag it. The same object
 * is printed by the console installer.
 */
final readonly class ProbeResult
{
    /**
     * @param  array<string, string>  $meta  Facts learned on success (server version...), for display.
     */
    private function __construct(
        public bool $ok,
        public ?string $field,
        public ?string $message,
        public array $meta,
    ) {}

    /**
     * @param  array<string, string>  $meta
     */
    public static function success(array $meta = []): self
    {
        return new self(true, null, null, $meta);
    }

    /**
     * @param  string  $message  Translated reason.
     * @param  string|null  $field  Input the failure is about, if any.
     */
    public static function failure(string $message, ?string $field = null): self
    {
        return new self(false, $field, $message, []);
    }
}
