<?php

declare(strict_types=1);

namespace App\Installer\Probes;

use App\Installer\Settings\StorageSettings;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Throwable;

/**
 * The real storage check: writes, reads back and deletes a temporary file on
 * the disk built from the settings.
 *
 * Going through `Storage::build()` exercises exactly the configuration the
 * application will use, credentials and endpoint included, without touching
 * the application's configured disks.
 */
final class FilesystemStorageProbe implements StorageProbe
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Writes, reads and deletes a temporary file.
     */
    public function check(StorageSettings $settings): ProbeResult
    {
        if ($settings->isS3() && ! class_exists(AwsS3V3Adapter::class)) {
            return ProbeResult::failure(__('The S3 driver is not installed (league/flysystem-aws-s3-v3).'), 'disk');
        }

        $name = '.installer-probe-'.Str::lower(Str::random(16));
        $payload = Str::random(32);
        $disk = null;

        try {
            $disk = Storage::build($settings->diskConfig($this->localDisk()));

            return $this->roundTrip($disk, $name, $payload, $settings);
        } catch (Throwable $exception) {
            return ProbeResult::failure(__('The storage did not accept the test file: :reason', ['reason' => $this->reason($exception, $settings)]), $settings->isS3() ? 'bucket' : 'disk');
        } finally {
            try {
                $disk?->delete($name);
            } catch (Throwable) {
                // Nothing left to clean up if the write never happened.
            }
        }
    }

    private function roundTrip(Filesystem $disk, string $name, string $payload, StorageSettings $settings): ProbeResult
    {
        $disk->put($name, $payload);

        if ($disk->get($name) !== $payload) {
            return ProbeResult::failure(__('The test file read back from the storage differs from what was written.'), $settings->isS3() ? 'bucket' : 'disk');
        }

        $disk->delete($name);

        return ProbeResult::success();
    }

    /**
     * The application's own local disk configuration.
     *
     * @return array<string, mixed>
     */
    private function localDisk(): array
    {
        $disk = $this->config->get('filesystems.disks.local');

        return is_array($disk) ? $disk : ['driver' => 'local', 'root' => storage_path('app/private')];
    }

    /**
     * A short, single-line version of an error that never contains the secret key.
     */
    private function reason(Throwable $exception, StorageSettings $settings): string
    {
        $message = (string) preg_replace('/\s+/', ' ', $exception->getMessage());

        foreach ([$settings->secret, $settings->key] as $secret) {
            if ($secret !== null && $secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return Str::limit(trim($message), 240);
    }
}
