{{--
    The history tab of the dialog of an item, for everybody who can see the item: who changed it and when, newest first, and
    what they changed ("Price: 10 → 12", "File added: invoice.pdf"). The first page is read when the tab is first shown; "Load
    more" adds the next one.

    Included in the dialog of an item (app/inventory.blade.php), so it reads the inventoryItems component
    (resources/js/components/inventory-items.js, with resources/js/item/history.js).

    Do not put a display utility (d-flex...) on an element that carries x-show or :hidden: the utility wins and it never hides.
--}}
<div>
    <div class="text-secondary" :hidden="!firstRevisionsLoading" role="status">
        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
        {{ __('Loading…') }}
    </div>

    <div class="alert alert-danger" role="alert" x-show="revisionsFailed">
        <div class="d-flex align-items-center gap-3">
            <span>{{ __('The history could not be loaded.') }}</span>
            <button type="button" class="btn btn-sm ms-auto" @click="retryRevisions" :disabled="revisionsLoading">{{ __('Try again') }}</button>
        </div>
    </div>

    <p class="text-secondary m-0" x-show="noRevisions">{{ __('No change recorded yet.') }}</p>

    <ul class="list-group list-group-flush">
        <template x-for="revision in revisions" :key="revision.id">
            <li class="list-group-item px-0">
                <div class="fw-bold text-break" x-text="revision.title"></div>
                <ul class="list-unstyled m-0 text-secondary">
                    <template x-for="line in revision.lines" :key="line.key">
                        <li class="text-break" x-text="line.text"></li>
                    </template>
                </ul>
            </li>
        </template>
    </ul>

    <div class="mt-3" x-show="hasMoreRevisions">
        <button type="button" class="btn btn-sm" @click="loadMoreRevisions" :disabled="revisionsLoading">
            <span class="spinner-border spinner-border-sm me-2" :hidden="!moreRevisionsLoading" aria-hidden="true"></span>
            {{ __('Load more') }}
        </button>
    </div>
</div>
