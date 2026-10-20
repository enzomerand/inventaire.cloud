<?php

declare(strict_types=1);

namespace App\Http\Controllers\Share;

use App\Http\Controllers\Controller;
use App\Http\Responses\StoredFileResponse;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\UploadedFilesType;
use App\Inventories\Sharing\ShareLinks;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/s/{token}/items/{item}/attachments/{attachment}` and `.../thumbnail`: the photos and files of a shared inventory, for anybody who holds
 * the address of a link that shows them.
 *
 * Each request judges the link again, so that a link revoked a moment ago serves nothing: the link must work ({@see ShareLinks::resolve()}) and
 * show the files (`include_files`); the item must belong to the inventory of the link and the file to the item; and the file must be one the
 * page shows, that of a photo or file field of the inventory (a file attached to an item with no field is not shown anywhere on the page, so
 * it is not served either). Anything else is a `404` page, the same whatever the reason. The file itself is answered by
 * {@see StoredFileResponse}, as the API does (a download, a sandbox for whatever it holds, `nosniff`), and nothing is kept by a cache: not even
 * the thumbnail, which the API lets a browser keep for an hour, since a revocation must take effect at once. Like the page, these routes
 * keep no session.
 */
final class ShareFileController extends Controller
{
    /**
     * Gets the service that finds the link, the storage that holds the files and the registry of types, which tells the fields that hold files.
     */
    public function __construct(
        private readonly ShareLinks $links,
        private readonly AttachmentStorage $storage,
        private readonly FieldTypes $types,
    ) {}

    /**
     * The file, as a download; shown in the page instead with `?inline=1` for an image or a PDF. `304` for a reader who has it already.
     */
    public function show(Request $request, string $token, string $item, string $attachment): Response
    {
        return $this->uncached(StoredFileResponse::file($this->find($token, $item, $attachment), $this->storage, $request));
    }

    /**
     * The thumbnail of the file, a JPEG; `404` when it has none.
     */
    public function thumbnail(string $token, string $item, string $attachment): Response
    {
        return $this->uncached(StoredFileResponse::thumbnail($this->find($token, $item, $attachment), $this->storage));
    }

    /**
     * The file the link shows at this address, or a `404` page.
     */
    private function find(string $token, string $item, string $attachment): Attachment
    {
        $link = $this->links->resolve($token);

        abort_if($link === null || ! $link->include_files, 404);

        $found = Attachment::query()
            ->select('attachments.*')
            ->join('items', 'items.id', '=', 'attachments.item_id')
            ->join('inventory_fields', 'inventory_fields.id', '=', 'attachments.field_id')
            ->where('attachments.ulid', strtolower($attachment))
            ->where('attachments.inventory_id', $link->inventory_id)
            ->where('items.ulid', strtolower($item))
            ->where('items.inventory_id', $link->inventory_id)
            ->where('inventory_fields.inventory_id', $link->inventory_id)
            ->whereIn('inventory_fields.type', $this->fileTypes())
            ->first();

        abort_if($found === null, 404);

        return $found;
    }

    /**
     * The keys of the types of field that hold files (`file`, `photo`).
     *
     * @return list<string>
     */
    private function fileTypes(): array
    {
        return array_values(array_map(
            fn ($type): string => $type->key(),
            array_filter($this->types->all(), fn ($type): bool => $type instanceof UploadedFilesType),
        ));
    }

    /**
     * Has a cache revalidate the answer each time (the link may be revoked between two requests).
     */
    private function uncached(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }
}
