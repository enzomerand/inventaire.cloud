{{--
    The public page of a share link: the name and the description of an inventory, its fields as columns and its items, read-only, for
    anybody who holds the secret address (see App\Http\Controllers\Share\SharePageController).

    Plain HTML, nothing to run in the browser: the search is a GET form, the pages are links, the table scrolls inside its own container
    so that the page itself never overflows on a phone. Nothing here names the workspace, its members or who made what; the head has no
    canonical address, description or social card (layouts/share.blade.php), since they would write the secret address down. Every text is
    escaped. The photos and files are links to the routes of the link, which check it at each request.
--}}
@extends('layouts.share')

@section('title', $inventory->name)

@section('content')
    @php
        // The address of a page of the list, with the search kept: the first page and an empty search are left out of it.
        $pageUrl = fn (int $number): string => route('share.show', array_filter(
            ['token' => $token, 'q' => $shared->search, 'page' => $number > 1 ? $number : null],
            fn (mixed $value): bool => $value !== '' && $value !== null,
        ), absolute: false);
    @endphp

    <div class="mb-3">
        <h1 class="page-title text-break">{{ $inventory->name }}</h1>
        @if (filled($inventory->description))
            <p class="text-secondary text-break app-multiline mb-0 mt-1">{{ $inventory->description }}</p>
        @endif
        <p class="text-secondary small mb-0 mt-2">
            {{ trans_choice(':count item|:count items', $inventory->items_count) }}
            @if ($expires !== null)
                &middot; {{ __('This link expires on :date.', ['date' => $expires]) }}
            @endif
        </p>
    </div>

    <form class="mb-3" method="get" action="{{ route('share.show', ['token' => $token], absolute: false) }}" role="search">
        <div class="input-group">
            <input
                type="search"
                name="q"
                class="form-control"
                value="{{ $shared->search }}"
                maxlength="{{ \App\Inventories\Sharing\SharedInventory::SEARCH_MAX_LENGTH }}"
                aria-label="{{ __('Search') }}"
                placeholder="{{ __('Search') }}"
                autocomplete="off"
            >
            <button type="submit" class="btn btn-primary">
                <x-tabler-search aria-hidden="true" />
                {{ __('Search') }}
            </button>
            @if ($shared->search !== '')
                <a class="btn" href="{{ route('share.show', ['token' => $token], absolute: false) }}">{{ __('Clear the search') }}</a>
            @endif
        </div>
    </form>

    @if ($shared->search !== '')
        <p class="text-secondary" role="status">
            {{ trans_choice(':count result out of :total|:count results out of :total', $shared->total, ['total' => $inventory->items_count]) }}
        </p>
    @endif

    <div class="card">
        @if ($shared->rows === [])
            <div class="card-body text-center text-secondary py-5">
                {{ $shared->search !== '' ? __('No item matches your search.') : __('No item yet') }}
            </div>
        @else
            <div class="table-responsive" tabindex="0" role="region" aria-label="{{ __('Items') }}">
                <table class="table table-vcenter card-table">
                    <caption class="visually-hidden">{{ $inventory->name }}</caption>
                    <thead>
                        <tr>
                            @foreach ($shared->columns as $column)
                                <th scope="col" class="text-break">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shared->rows as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td>@include('share.cell')</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($shared->lastPage > 1)
            <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                <span class="text-secondary">
                    {{ __(':first–:last of :total', ['first' => $shared->first, 'last' => $shared->last, 'total' => $shared->total]) }}
                </span>
                <nav class="ms-auto" aria-label="{{ __('Pagination Navigation') }}">
                    <ul class="pagination m-0">
                        <li class="page-item @unless ($shared->hasPrevious()) disabled @endunless">
                            @if ($shared->hasPrevious())
                                <a class="page-link" href="{{ $pageUrl($shared->page - 1) }}" rel="prev">{{ __('Previous') }}</a>
                            @else
                                <span class="page-link">{{ __('Previous') }}</span>
                            @endif
                        </li>
                        <li class="page-item disabled">
                            <span class="page-link">{{ __('Page :page / :pages', ['page' => $shared->page, 'pages' => $shared->lastPage]) }}</span>
                        </li>
                        <li class="page-item @unless ($shared->hasNext()) disabled @endunless">
                            @if ($shared->hasNext())
                                <a class="page-link" href="{{ $pageUrl($shared->page + 1) }}" rel="next">{{ __('Next') }}</a>
                            @else
                                <span class="page-link">{{ __('Next') }}</span>
                            @endif
                        </li>
                    </ul>
                </nav>
            </div>
        @endif
    </div>
@endsection
