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
    ) {}

    /**
     * Builds the settings from the values of the mail step.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
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
        );
    }

    /**
     * A Laravel mailer configuration for these settings. A short timeout keeps
     * a wrong host from freezing the wizard.
     *
     * @return array<string, mixed>
     */
    public function mailerConfig(): array
    {
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
