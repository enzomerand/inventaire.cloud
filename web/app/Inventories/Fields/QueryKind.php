<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Inventories\ItemQuery;

/**
 * How the values of a type of field are sorted and filtered when the items of an inventory are listed ({@see ItemQuery}).
 *
 * It is the shape of the stored value that decides, not the name of the type: a rating and an amount are both numbers
 * to sort. Each kind says which sort the values have and which conditions a filter may put on them. Every kind can be
 * filtered on whether the field holds a value (`empty`).
 */
enum QueryKind: string
{
    /**
     * Plain text (a name, an address, a color code). Sorted as text, with the collation of the database connection, so
     * that the capitals and the accents do not scatter the order; a filter can only ask whether the field is empty (what
     * the text says is looked for with the search of the list).
     */
    case Text = 'text';

    /**
     * A decimal number kept as a string, a rating or an amount. Sorted and compared as a number ("9" is below "10"),
     * exactly, never as a floating point value. A filter can give a minimum and a maximum, both included.
     */
    case Number = 'number';

    /**
     * A date, a moment or a time of day, stored as a fixed-width text where the alphabetical order is the order in time.
     * Sorted as that text. A filter can give a minimum and a maximum in the stored form, both included.
     */
    case Temporal = 'temporal';

    /**
     * A yes or no. Sorted with "no" before "yes". A filter can ask for one of the two.
     */
    case Boolean = 'boolean';

    /**
     * One choice among those the field offers. Sorted in the order the field lists its choices. A filter can give the
     * choices to keep; an item holding any of them matches.
     */
    case Choice = 'choice';

    /**
     * Several choices among those the field offers. Not sorted (an item has no single position). A filter can give the
     * choices to keep; an item holding any of them matches.
     */
    case Choices = 'choices';

    /**
     * Free labels, any number per item. Not sorted. A filter can only ask whether the field is empty (the labels are
     * looked for with the search of the list).
     */
    case Tags = 'tags';

    /**
     * Files attached to the item (the list of the ULIDs of its attachments). Not sorted. A filter can only ask whether the
     * field is empty, that is whether the item has a file in it.
     */
    case Files = 'files';

    /**
     * Whether the values of this kind can be sorted: an item with several values has no place in an order.
     */
    public function sortable(): bool
    {
        return $this !== self::Choices && $this !== self::Tags && $this !== self::Files;
    }
}
