<?php

declare(strict_types=1);

namespace App\Installer\Settings;

/**
 * Where the application keeps uploaded files: the local disk or an
 * S3-compatible bucket.
 */
final readonly class StorageSettings
{
    public function __construct(
        public string $disk,
        public ?string $key = null,
        public ?string $secret = null,
        public ?string $region = null,
        public ?string $bucket = null,
        public ?string $endpoint = null,
        public bool $pathStyle = false,
    ) {}

    /**
     * Builds the settings from the values of the storage step.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $text = static function (string $key) use ($values): ?string {
            $value = $values[$key] ?? null;

            return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        };

        return new self(
            disk: $text('disk') ?? 'local',
            key: $text('key'),
            secret: $text('secret'),
            region: $text('region'),
            bucket: $text('bucket'),
            endpoint: $text('endpoint'),
            pathStyle: filter_var($values['path_style'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * Whether the files go to an S3-compatible service.
     */
    public function isS3(): bool
    {
        return $this->disk === 's3';
    }

    /**
     * A Laravel disk configuration for these settings, set to throw on
     * failure so a probe sees the real error instead of a silent `false`.
     *
     * @param  array<string, mixed>  $local  The application's own `local` disk, used as is for local storage.
     * @return array<string, mixed>
     */
    public function diskConfig(array $local): array
    {
        if (! $this->isS3()) {
            return $local + ['throw' => true];
        }

        return [
            'driver' => 's3',
            'key' => $this->key,
            'secret' => $this->secret,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => $this->pathStyle,
            'throw' => true,
        ];
    }
}
