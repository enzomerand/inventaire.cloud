<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

use App\Support\DisplayTime;

/**
 * What the types need, beyond the cell, to read it: which sign separates the decimals, in which order a date is written, the time zone
 * a wall-clock time is in, and the language of the messages. Built once for an import from its options.
 *
 * @see ImportOptions
 */
final readonly class ImportContext
{
    /**
     * @param  string  $decimal  `comma` or `point`: the sign a number written as text separates its decimals with.
     * @param  string  $dateOrder  `dmy`, `mdy` or `ymd`: the order of the numbers of a date written as text (an ISO date is read as it is).
     * @param  string  $zone  The time zone a date and time without an offset is in: the one the application shows dates in.
     * @param  string  $locale  The language of the person, for the words of a yes/no.
     */
    public function __construct(
        public string $decimal,
        public string $dateOrder,
        public string $zone,
        public string $locale,
    ) {}

    /**
     * The context of an import: its options, in the time zone of the application.
     */
    public static function for(ImportOptions $options, string $locale): self
    {
        return new self($options->decimal, $options->dateOrder, DisplayTime::zone(), $locale);
    }

    /**
     * The character that separates the decimals of a number.
     */
    public function decimalSign(): string
    {
        return $this->decimal === 'comma' ? ',' : '.';
    }
}
