{{--
    Workspace switcher shown in the header of a signed-in user: the workspace they work in, the others to switch
    to, and the way to create one or to manage them.

    Alpine runs in its CSP build: the component is referenced by name and gets its values from the data-*
    attributes below (the list of workspaces as JSON, read by resources/js/components/workspace-switcher.js, which
    shares it through the "workspaces" store with the workspaces page). The name of the current workspace is also
    written here as plain text, for the time before the scripts run. Choosing a workspace takes the person to its
    page (data-workspace-url, where __id__ stands for the ULID). A workspace of somebody else shows the name of its
    owner under its own name.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@php
    $list = $items();
    $current = collect($list)->firstWhere('current', true)['name'] ?? '';
@endphp

<div
    class="nav-item dropdown"
    x-data="workspaceSwitcher"
    data-url="{{ route('api.v1.me.current-workspace', absolute: false) }}"
    data-workspace-url="{{ route('app.workspace', ['workspace' => '__id__'], absolute: false) }}"
    data-workspaces="{{ json_encode($list, JSON_THROW_ON_ERROR) }}"
>
    <button
        type="button"
        class="nav-link dropdown-toggle d-flex align-items-center gap-1 bg-transparent border-0"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        aria-label="{{ __('Change workspace') }}"
    >
        <x-tabler-stack-2 aria-hidden="true" />
        <span class="d-none d-md-inline text-truncate app-workspace-name" x-text="currentName">{{ $current }}</span>
    </button>

    <div class="dropdown-menu dropdown-menu-end">
        <h6 class="dropdown-header">{{ __('Workspaces') }}</h6>
        <template x-for="workspace in workspaces" :key="workspace.id">
            <button
                type="button"
                :class="itemClass(workspace)"
                :aria-current="workspace.current ? 'true' : null"
                :disabled="busy"
                @click="select(workspace)"
            >
                <span class="d-block" x-text="workspace.name"></span>
                {{-- Whose workspace it is, when it is somebody else's: two of them may have the same name. The hidden word is for screen readers, which would read "Personal space Camille Martin". --}}
                <template x-if="ownerOf(workspace)">
                    <span :class="ownerClass(workspace)"><span class="visually-hidden">{{ __('Owner:') }} </span><span x-text="ownerOf(workspace)"></span></span>
                </template>
            </button>
        </template>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item d-flex align-items-center gap-2" href="{{ route('app.workspaces', absolute: false) }}#create">
            <x-tabler-plus aria-hidden="true" />
            {{ __('New workspace') }}
        </a>
        <a class="dropdown-item d-flex align-items-center gap-2" href="{{ route('app.workspaces', absolute: false) }}">
            <x-tabler-settings aria-hidden="true" />
            {{ __('Manage workspaces') }}
        </a>
    </div>
</div>
