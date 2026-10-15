<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FieldTypeResource;
use App\Inventories\Fields\FieldTypes;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The types a field can have, in the order they are offered, each with its name in the language of the request.
 */
final class FieldTypeController extends Controller
{
    /**
     * Every type of the registry: `type` (the word to send when adding a field) and `label`.
     */
    public function __invoke(FieldTypes $types): AnonymousResourceCollection
    {
        return FieldTypeResource::collection($types->all());
    }
}
