{{--
    Toast stack, driven by the Alpine "toasts" store (resources/js/stores/toasts.js).

    Two permanent live regions are rendered up front, because screen readers only
    announce content added to a region that already exists: polite for
    success/info, assertive (role="alert") for warnings and errors.
--}}
<div class="app-toasts" x-data="toasts">
    <div class="d-flex flex-column gap-2" role="status" aria-live="polite" aria-atomic="false">
        <template x-for="toast in $store.toasts.polite" :key="toast.id">
            @include('partials.toast')
        </template>
    </div>

    <div class="d-flex flex-column gap-2 mt-2" role="alert" aria-live="assertive" aria-atomic="false">
        <template x-for="toast in $store.toasts.assertive" :key="toast.id">
            @include('partials.toast')
        </template>
    </div>
</div>
