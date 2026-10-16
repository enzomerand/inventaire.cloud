<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Http\Resources\TemplateSummaryResource;
use App\Http\Responses\ApiError;
use App\Inventories\Templates\TemplateCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The catalogue of inventory templates: public (no account needed), limited in pace, in the language of the request.
 *
 * An inventory is made from a template by `POST /api/v1/workspaces/{workspace}/inventories` with the slug as `template`.
 */
final class TemplateController extends Controller
{
    /**
     * Gets the catalogue.
     */
    public function __construct(private readonly TemplateCatalogue $catalogue) {}

    /**
     * Every template, by category then by name: the slug, the category, the icon, the name, the summary, the number of fields
     * and the address of its public page.
     */
    public function index(): AnonymousResourceCollection
    {
        $templates = [];

        foreach ($this->catalogue->grouped(app()->getLocale()) as $group) {
            array_push($templates, ...$group);
        }

        return TemplateSummaryResource::collection($templates);
    }

    /**
     * One template with its description, its fields and its examples; `404` when there is none with this slug.
     */
    public function show(string $slug): TemplateResource|JsonResponse
    {
        $template = $this->catalogue->find($slug);

        return $template === null
            ? ApiError::notFound(__('This template does not exist.'), 'template_not_found')
            : new TemplateResource($template);
    }
}
