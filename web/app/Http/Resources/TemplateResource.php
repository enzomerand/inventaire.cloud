<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\Fields\FieldTypes;
use App\Inventories\Templates\Template;
use App\Inventories\Templates\TemplateCategories;
use App\Support\PublicUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * How the API describes one template, in the language of the request: what the summary has, plus the description, the
 * fields it would create and its example rows.
 *
 * A field has its `key` (what the examples are keyed by), its name and type (with the label of the type), whether it is
 * required and its options as the file gives them: for a choice field, `choices` lists `{key, label, color}` (the stored
 * ids are made when an inventory is created from the template). An example row is keyed by the field keys, with the
 * values as the API writes them (a choice by its key, a number as a decimal text).
 *
 * @property Template $resource
 */
final class TemplateResource extends JsonResource
{
    /**
     * The template, as the API gives it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $template = $this->resource;
        $locale = app()->getLocale();
        $types = app(FieldTypes::class);

        return [
            'slug' => $template->slug,
            'category' => $template->category,
            'category_label' => TemplateCategories::label($template->category),
            'icon' => $template->icon,
            'name' => $template->name($locale),
            'summary' => $template->summary($locale),
            'description' => $template->description($locale),
            'fields' => array_map(fn ($field): array => [
                'key' => $field->key,
                'name' => $field->name,
                'type' => $field->type,
                'type_label' => $types->find($field->type)?->label() ?? $field->type,
                'required' => $field->required,
                'options' => $field->publicOptions() === [] ? new stdClass : $field->publicOptions(),
            ], $template->fields($locale)),
            'examples' => $template->examples($locale),
            'url' => PublicUrls::template($template->slug, $locale),
        ];
    }
}
