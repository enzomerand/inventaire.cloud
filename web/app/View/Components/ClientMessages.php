<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Hands the translated strings used by JavaScript over to the browser.
 *
 * Locales are resolved on the server, so scripts never hardcode display text:
 * they look messages up by key in the JSON this component embeds in the page
 * (see resources/js/lib/i18n.js).
 */
class ClientMessages extends Component
{
    /**
     * Messages available to scripts, keyed by the identifier the script uses.
     *
     * Each string is wrapped in __() with its English source text so the
     * translation extractor and lang/*.json files stay the single source of
     * truth. Add a key here whenever a script needs a new user-facing string.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'error.generic' => __('Something went wrong. Please try again.'),
            'error.network' => __('Network error. Check your connection and try again.'),
            'error.timeout' => __('The server took too long to answer. Please try again.'),
            'error.server' => __('The server hit an error. Please try again in a moment.'),
            'error.unexpected_response' => __('The server sent an unexpected answer. Please try again.'),
            'error.unauthenticated' => __('You are signed out. Please sign in again.'),
            'error.forbidden' => __('You are not allowed to do this.'),
            'error.not_found' => __('This item no longer exists.'),
            'error.too_many_requests' => __('Too many attempts. Please wait a moment before trying again.'),
            'error.session_expired' => __('Your session has expired. The page is about to reload.'),
        ];
    }

    /**
     * Renders the JSON payload element.
     */
    public function render(): View
    {
        return view('components.client-messages');
    }
}
