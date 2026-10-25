<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\PhotoEntry\PhotoAnalyses;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;

/**
 * The photos of one object to analyse: a multipart `photos[]`, from one to `inventories.max_photos_per_analysis` (5) images, JPEG, PNG or
 * WebP, each at most `inventories.max_attachment_size` bytes. Adding an item is what the analysis is for, so editors and owners only (see
 * {@see InventoryPolicy}).
 *
 * Only the shape and the size are checked here. The type is detected from the content, and the image decoded, by the code that prepares
 * the photos ({@see PhotoAnalyses}).
 */
final class StorePhotoAnalysisRequest extends FormRequest
{
    /**
     * Reading photos for an item is adding items: the policy knows which roles may.
     */
    public function authorize(): Response
    {
        return Gate::inspect('editItems', $this->route('inventory'));
    }

    /**
     * `photos` (required: a list of one to the most the configuration allows) and each photo (an uploaded file of an image type, not too
     * large).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // The size rule counts kilobytes.
        $kilobytes = (int) ceil(config()->integer('inventories.max_attachment_size') / 1024);

        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.config()->integer('inventories.max_photos_per_analysis')],
            'photos.*' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:'.$kilobytes],
        ];
    }

    /**
     * The messages that name a limit do it in words a person reads.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos.max' => trans_choice('You can send :count photo at a time.|You can send :count photos at a time.', config()->integer('inventories.max_photos_per_analysis')),
            'photos.*.max' => __('Each photo must not be larger than :size.', ['size' => Number::fileSize(config()->integer('inventories.max_attachment_size'), maxPrecision: 1)]),
        ];
    }

    /**
     * The photos that were sent, in order.
     *
     * @return list<UploadedFile>
     */
    public function photos(): array
    {
        $files = $this->file('photos');

        return array_values(is_array($files) ? $files : []);
    }
}
