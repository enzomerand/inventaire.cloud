{{--
    The notice that the previous request left for this page, shown once as a toast.

    It is for what happened in a request whose answer is not a page (deleting the account is an API call, after which
    the browser goes to the home page): the API flashes a key into the new session, and this page turns it into the
    translated message below. The flash is gone after this page, so reloading does not say it again.

    Alpine runs in its CSP build: the component is referenced by name and reads the type and the message from the
    data-* attributes below (resources/js/components/flash-notice.js).
--}}
@if (session()->has('account_deleted'))
    <div hidden x-data="flashNotice" data-type="success" data-message="{{ __('Your account has been deleted.') }}"></div>
@endif
