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
            'install.status.done' => __('Done'),
            'install.status.skipped' => __('Skipped'),
            'install.status.pending' => __('To do'),
            'install.requirement.ok' => __('Met'),
            'install.requirement.missing' => __('Not met'),
            'install.mail.code_sent' => __('A verification code was sent. Check the inbox of the test address.'),
            'install.warning.documenso_insecure_url' => __('The Documenso address uses plain http: the API token travels unencrypted. Prefer https unless Documenso runs on this machine or a private network.'),
            'install.finished' => __('Installation complete. Redirecting...'),
            'login.error.not_stateful' => __('Signing in is not available from this address. Ask the administrator of this instance to check its SANCTUM_STATEFUL_DOMAINS setting.'),
            'login.error.throttled' => __('Too many attempts. Try again in :seconds seconds.'),
            'login.error.throttled_minutes' => __('Too many attempts. Try again in :minutes minutes.'),
            'login.notice.code_resent' => __('A new code was sent.'),
            'workspaces.role.owner' => __('Owner'),
            'workspaces.role.editor' => __('Editor'),
            'workspaces.role.viewer' => __('Viewer'),
            'workspaces.rename_named' => __('Rename :name'),
            'workspaces.delete_named' => __('Delete :name'),
            'workspaces.confirm_delete' => __('Delete :name and everything in it? This cannot be undone.'),
            'workspaces.notice.created' => __('Workspace created.'),
            'workspaces.notice.renamed' => __('Workspace renamed.'),
            'workspaces.notice.deleted' => __('Workspace deleted.'),
            'workspaces.error.last' => __('You cannot delete your last workspace: create another one first.'),
            'account.notice.saved' => __('Your account was updated. The page is about to reload.'),
            'account.notice.signed_out_everywhere' => __('You are signed out of all your other devices.'),
            'account.notice.code_sent' => __('A code was sent to your email address.'),
            'account.error.last_administrator' => __('You are the last administrator of this instance: make somebody else an administrator first (php artisan inventaire:make-admin).'),
            'account.error.sole_owner' => __('You are the only owner of workspaces that other people belong to: hand them over or delete them first.'),
            'admin.users.range' => __(':first–:last of :total'),
            'admin.users.workspace_count' => __('Workspaces: :count'),
            'admin.users.created_on' => __('Created on :date'),
            'admin.users.promote' => __('Make administrator'),
            'admin.users.demote' => __('Remove administrator'),
            'admin.users.promote_named' => __('Make :name an administrator'),
            'admin.users.demote_named' => __('Remove :name from the administrators'),
            'admin.users.delete_named' => __('Delete the account of :name'),
            'admin.users.confirm_delete' => __('Delete the account of :name, and the workspaces only this person belongs to? Workspaces shared with other people are handed over to their longest-standing member. This cannot be undone.'),
            'admin.users.notice.created' => __('Account created. The person is emailed how to sign in.'),
            'admin.users.notice.promoted' => __('Administrator rights granted.'),
            'admin.users.notice.demoted' => __('Administrator rights removed.'),
            'admin.users.notice.deleted' => __('Account deleted.'),
            'admin.users.error.account_exists' => __('An account already exists for this address.'),
            'admin.users.error.last_administrator' => __('This is the last administrator of the instance: make somebody else an administrator first.'),
            'admin.users.error.self_deletion' => __('You cannot delete your own account here: use your account page.'),
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
