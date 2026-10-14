<?php

declare(strict_types=1);

namespace App\Installer\Settings;

/**
 * SMTP details and sender identity of the application mail.
 */
final readonly class MailSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $scheme,
        public ?string $username,
        public ?string $password,
        public string $fromAddress,
        public string $fromName,
        public ?string $url = null,
    ) {}

    /**
     * Builds the settings from the values of the mail step.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $url  The mailer URL (MAIL_URL) when one is provided; it then replaces the connection fields.
     */
    public static function fromArray(array $values, ?string $url = null): self
    {
        $text = static function (string $key) use ($values): string {
            $value = $values[$key] ?? '';

            return is_scalar($value) ? (string) $value : '';
        };
        $optional = static fn (string $key): ?string => $text($key) === '' ? null : $text($key);

        return new self(
            host: $text('host'),
            port: (int) $text('port'),
            scheme: $optional('scheme'),
            username: $optional('username'),
            password: $optional('password'),
            fromAddress: $text('from_address'),
            fromName: $text('from_name'),
            url: $url,
        );
    }

    /**
     * A Laravel mailer configuration for these settings. A short timeout keeps
     * a wrong host from freezing the wizard.
     *
     * With a mailer URL the connection fields are ignored, as Laravel does: the
     * URL is parsed by the mail manager when the mailer is built.
     *
     * @param  array<string, mixed>  $base  The application's own smtp mailer, which the URL completes.
     * @return array<string, mixed>
     */
    public function mailerConfig(array $base = []): array
    {
        if ($this->url !== null) {
            return array_replace($base, ['transport' => 'smtp', 'url' => $this->url, 'timeout' => 10]);
        }

        return [
            'transport' => 'smtp',
            'scheme' => $this->scheme,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'timeout' => 10,
        ];
    }
}
