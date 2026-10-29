{{--
    The two plans compared line by line: the limits, which come from the configuration of the instance (App\Landing\PricingPlans),
    then what both plans include. Nothing is called unlimited: every quota is finite. A feature the instance cannot offer (the photo
    entry without its switch and key, signed exports without Documenso) is not listed.

    A table for a screen reader: a caption, column headers and row headers. It scrolls inside its own region when a phone is too narrow
    for it, and the region takes the keyboard focus so the table can be scrolled without a mouse.

    Icons: never pass a class attribute to <x-tabler-*>.

    `$plans` is the App\Landing\PricingPlans.
--}}
@php
    $limits = [
        [__('Inventories'), $plans->number($plans->limit('free', 'inventories')), $plans->number($plans->limit('pro', 'inventories'))],
        [__('Items per inventory'), $plans->number($plans->limit('free', 'items')), $plans->number($plans->limit('pro', 'items'))],
        [__('File storage'), $plans->storage($plans->limit('free', 'storage_bytes')), $plans->storage($plans->limit('pro', 'storage_bytes'))],
        [__('Members (pending invitations included)'), $plans->number($plans->limit('free', 'members')), $plans->number($plans->limit('pro', 'members'))],
        [__('Share links per inventory'), $plans->number($plans->limit('free', 'share_links')), $plans->number($plans->limit('pro', 'share_links'))],
    ];

    $both = [
        \App\Landing\Pitch::fieldTypes(),
        __('Import XLSX, ODS, CSV'),
        __('Export XLSX, ODS, CSV, PDF'),
        __('Item history and attachments'),
        __('Barcode scan and installable app'),
        __('Members with roles'),
        __('Read-only share links'),
        __('Public API with personal tokens'),
    ];

    if ($plans->signatures) {
        $both[] = __('Signed official export');
    }
@endphp

<div class="table-responsive" tabindex="0" role="region" aria-label="{{ __('Compare the plans') }}">
    <table class="table table-vcenter landing-table">
        <caption class="visually-hidden">{{ __('Compare the plans') }}</caption>
        <thead>
            <tr>
                <td></td>
                <th scope="col">{{ __('Free') }}</th>
                <th scope="col" class="landing-table-pro">{{ __('Pro') }}</th>
            </tr>
        </thead>

        <tbody>
            <tr>
                <th scope="rowgroup" colspan="3" class="landing-table-group">{{ __('Limits per workspace') }}</th>
            </tr>
            @foreach ($limits as [$label, $free, $pro])
                <tr>
                    <th scope="row" class="fw-normal">{{ $label }}</th>
                    <td>{{ $free }}</td>
                    <td class="landing-table-pro">{{ $pro }}</td>
                </tr>
            @endforeach
            @if ($plans->mentionsPhotoEntry())
                <tr>
                    <th scope="row" class="fw-normal">{{ __('Add items from photos (AI)') }}</th>
                    @foreach (['free', 'pro'] as $plan)
                        <td @class(['landing-table-pro' => $plan === 'pro'])>
                            @if ($plans->includesPhotoEntry($plan))
                                <x-tabler-check aria-hidden="true" /><span class="visually-hidden">{{ __('Included') }}</span>
                            @else
                                <x-tabler-minus aria-hidden="true" /><span class="visually-hidden">{{ __('Not included') }}</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endif
        </tbody>

        <tbody>
            <tr>
                <th scope="rowgroup" colspan="3" class="landing-table-group">{{ __('Included in both plans') }}</th>
            </tr>
            @foreach ($both as $feature)
                <tr>
                    <th scope="row" class="fw-normal">{{ $feature }}</th>
                    <td><x-tabler-check aria-hidden="true" /><span class="visually-hidden">{{ __('Included') }}</span></td>
                    <td class="landing-table-pro"><x-tabler-check aria-hidden="true" /><span class="visually-hidden">{{ __('Included') }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
