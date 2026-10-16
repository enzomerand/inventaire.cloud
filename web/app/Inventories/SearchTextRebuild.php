<?php

declare(strict_types=1);

namespace App\Inventories;

/**
 * What {@see DerivedData::rebuildSearchText()} did: whether it went over every text or only the missing ones, and how
 * many inventories it could not do (each logged), which the upgrade reports and which keep the version of the form
 * from being recorded.
 */
final readonly class SearchTextRebuild
{
    /**
     * @param  bool  $everything  Whether every text was to be built again (the version of its form changed), not only the missing ones.
     * @param  int  $failed  The number of inventories whose texts could not be built (they are tried again by the next upgrade).
     */
    public function __construct(
        public bool $everything,
        public int $failed,
    ) {}
}
