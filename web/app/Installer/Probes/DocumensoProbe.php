<?php

declare(strict_types=1);

namespace App\Installer\Probes;

use App\Installer\Settings\DocumensoSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a Documenso address and API token with one cheap authenticated read.
 *
 * Documenso's REST API v2 (https://docs.documenso.com/docs/developers/api)
 * authenticates with the bare token in the `Authorization` header (no
 * "Bearer"). Listing envelopes, one per page, is the lightest call that needs
 * a valid token: a wrong token answers 401, a token without access 403, and
 * a URL that is not a Documenso instance 404 or an HTML page.
 */
final class DocumensoProbe
{
    /** Seconds before giving up on the instance. */
    private const TIMEOUT = 10;

    /** Seconds to establish the connection. */
    private const CONNECT_TIMEOUT = 5;

    /**
     * Lists at most one envelope with the given token.
     *
     * Only network and HTTP failures are turned into a verdict. Anything else
     * is a bug: it is logged (without the token) and rethrown so it surfaces
     * instead of masquerading as "cannot reach Documenso".
     */
    public function check(DocumensoSettings $settings): ProbeResult
    {
        try {
            $response = Http::withHeaders(['Authorization' => $settings->apiKey])
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->withoutRedirecting()
                ->get($settings->apiBaseUrl().'/envelope', ['page' => 1, 'perPage' => 1]);
        } catch (ConnectionException) {
            return ProbeResult::failure(__('Cannot reach Documenso at this address.'), 'url');
        } catch (RequestException $exception) {
            return ProbeResult::failure(__('Documenso answered with an error (HTTP :status).', ['status' => $exception->response->status()]), 'url');
        } catch (Throwable $exception) {
            $this->report($exception, $settings);

            throw $exception;
        }

        return match (true) {
            $response->successful() => ProbeResult::success([], $this->warnings($settings)),
            in_array($response->status(), [401, 403], true) => ProbeResult::failure(__('Documenso refused this API token.'), 'api_key'),
            $response->redirect() => ProbeResult::failure(__('Documenso redirected the request: use the final address of the instance.'), 'url'),
            $response->status() === 404 => ProbeResult::failure(__('This address does not look like a Documenso instance (API not found).'), 'url'),
            default => ProbeResult::failure(__('Documenso answered with an error (HTTP :status).', ['status' => $response->status()]), 'url'),
        };
    }

    /**
     * Non-blocking remarks about the address: a token sent over plain http
     * crosses the network readable, unless Documenso runs on this very machine.
     *
     * @return array<string, string>
     */
    private function warnings(DocumensoSettings $settings): array
    {
        $parts = parse_url(trim($settings->url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = $host === 'localhost' || $host === '[::1]' || $host === '::1' || str_ends_with($host, '.localhost') || str_starts_with($host, '127.');

        if (strtolower((string) ($parts['scheme'] ?? '')) === 'http' && ! $local) {
            return ['documenso_insecure_url' => __('The Documenso address uses plain http: the API token travels unencrypted. Prefer https unless Documenso runs on this machine or a private network.')];
        }

        return [];
    }

    /**
     * Logs an unexpected failure, with the token masked.
     */
    private function report(Throwable $exception, DocumensoSettings $settings): void
    {
        $message = $settings->apiKey === '' ? $exception->getMessage() : str_replace($settings->apiKey, '***', $exception->getMessage());

        try {
            Log::channel('installer')->error('Unexpected failure while checking Documenso: '.$exception::class.': '.$message);
        } catch (Throwable) {
            error_log('Unexpected failure while checking Documenso: '.$exception::class);
        }
    }
}
