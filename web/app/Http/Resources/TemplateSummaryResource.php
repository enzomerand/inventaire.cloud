<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Inventories\Templates\Template;
use App\Inventories\Templates\TemplateCategories;
use App\Support\PublicUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the API describes a template in the list: enough to draw a card, in the language of the request.
 *
 * `url` is the public page of the template in that language, absolute.
 *
 * @property Template $resource
 */
final class TemplateSummaryResource extends JsonResource
{
    /**
     * The slug, the category (and its heading), the icon, the name, the summary, the number of fields and the page.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $template = $this->resource;
        $locale = app()->getLocale();

        return [
            'slug' => $template->slug,
            'category' => $template->category,
            'category_label' => TemplateCategories::label($template->category),
            'icon' => $template->icon,
            'name' => $template->name($locale),
            'summary' => $template->summary($locale),
            'fields_count' => $template->fieldsCount(),
            'url' => PublicUrls::template($template->slug, $locale),
        ];
    }
}
