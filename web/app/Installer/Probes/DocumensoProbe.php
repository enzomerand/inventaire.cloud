<?php

declare(strict_types=1);

namespace App\Installer\Probes;

use App\Installer\Settings\DocumensoSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

    /**
     * Lists at most one envelope with the given token.
     */
    public function check(DocumensoSettings $settings): ProbeResult
    {
        try {
            $response = Http::withHeaders(['Authorization' => $settings->apiKey])
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->withoutRedirecting()
                ->get($settings->apiBaseUrl().'/envelope', ['page' => 1, 'perPage' => 1]);
        } catch (ConnectionException $exception) {
            return ProbeResult::failure(__('Cannot reach Documenso at this address.'), 'url');
        } catch (Throwable $exception) {
            return ProbeResult::failure(__('The Documenso address is not valid.'), 'url');
        }

        return match (true) {
            $response->successful() => ProbeResult::success(),
            in_array($response->status(), [401, 403], true) => ProbeResult::failure(__('Documenso refused this API token.'), 'api_key'),
            $response->redirect() => ProbeResult::failure(__('Documenso redirected the request: use the final address of the instance.'), 'url'),
            $response->status() === 404 => ProbeResult::failure(__('This address does not look like a Documenso instance (API not found).'), 'url'),
            default => ProbeResult::failure(__('Documenso answered with an error (HTTP :status).', ['status' => $response->status()]), 'url'),
        };
    }
}
