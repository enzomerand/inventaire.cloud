<?php

declare(strict_types=1);

namespace App\PhotoEntry;

use App\Models\InventoryField;

/**
 * Reads the photos of one object and proposes values for the fields of an inventory.
 *
 * It is the one place the application talks to a model: {@see ClaudePhotoAnalyzer} asks Claude, {@see FakePhotoAnalyzer} makes proposals up
 * (the tests, and a development machine without a key). The rest of the application only knows this interface, and treats what comes back
 * as untrusted text: {@see Proposals} converts and checks every value before anyone sees it.
 */
interface PhotoAnalyzer
{
    /**
     * Reads the photos.
     *
     * @param  list<string>  $photos  The bytes of the photos, JPEGs prepared by {@see PhotoImages}, all of the same object.
     * @param  list<InventoryField>  $fields  The fields to fill: the eligible ones ({@see PhotoFields::eligible()}), never empty.
     * @param  string  $locale  The language the person reads (`fr`, `en`), the one the evidence is written in.
     *
     * @throws PhotoAnalysisException When the photos could not be analysed; its `error` says why.
     */
    public function analyze(array $photos, array $fields, string $locale): PhotoReading;
}
