<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventories;

use App\Inventories\Attachments\Attachments;
use App\Policies\InventoryPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;

/**
 * A file to attach to an item: one multipart `file` part, at most `inventories.max_attachment_size` bytes.
 *
 * Only the shape and the size are checked here. The type is detected from the content by the service that stores the file,
 * and the limits that depend on what the item and the workspace hold now are judged under their locks
 * ({@see Attachments}).
 */
final class UploadAttachmentRequest extends FormRequest
{
    /**
     * Attaching files is changing the item: the policy knows which roles may (see {@see InventoryPolicy}).
     */
    public function authorize(): Response
    {
        return Gate::inspect('editItems', $this->route('inventory'));
    }

    /**
     * The file: present, really uploaded, and not too large.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // The size rule counts kilobytes.
        $kilobytes = (int) ceil(config()->integer('inventories.max_attachment_size') / 1024);

        return [
            'file' => ['required', 'file', 'max:'.$kilobytes],
        ];
    }

    /**
     * The message of the size rule names the limit in words a person reads.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => __('The file must not be larger than :size.', ['size' => Number::fileSize(config()->integer('inventories.max_attachment_size'), maxPrecision: 1)]),
        ];
    }

    /**
     * The file that was sent.
     */
    public function uploaded(): UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : throw new \LogicException('The request holds no file: validation should have refused it.');
    }
}
