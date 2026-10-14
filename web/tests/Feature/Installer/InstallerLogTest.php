<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

it('is readable by the owner and the group only: it holds the setup token', function (): void {
    $log = $this->sandbox->root.'/installer.log';
    $previous = umask(0022);

    try {
        Log::channel('installer')->info('a line');
    } finally {
        umask($previous);
    }

    expect(config('logging.channels.installer.permission'))->toBe(0640)
        ->and(file_get_contents($log))->toContain('a line')
        ->and(fileperms($log) & 0777)->toBe(0640);
});

it('is created private even when the umask would make it world-readable', function (): void {
    $previous = umask(0000);

    try {
        Log::channel('installer')->info('a line');
    } finally {
        umask($previous);
    }

    expect(fileperms($this->sandbox->root.'/installer.log') & 0777)->toBe(0640);
});
