<?php

declare(strict_types=1);

namespace App\Inventories\Imports;

/**
 * What reading the beginning of a file tells: its columns, and where each one is proposed to go.
 */
final readonly class ImportAnalysis
{
    /**
     * @param  list<array{index: int, header: string, samples: list<string>, guess: string}>  $columns  `guess` is the key of the type of field the values look like.
     * @param  ImportMapping  $mapping  The proposal: one entry per column.
     * @param  ImportOptions  $options  The options the file is read with: those it was given, and the decimal sign and the order of the dates found in it.
     */
    public function __construct(public array $columns, public ImportMapping $mapping, public ImportOptions $options) {}
}
