{{--
    The tabs of the administration: the users and the settings of the instance. Included by both pages, which say
    which one is open ($active).
--}}
<ul class="nav nav-pills mb-3" aria-label="{{ __('Administration') }}">
    <li class="nav-item">
        <a class="nav-link{{ $active === 'users' ? ' active' : '' }}" href="{{ route('app.admin.users') }}"{!! $active === 'users' ? ' aria-current="page"' : '' !!}>
            <x-tabler-users aria-hidden="true" />
            <span class="ms-1">{{ __('Users') }}</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link{{ $active === 'settings' ? ' active' : '' }}" href="{{ route('app.admin.settings') }}"{!! $active === 'settings' ? ' aria-current="page"' : '' !!}>
            <x-tabler-settings aria-hidden="true" />
            <span class="ms-1">{{ __('Settings') }}</span>
        </a>
    </li>
</ul>
