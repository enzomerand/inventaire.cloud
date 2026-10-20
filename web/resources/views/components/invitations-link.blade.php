{{--
    The link to the invitations of the signed-in person, in the header, with how many wait for an answer. It is rendered only
    when some do, and only on the pages of the app (see App\View\Components\InvitationsLink).

    Alpine runs in its CSP build: the "invitationsLink" component (resources/js/components/invitations-link.js) takes the count
    from the data-count attribute and follows the "invitations-changed" event of the window, which the page of invitations sends
    when the person answers one. The element is hidden with the "hidden" attribute once none is left; the count is also written
    here as plain text, for the time before the scripts run. The label says how many wait, in words (the badge is only a figure).

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
<div
    class="nav-item"
    x-data="invitationsLink"
    data-count="{{ $waiting }}"
    :hidden="!visible"
    @invitations-changed.window="update"
>
    <a
        class="nav-link d-flex align-items-center gap-1"
        href="{{ route('app.invitations', absolute: false) }}"
        aria-label="{{ $waiting === 1 ? __(':count invitation waiting', ['count' => 1]) : __(':count invitations waiting', ['count' => $waiting]) }}"
        :aria-label="label"
    >
        <x-tabler-mail aria-hidden="true" />
        <span class="d-none d-md-inline">{{ __('Invitations') }}</span>
        <span class="badge bg-red text-red-fg" x-text="count">{{ $waiting }}</span>
    </a>
</div>
