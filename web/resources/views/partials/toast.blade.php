{{-- One toast, rendered inside an Alpine x-for (see components/toasts.blade.php) that provides `toast`. --}}
<div class="toast show border-start border-3" :class="levelClass(toast)">
    <div class="d-flex align-items-start">
        <div class="toast-body flex-fill" x-text="toast.message"></div>
        <button
            type="button"
            class="btn-close m-2"
            aria-label="{{ __('Close') }}"
            @click="dismiss(toast.id)"
        ></button>
    </div>
</div>
