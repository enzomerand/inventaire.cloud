<?php

declare(strict_types=1);

namespace App\Inventories\Sharing;

/**
 * One page of the items of a shared inventory, ready for the public page of a share link: the names of the columns, a row of cells for each
 * item, and where the page stands among the items that match the search.
 */
final readonly class SharedPage
{
    /**
     * @param  list<string>  $columns  The name of each column (the fields the link shows, in order).
     * @param  list<list<SharedCell>>  $rows  A row for each item of the page, one cell for each column.
     * @param  string  $search  What the visitor searched for (cut to {@see SharedInventory::SEARCH_MAX_LENGTH}), '' for no search.
     * @param  int  $total  How many items match the search.
     * @param  int  $page  The page shown, from 1.
     * @param  int  $lastPage  The last page there is, 1 at least.
     * @param  int  $first  The position of the first item of the page among the ones that match (0 when there is none).
     * @param  int  $last  The position of the last item of the page.
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public string $search,
        public int $total,
        public int $page,
        public int $lastPage,
        public int $first,
        public int $last,
    ) {}

    /**
     * Whether the page has a page before it.
     */
    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    /**
     * Whether the page has a page after it.
     */
    public function hasNext(): bool
    {
        return $this->page < $this->lastPage;
    }
}
