{{--
    The items of an inventory as a gallery of cards, for a phone or for a person who likes to browse: each card is called by
    the first text the item holds, and shows a few of its other values with their labels. The same page of items, with the
    same toolbar, count and pagination as the table; the totals, which have no column to sit under, are a line above the cards.

    Included in the items card of the page of an inventory (app/inventory.blade.php), so it reads the inventoryItems
    component (resources/js/components/inventory-items.js, with resources/js/grid/gallery.js). A card opens the item as a row
    does (the form for who may edit, the details for the others): the name is a button, which is what a keyboard reaches, and a
    click anywhere else on the card does the same; a link in a card is a link.

    Do not put a display utility (d-flex...) on an element that carries x-show or :hidden: the utility wins and it never hides.
--}}
<div class="card-body" :hidden="!showGallery" :class="tableClass" :aria-busy="busyAttribute">
    <div class="mb-3" :hidden="!showGalleryTotals">
        <span class="fw-bold me-2" x-text="totalsLabel"></span>
        <template x-for="total in galleryTotals" :key="total.key">
            <span class="me-3 text-nowrap">
                <span class="text-secondary" x-text="total.name"></span>
                <span class="fw-bold" x-text="total.text"></span>
            </span>
        </template>
    </div>
    <div class="row row-cards">
        <template x-for="card in cards" :key="card.id">
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                <div class="card h-100 app-row-link" @click="openFromRow(card, $event)">
                    {{-- The first photo of the item, when it has one: its thumbnail, with the file name as alternative text. --}}
                    <template x-if="card.cover">
                        <div class="position-relative">
                            <img class="card-img-top app-cover" :src="card.cover.src" :alt="card.cover.alt" loading="lazy">
                            <template x-if="card.cover.more">
                                <span class="badge bg-dark text-white position-absolute bottom-0 end-0 m-2" x-text="card.cover.more"></span>
                            </template>
                        </div>
                    </template>
                    <div class="card-body">
                        <h3 class="card-title text-break mb-3">
                            <button type="button" class="btn btn-link p-0 text-start text-reset fw-bold text-break" :class="{ 'fst-italic text-secondary': card.untitled }" :aria-label="openLabel + ': ' + card.title" @click.stop="open(card)" x-text="card.title"></button>
                        </h3>
                        <dl class="m-0">
                            <template x-for="cell in card.cells" :key="cell.key">
                                <div class="mb-2">
                                    <dt class="text-secondary fw-normal small" x-text="cell.name"></dt>
                                    <dd class="m-0">
                                        @include('partials.field-value')
                                    </dd>
                                </div>
                            </template>
                        </dl>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
