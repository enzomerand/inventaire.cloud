<?php

declare(strict_types=1);

namespace App\Installer\Settings;

/**
 * Address and API token of the Documenso e-signature service.
 */
final readonly class DocumensoSettings
{
    public function __construct(public string $url, public string $apiKey) {}

    /**
     * Builds the settings from the values of the Documenso step.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $text = static function (string $key) use ($values): string {
            $value = $values[$key] ?? '';

            return is_scalar($value) ? (string) $value : '';
        };

        return new self($text('url'), $text('api_key'));
    }

    /**
     * The root of Documenso's REST API (version 2) on this instance.
     *
     * People paste either the instance address or the API address, so a
     * trailing slash and an `/api/v2` suffix are both tolerated.
     */
    public function apiBaseUrl(): string
    {
        $base = rtrim(trim($this->url), '/');
        $base = (string) preg_replace('#/api(/v\d+)?$#', '', $base);

        return $base.'/api/v2';
    }
}
