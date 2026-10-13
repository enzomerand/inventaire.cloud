{{-- One toast, rendered inside an Alpine x-for that provides `toast`. --}}
<div class="toast show border-start border-3" :class="'border-' + toast.type">
    <div class="d-flex align-items-start">
        <div class="toast-body flex-fill" x-text="toast.message"></div>
        <button
            type="button"
            class="btn-close m-2"
            :aria-label="$t('close')"
            @click="$store.toasts.dismiss(toast.id)"
        ></button>
    </div>
</div>
