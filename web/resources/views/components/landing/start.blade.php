{{--
    The main action of the sales pages: a signed-in person goes to their space, a visitor to the sign-in page (the account is made there,
    with the e-mail alone: there is no separate sign-up). The look is the caller's (`class`); any other attribute is passed to the link.
--}}
@props(['class' => 'btn btn-primary btn-lg'])

@auth
    <a {{ $attributes->merge(['class' => $class, 'href' => route('app', absolute: false)]) }}>{{ __('Open my space') }}</a>
@else
    <a {{ $attributes->merge(['class' => $class, 'href' => route('login', absolute: false)]) }}>{{ __('Start for free') }}</a>
@endauth
