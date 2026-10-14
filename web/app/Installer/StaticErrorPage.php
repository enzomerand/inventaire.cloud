<?php

declare(strict_types=1);

namespace App\Installer;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A self-contained error page for failures that happen before the application
 * can render anything: no Blade, no session, no database, no built assets.
 *
 * It exists for the one case where the installer itself cannot start (no
 * application key and an unwritable .env), where any richer page would fail
 * the same way.
 */
final class StaticErrorPage
{
    /**
     * Builds the HTML response.
     *
     * The text is deliberately generic: this page is shown to anybody who
     * reaches the site, so it never carries a file path or an exception
     * message. Those go to the log ({@see self::report()}).
     */
    public static function response(): Response
    {
        $title = e(__('The installer cannot start'));
        $body = e(__('A file or folder the installer needs is not writable. Check the permissions of the application folders and of its configuration file, then reload this page. The details are in the installer log.'));
        $lang = e(app()->getLocale());

        $html = <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$title}</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f6f8fb;color:#1d273b;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:34rem;margin:1rem;padding:2rem;background:#fff;border:1px solid #dce1e7;border-radius:6px}
h1{margin:0 0 1rem;font-size:1.25rem;color:#d63939}
p{margin:0}
</style>
</head>
<body>
<main role="alert">
<h1>{$title}</h1>
<p>{$body}</p>
</main>
</body>
</html>
HTML;

        return new Response($html, 500, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Records why the installer cannot start, in the installer log or, when
     * even that is not writable, in the server's error log.
     */
    public static function report(Throwable $exception): void
    {
        $line = 'inventaire.cloud installer cannot start: '.$exception->getMessage();

        try {
            Log::channel('installer')->error($line);
        } catch (Throwable) {
            error_log($line);
        }
    }
}
