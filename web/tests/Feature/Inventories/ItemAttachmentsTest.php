<?php

declare(strict_types=1);

use App\Inventories\Attachments\Attachments;
use App\Inventories\Attachments\AttachmentStorage;
use App\Inventories\Items;
use App\Models\Attachment;
use App\Models\ItemRevision;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/attachments.php';

/*
 * The files attached to an item: upload, list (the `attachments` of the item), download, thumbnail and removal; what is
 * detected, cleaned up and refused; the limits (size, files of an item, storage of a workspace); who may; and what is
 * written in the history and in the storage counter of the workspace. The thumbnails themselves are in
 * AttachmentThumbnailsTest.php, the files that sit in file and photo fields in FileFieldsTest.php, what becomes of the
 * stored files when something is deleted in StoredFilesPurgeTest.php, and two uploads at the same moment in
 * AttachmentsConcurrencyTest.php.
 */

uses(RefreshDatabase::class);

/**
 * The storage counter of a workspace as the database holds it.
 */
function attachmentStorageUsed(Workspace $workspace): int
{
    return (int) DB::table('workspaces')->where('id', $workspace->id)->value('storage_used');
}

describe('the tables', function (): void {
    it('have the columns the models read', function (): void {
        expect(Schema::hasColumns('attachments', ['id', 'ulid', 'inventory_id', 'item_id', 'field_id', 'disk', 'path', 'thumbnail_path', 'name', 'mime', 'size', 'width', 'height', 'uploaded_by', 'created_at', 'updated_at']))->toBeTrue()
            ->and(Schema::hasColumn('workspaces', 'storage_used'))->toBeTrue();
    });

    it('start every workspace without any file', function (): void {
        $case = attachmentCase();

        expect(attachmentStorageUsed($case['team']['workspace']))->toBe(0)
            ->and(Workspace::query()->findOrFail($case['team']['workspace']->id)->storage_used)->toBe(0)
            ->and(Workspace::factory()->create()->storage_used)->toBe(0);
    });

    it('have a configuration with the limits the spec asks for', function (): void {
        expect(config('inventories.max_attachment_size'))->toBe(20 * 1024 * 1024)
            ->and(config('inventories.max_attachments_per_item'))->toBe(20)
            ->and(config('inventories.storage_per_workspace'))->toBe(1024 * 1024 * 1024);
    });
});

describe('uploading a file', function (): void {
    it('stores it in a folder named after the workspace, the inventory and the item, under the ULID of the file', function (): void {
        $case = attachmentCase();

        $response = attachmentUpload($this, $case['team']['editor'], $case['files'], attachmentPdf('Facture 2026.pdf'))->assertCreated();
        $attachment = Attachment::query()->sole();
        $path = "workspaces/{$case['team']['workspace']->ulid}/inventories/{$case['inventory']->ulid}/items/{$case['item']->ulid}/{$attachment->ulid}";

        expect($attachment->path)->toBe($path)
            ->and($attachment->disk)->toBe(ATTACHMENT_TEST_DISK)
            ->and($attachment->thumbnail_path)->toBeNull()
            ->and($attachment->name)->toBe('Facture 2026.pdf')
            ->and($attachment->mime)->toBe('application/pdf')
            ->and($attachment->size)->toBe(strlen((string) Storage::disk(ATTACHMENT_TEST_DISK)->get($path)))
            ->and($attachment->item_id)->toBe($case['item']->id)
            ->and($attachment->field_id)->toBeNull()
            ->and($attachment->uploaded_by)->toBe($case['team']['editor']->id)
            ->and(storedFiles())->toBe([$path])
            ->and($response->json('data.id'))->toBe($case['item']->ulid)
            ->and($response->json('data.attachments'))->toHaveCount(1);
    });

    it('describes the file in the item that comes back, with the addresses to fetch it from', function (): void {
        $case = attachmentCase();

        $file = attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated()->json('data.attachments.0');
        $attachment = Attachment::query()->sole();

        expect(array_keys($file))->toBe(['id', 'field', 'name', 'mime', 'size', 'width', 'height', 'has_thumbnail', 'uploaded_at', 'uploaded_by', 'url', 'thumbnail_url'])
            ->and($file['id'])->toBe($attachment->ulid)
            ->and($file['field'])->toBeNull()
            ->and($file['name'])->toBe('invoice.pdf')
            ->and($file['mime'])->toBe('application/pdf')
            ->and($file['size'])->toBe($attachment->size)
            ->and($file['width'])->toBeNull()
            ->and($file['height'])->toBeNull()
            ->and($file['has_thumbnail'])->toBeFalse()
            ->and($file['uploaded_at'])->toStartWith(substr(now()->toIso8601String(), 0, 10))
            ->and($file['uploaded_by'])->toBe(['id' => $case['team']['owner']->id, 'name' => 'Owner'])
            ->and($file['url'])->toBe($case['files'].'/'.$attachment->ulid)
            ->and($file['thumbnail_url'])->toBeNull();
    });

    it('never puts the name the client gave, or its extension, in the path', function (): void {
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('../../../etc/passwd.php', 'x'))->assertCreated();
        $attachment = Attachment::query()->sole();

        expect($attachment->name)->toBe('passwd.php')
            ->and($attachment->path)->not->toContain('passwd')
            ->and($attachment->path)->not->toContain('.php')
            ->and($attachment->path)->toMatch('#^workspaces/[0-9a-z]{26}/inventories/[0-9a-z]{26}/items/[0-9a-z]{26}/[0-9a-z]{26}$#');
    });

    it('detects the type from the content, not from what the client says', function (): void {
        $case = attachmentCase();

        // A script that says it is a JPEG image, and a PNG that says it is plain text.
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('photo.jpg', "<?php echo 'hello';\n", 'image/jpeg'))->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage('notes.txt'))->assertCreated();

        [$script, $image] = Attachment::query()->orderBy('id')->get()->all();

        expect($script->mime)->not->toStartWith('image/')
            ->and($script->mime)->toStartWith('text/')
            ->and($script->thumbnail_path)->toBeNull()
            ->and($script->name)->toBe('photo.jpg')
            ->and($image->mime)->toBe('image/png')
            ->and($image->thumbnail_path)->not->toBeNull();
    });

    it('keeps a file whose content tells nothing as an octet stream or an empty file', function (): void {
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('data.bin', random_bytes(64)))->assertCreated();

        expect(Attachment::query()->sole()->mime)->toMatch('#^[a-z]+/[a-z0-9.+-]+$#');
    });

    it('refuses a request without a file, or with something that is not one', function (): void {
        $case = attachmentCase();

        attachmentGet($this, $case['team']['owner'], $case['uri']);
        $this->flushSession();
        app('auth')->forgetGuards();
        $this->actingAs($case['team']['owner']);

        $this->post($case['files'], [], frontend(['Accept' => 'application/json']))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post($case['files'], ['file' => 'not a file'], frontend(['Accept' => 'application/json']))->assertUnprocessable()->assertJsonValidationErrors('file');

        expect(Attachment::query()->count())->toBe(0)
            ->and(storedFiles())->toBe([]);
    });

    it('is allowed to editors and owners, refused to viewers, and not there for strangers', function (): void {
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        attachmentUpload($this, $case['team']['editor'], $case['files'], attachmentPdf())->assertCreated();
        attachmentUpload($this, $case['team']['viewer'], $case['files'], attachmentPdf())->assertForbidden();
        attachmentUpload($this, $case['team']['stranger'], $case['files'], attachmentPdf())->assertNotFound();

        $this->flushSession();
        app('auth')->forgetGuards();
        $this->post($case['files'], ['file' => attachmentPdf()], frontend(['Accept' => 'application/json']))->assertUnauthorized();

        expect(Attachment::query()->count())->toBe(2)
            ->and(storedFiles())->toHaveCount(2);
    });

    it('goes to the item of the inventory in the path or is a 404', function (): void {
        $case = attachmentCase();
        $elsewhere = inventoryIn($case['team']['workspace'], 'Elsewhere');

        attachmentUpload($this, $case['team']['owner'], "/api/v1/inventories/{$elsewhere->ulid}/items/{$case['item']->ulid}/attachments", attachmentPdf())->assertNotFound();
        attachmentUpload($this, $case['team']['owner'], "/api/v1/inventories/{$case['inventory']->ulid}/items/01j0000000000000000000000x/attachments", attachmentPdf())->assertNotFound();

        expect(storedFiles())->toBe([]);
    });

    it('moves the history of the item and the storage of the workspace', function (): void {
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['editor'], $case['files'], attachmentPdf('a.pdf'))->assertCreated();
        $attachment = Attachment::query()->sole();
        $revision = ItemRevision::query()->where('action', 'attachment_added')->sole();

        expect($revision->item_id)->toBe($case['item']->id)
            ->and($revision->user_id)->toBe($case['team']['editor']->id)
            ->and($revision->changes)->toEqual(['attachment' => $attachment->ulid, 'name' => 'a.pdf', 'field' => null])
            ->and(attachmentStorageUsed($case['team']['workspace']))->toBe($attachment->size);
    });

    it('does not touch the values of the item, nor its dates', function (): void {
        $case = attachmentCase();
        DB::table('items')->where('id', $case['item']->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        attachmentUpload($this, $case['team']['editor'], $case['files'], attachmentPdf())->assertCreated();

        expect(DB::table('items')->where('id', $case['item']->id)->value('updated_at'))->toBe('2020-01-01 00:00:00');
    });
});

describe('the limits', function (): void {
    it('refuses a file larger than the maximum, naming it, and keeps nothing', function (): void {
        config(['inventories.max_attachment_size' => 10 * 1024]);
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('big.bin', str_repeat('a', 10 * 1024 + 1)))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('exact.bin', str_repeat('a', 10 * 1024)))->assertCreated();

        expect(Attachment::query()->count())->toBe(1)
            ->and(storedFiles())->toHaveCount(1);
    });

    it('says the maximum size in the language of the request', function (): void {
        config(['inventories.max_attachment_size' => 2 * 1024 * 1024]);
        $case = attachmentCase();
        $this->useLanguage('en');

        $message = attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('big.bin', str_repeat('a', 2 * 1024 * 1024 + 1024)))
            ->assertUnprocessable()->json('errors.file.0');

        expect($message)->toBe('The file must not be larger than 2 MB.');
    });

    it('refuses the file beyond the most an item may hold, with the limit, and keeps nothing of it', function (): void {
        config(['inventories.max_attachments_per_item' => 2]);
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $refused = attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertStatus(409);

        expect($refused->json('code'))->toBe('attachment_limit_reached')
            ->and($refused->json('limit'))->toBe(2)
            ->and(Attachment::query()->count())->toBe(2)
            ->and(storedFiles())->toHaveCount(2)
            ->and(ItemRevision::query()->where('action', 'attachment_added')->count())->toBe(2);
    });

    it('counts the files of each item on its own', function (): void {
        config(['inventories.max_attachments_per_item' => 1]);
        $case = attachmentCase();
        $other = itemIn($case['inventory']);

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        attachmentUpload($this, $case['team']['owner'], "/api/v1/inventories/{$case['inventory']->ulid}/items/{$other->ulid}/attachments", attachmentPdf())->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertStatus(409);
    });

    it('frees a place when a file is removed', function (): void {
        config(['inventories.max_attachments_per_item' => 1]);
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $attachment = Attachment::query()->sole();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertStatus(409);
        inventoryCall($this, $case['team']['owner'], 'DELETE', $case['files'].'/'.$attachment->ulid)->assertOk();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
    });

    it('refuses the file that would take the workspace beyond its storage, and keeps nothing of it', function (): void {
        $size = strlen("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        config(['inventories.storage_per_workspace' => $size * 2 + 1]);
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $refused = attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertStatus(409);

        expect($refused->json('code'))->toBe('storage_limit_reached')
            ->and($refused->json('limit'))->toBe($size * 2 + 1)
            ->and(attachmentStorageUsed($case['team']['workspace']))->toBe($size * 2)
            ->and(Attachment::query()->count())->toBe(2)
            ->and(storedFiles())->toHaveCount(2);
    });

    it('lets a file take the last byte of the storage', function (): void {
        $size = strlen("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        config(['inventories.storage_per_workspace' => $size]);
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertStatus(409);

        expect(attachmentStorageUsed($case['team']['workspace']))->toBe($size);
    });

    it('judges the storage of each workspace on its own', function (): void {
        config(['inventories.storage_per_workspace' => 100]);
        $case = attachmentCase();
        $other = inventoryTeam();
        $otherItem = itemIn(inventoryIn($other['workspace']));
        $otherUri = "/api/v1/inventories/{$otherItem->inventory->ulid}/items/{$otherItem->ulid}/attachments";

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        attachmentUpload($this, $other['owner'], $otherUri, attachmentPdf())->assertCreated();
    });

    it('is judged on the counter of the workspace, which a removal brings back', function (): void {
        config(['inventories.storage_per_workspace' => 1000]);
        $case = attachmentCase();
        DB::table('workspaces')->where('id', $case['team']['workspace']->id)->update(['storage_used' => 1000]);

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('a.txt', 'x'))->assertStatus(409);

        DB::table('workspaces')->where('id', $case['team']['workspace']->id)->update(['storage_used' => 999]);

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('a.txt', 'x'))->assertCreated();
    });

    it('tells the front the limits with the list of items', function (): void {
        $case = attachmentCase();

        $limits = inventoryCall($this, $case['team']['viewer'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/items")->assertOk()->json('meta.limits');

        expect($limits)->toBe(['attachment_size' => 20 * 1024 * 1024, 'attachments_per_item' => 20]);
    });
});

describe('a failed upload', function (): void {
    it('leaves no file on the disk when the quota refuses it', function (): void {
        config(['inventories.storage_per_workspace' => 5]);
        $case = attachmentCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage())->assertStatus(409);

        expect(storedFiles())->toBe([])
            ->and(Attachment::query()->count())->toBe(0);
    });

    it('leaves no file on the disk, thumbnail included, when the item was deleted meanwhile', function (): void {
        $case = attachmentCase();
        $stale = $case['item'];
        app(Items::class)->delete($case['inventory'], $case['item']);

        expect(fn () => app(Attachments::class)->upload($case['inventory'], $stale, attachmentImage(), $case['team']['owner']))
            ->toThrow(ModelNotFoundException::class);

        expect(storedFiles())->toBe([]);
    });

    it('is a 404 over HTTP when the item is gone, and leaves nothing behind', function (): void {
        $case = attachmentCase();
        $uploads = $case['files'];
        app(Items::class)->delete($case['inventory'], $case['item']);

        attachmentUpload($this, $case['team']['owner'], $uploads, attachmentPdf())->assertNotFound();

        expect(storedFiles())->toBe([]);
    });

    it('leaves no file on the disk when the database fails', function (): void {
        $case = attachmentCase();
        Attachment::creating(fn () => throw new RuntimeException('The database is down.'));

        expect(fn () => app(Attachments::class)->upload($case['inventory'], $case['item'], attachmentImage(), $case['team']['owner']))
            ->toThrow(RuntimeException::class);

        expect(storedFiles())->toBe([])
            ->and(attachmentStorageUsed($case['team']['workspace']))->toBe(0);
    });
});

describe('downloading a file', function (): void {
    it('streams it as a download with the headers that keep it harmless', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf('Facture.pdf'))->assertCreated();
        $attachment = Attachment::query()->sole();

        $response = attachmentGet($this, $case['team']['viewer'], $case['files'].'/'.$attachment->ulid)->assertOk();

        expect($response->streamedContent())->toBe(Storage::disk(ATTACHMENT_TEST_DISK)->get($attachment->path))
            ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=Facture.pdf')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=3600')
            ->and($response->headers->get('Content-Length'))->toBe((string) $attachment->size);
    });

    it('names the file in the two forms of RFC 6266 when the name is not plain ASCII', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf('Facture été "mars" 100%.pdf'))->assertCreated();
        $attachment = Attachment::query()->sole();

        $disposition = (string) attachmentGet($this, $case['team']['owner'], $case['files'].'/'.$attachment->ulid)->headers->get('Content-Disposition');

        expect($disposition)->toStartWith('attachment; filename=')
            ->and($disposition)->toContain("filename*=utf-8''Facture%20%C3%A9t%C3%A9%20%22mars%22%20100%25.pdf")
            ->and(preg_match('/^[\x20-\x7e]*$/', explode('; filename*=', $disposition)[0]))->toBe(1)
            ->and($disposition)->not->toContain('é');
    });

    it('shows an image or a PDF in the page only when asked, and nothing else', function (string $name, string $content, bool $inline): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs($name, $content))->assertCreated();
        $attachment = Attachment::query()->sole();

        $plain = attachmentGet($this, $case['team']['owner'], $case['files'].'/'.$attachment->ulid);
        $asked = attachmentGet($this, $case['team']['owner'], $case['files'].'/'.$attachment->ulid.'?inline=1');

        expect($plain->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->and($asked->headers->get('Content-Disposition'))->toStartWith($inline ? 'inline' : 'attachment')
            ->and($asked->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($asked->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox");
    })->with([
        'png' => ['a.png', attachmentImageBytes('png'), true],
        'jpeg' => ['a.jpg', attachmentImageBytes('jpeg'), true],
        'gif' => ['a.gif', attachmentImageBytes('gif'), true],
        'webp' => ['a.webp', attachmentImageBytes('webp'), true],
        'pdf' => ['a.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF\n", true],
        'html' => ['a.html', '<!doctype html><html><body><script>alert(1)</script></body></html>', false],
        'svg' => ['a.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>', false],
        'text' => ['a.txt', 'hello', false],
    ]);

    it('serves the type it detected, whatever the extension of the name', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('evil.html', attachmentImageBytes('png'), 'text/html'))->assertCreated();
        $attachment = Attachment::query()->sole();

        $response = attachmentGet($this, $case['team']['owner'], $case['files'].'/'.$attachment->ulid.'?inline=1');

        expect($response->headers->get('Content-Type'))->toBe('image/png');
    });

    it('is readable by every member, and by nobody else', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $address = $case['files'].'/'.Attachment::query()->sole()->ulid;

        foreach (['owner', 'editor', 'viewer'] as $role) {
            attachmentGet($this, $case['team'][$role], $address)->assertOk();
        }

        attachmentGet($this, $case['team']['stranger'], $address)->assertNotFound();
        $this->flushSession();
        app('auth')->forgetGuards();
        $this->get($address, frontend())->assertUnauthorized();
    });

    it('is found through its own item and inventory only', function (): void {
        $case = attachmentCase();
        $otherItem = itemIn($case['inventory']);
        $elsewhere = inventoryIn($case['team']['workspace'], 'Elsewhere');
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $attachment = Attachment::query()->sole();
        $base = "/api/v1/inventories/{$case['inventory']->ulid}/items";

        attachmentGet($this, $case['team']['owner'], "{$base}/{$otherItem->ulid}/attachments/{$attachment->ulid}")->assertNotFound();
        attachmentGet($this, $case['team']['owner'], "/api/v1/inventories/{$elsewhere->ulid}/items/{$case['item']->ulid}/attachments/{$attachment->ulid}")->assertNotFound();
        attachmentGet($this, $case['team']['owner'], "{$base}/{$case['item']->ulid}/attachments/01j0000000000000000000000x")->assertNotFound();
        attachmentGet($this, $case['team']['owner'], "{$base}/{$case['item']->ulid}/attachments/{$attachment->ulid}/thumbnail")->assertNotFound();
        attachmentGet($this, $case['team']['owner'], "{$base}/{$case['item']->ulid}/attachments/".strtoupper($attachment->ulid))->assertOk();
    });

    it('does not reach a file of another workspace, even through an inventory the person belongs to', function (): void {
        $case = attachmentCase();
        $other = attachmentCase();
        attachmentUpload($this, $other['team']['owner'], $other['files'], attachmentPdf())->assertCreated();
        $foreign = Attachment::query()->sole();

        attachmentGet($this, $case['team']['owner'], "{$case['files']}/{$foreign->ulid}")->assertNotFound();
        attachmentGet($this, $case['team']['owner'], "{$other['files']}/{$foreign->ulid}")->assertNotFound();
    });

    it('answers a 404 in JSON when the file is no longer on the disk', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $attachment = Attachment::query()->sole();
        Storage::disk(ATTACHMENT_TEST_DISK)->delete($attachment->path);

        attachmentGet($this, $case['team']['owner'], $case['files'].'/'.$attachment->ulid)->assertNotFound()->assertJson(['code' => 'file_not_found']);
    });
});

describe('the thumbnail of a file', function (): void {
    it('is streamed as a JPEG, with the same headers', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage('photo.png', 'png', 800, 400))->assertCreated();
        $attachment = Attachment::query()->sole();

        $response = attachmentGet($this, $case['team']['viewer'], $case['files'].'/'.$attachment->ulid.'/thumbnail')->assertOk();
        $size = getimagesizefromstring($response->streamedContent());

        expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
            ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=3600')
            ->and([$size[0], $size[1]])->toBe([480, 240]);
    });

    it('is a 404 for a file that has none', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();

        attachmentGet($this, $case['team']['owner'], $case['files'].'/'.Attachment::query()->sole()->ulid.'/thumbnail')->assertNotFound()->assertJson(['code' => 'thumbnail_not_found']);
    });
});

describe('removing a file', function (): void {
    it('deletes its row, its files and its bytes in the storage of the workspace, and describes the item without it', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage())->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        [$image, $pdf] = Attachment::query()->orderBy('id')->get()->all();

        $response = inventoryCall($this, $case['team']['editor'], 'DELETE', $case['files'].'/'.$image->ulid)->assertOk();

        expect(Attachment::query()->pluck('id')->all())->toBe([$pdf->id])
            ->and($response->json('data.id'))->toBe($case['item']->ulid)
            ->and(array_column($response->json('data.attachments'), 'id'))->toBe([$pdf->ulid])
            ->and(storedFiles())->toBe([$pdf->path])
            ->and(attachmentStorageUsed($case['team']['workspace']))->toBe($pdf->size);
    });

    it('writes it in the history of the item', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf('a.pdf'))->assertCreated();
        $attachment = Attachment::query()->sole();

        inventoryCall($this, $case['team']['editor'], 'DELETE', $case['files'].'/'.$attachment->ulid)->assertOk();
        $revisions = inventoryCall($this, $case['team']['viewer'], 'GET', $case['uri'].'/revisions')->assertOk()->json('data');

        expect(array_column($revisions, 'action'))->toBe(['attachment_removed', 'attachment_added', 'created'])
            ->and($revisions[0]['user'])->toBe(['id' => $case['team']['editor']->id, 'name' => 'Editor'])
            ->and($revisions[0]['changes'])->toBe([['attachment' => ['id' => $attachment->ulid, 'name' => 'a.pdf'], 'field' => null]])
            ->and($revisions[1]['changes'])->toBe([['attachment' => ['id' => $attachment->ulid, 'name' => 'a.pdf'], 'field' => null]]);
    });

    it('is allowed to editors and owners, refused to viewers, and not there for strangers', function (): void {
        $case = attachmentCase();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $address = $case['files'].'/'.Attachment::query()->sole()->ulid;

        inventoryCall($this, $case['team']['viewer'], 'DELETE', $address)->assertForbidden();
        inventoryCall($this, $case['team']['stranger'], 'DELETE', $address)->assertNotFound();
        expect(Attachment::query()->count())->toBe(1);

        inventoryCall($this, $case['team']['owner'], 'DELETE', $address)->assertOk();
        expect(Attachment::query()->count())->toBe(0);
    });

    it('is a 404 for a file that is gone, or that belongs to another item', function (): void {
        $case = attachmentCase();
        $otherItem = itemIn($case['inventory']);
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $attachment = Attachment::query()->sole();

        inventoryCall($this, $case['team']['owner'], 'DELETE', "/api/v1/inventories/{$case['inventory']->ulid}/items/{$otherItem->ulid}/attachments/{$attachment->ulid}")->assertNotFound();
        inventoryCall($this, $case['team']['owner'], 'DELETE', $case['files'].'/'.$attachment->ulid)->assertOk();
        inventoryCall($this, $case['team']['owner'], 'DELETE', $case['files'].'/'.$attachment->ulid)->assertNotFound();

        expect(attachmentStorageUsed($case['team']['workspace']))->toBe(0);
    });

    it('is a 404 when the row was removed by a request that held the item first, and counts the bytes once', function (): void {
        $case = attachmentCase();
        $attachment = attachmentTo($case['inventory'], $case['item'], null, $case['team']['owner']);
        $stale = Attachment::query()->findOrFail($attachment->id);
        app(Attachments::class)->delete($case['inventory'], $case['item'], $attachment, $case['team']['owner']);

        expect(fn () => app(Attachments::class)->delete($case['inventory'], $case['item'], $stale, $case['team']['owner']))
            ->toThrow(ModelNotFoundException::class)
            ->and(attachmentStorageUsed($case['team']['workspace']))->toBe(0);
    });
});

describe('the storage counter of a workspace', function (): void {
    it('follows every upload and removal, and the deletion of an item with its files', function (): void {
        $case = attachmentCase();
        $first = attachmentTo($case['inventory'], $case['item'], attachmentPdf('a.pdf'));
        $second = attachmentTo($case['inventory'], $case['item'], attachmentUploadedAs('b.txt', str_repeat('b', 100)));
        $other = itemIn($case['inventory']);
        $third = attachmentTo($case['inventory'], $other, attachmentUploadedAs('c.txt', str_repeat('c', 50)));

        expect(attachmentStorageUsed($case['team']['workspace']))->toBe($first->size + $second->size + $third->size);

        app(Attachments::class)->delete($case['inventory'], $case['item'], $first, $case['team']['owner']);
        expect(attachmentStorageUsed($case['team']['workspace']))->toBe($second->size + $third->size);

        app(Items::class)->delete($case['inventory'], $case['item']);
        expect(attachmentStorageUsed($case['team']['workspace']))->toBe($third->size);
    });

    it('is not moved by a thumbnail', function (): void {
        $case = attachmentCase();

        $image = attachmentTo($case['inventory'], $case['item'], attachmentImage('a.png', 'png', 800, 800));

        expect($image->thumbnail_path)->not->toBeNull()
            ->and(attachmentStorageUsed($case['team']['workspace']))->toBe($image->size);
    });

    it('never goes below zero, and says so in the log when it would have', function (): void {
        $case = attachmentCase();
        $attachment = attachmentTo($case['inventory'], $case['item'], attachmentPdf(), $case['team']['owner']);
        DB::table('workspaces')->where('id', $case['team']['workspace']->id)->update(['storage_used' => 1]);
        Log::spy();

        app(Attachments::class)->delete($case['inventory'], $case['item'], $attachment, $case['team']['owner']);

        expect(attachmentStorageUsed($case['team']['workspace']))->toBe(0);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'below zero')
            && $context['workspace_id'] === $case['team']['workspace']->id
            && $context['stored'] === 1);
    });

    it('does not touch the dates of the workspace', function (): void {
        $case = attachmentCase();
        DB::table('workspaces')->where('id', $case['team']['workspace']->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        attachmentTo($case['inventory'], $case['item'], null, $case['team']['owner']);

        expect(DB::table('workspaces')->where('id', $case['team']['workspace']->id)->value('updated_at'))->toBe('2020-01-01 00:00:00');
    });
});

describe('the item', function (): void {
    it('lists its files, oldest first, in the list of items and when read alone', function (): void {
        $case = attachmentCase();
        $first = attachmentTo($case['inventory'], $case['item'], attachmentPdf('a.pdf'));
        $second = attachmentTo($case['inventory'], $case['item'], attachmentImage('b.png'));
        $empty = itemIn($case['inventory']);

        $one = inventoryCall($this, $case['team']['viewer'], 'GET', $case['uri'])->assertOk()->json('data');
        $list = inventoryCall($this, $case['team']['viewer'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/items")->assertOk()->json('data');
        $listed = collect($list)->keyBy('id');

        expect(array_column($one['attachments'], 'id'))->toBe([$first->ulid, $second->ulid])
            ->and(array_column($listed[$case['item']->ulid]['attachments'], 'id'))->toBe([$first->ulid, $second->ulid])
            ->and($listed[$empty->ulid]['attachments'])->toBe([])
            ->and($one['attachments'][1]['has_thumbnail'])->toBeTrue()
            ->and($one['attachments'][1]['thumbnail_url'])->toBe($case['files'].'/'.$second->ulid.'/thumbnail')
            ->and(array_keys($one))->toBe(['id', 'values', 'attachments', 'created_at', 'updated_at']);
    });

    it('names who uploaded each file, and nobody once their account is deleted', function (): void {
        $case = attachmentCase();
        attachmentTo($case['inventory'], $case['item'], attachmentPdf('a.pdf'), $case['team']['editor']);
        attachmentTo($case['inventory'], $case['item'], attachmentPdf('b.pdf'), $case['team']['owner']);

        $uploaders = fn (): array => array_column(inventoryCall($this, $case['team']['viewer'], 'GET', $case['uri'])->assertOk()->json('data.attachments'), 'uploaded_by');

        expect($uploaders())->toBe([['id' => $case['team']['editor']->id, 'name' => 'Editor'], ['id' => $case['team']['owner']->id, 'name' => 'Owner']]);

        $case['team']['editor']->delete();

        expect($uploaders())->toBe([null, ['id' => $case['team']['owner']->id, 'name' => 'Owner']])
            ->and(Attachment::query()->count())->toBe(2);
    });

    it('answers the creation of an item, the upload and the removal of a file in the standard envelope', function (): void {
        $case = attachmentCase();

        $created = inventoryCall($this, $case['team']['owner'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/items", ['values' => []])->assertCreated();
        $uploaded = attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        $removed = inventoryCall($this, $case['team']['owner'], 'DELETE', $case['files'].'/'.Attachment::query()->sole()->ulid)->assertOk();

        foreach ([$created, $uploaded, $removed] as $response) {
            expect(array_keys($response->json()))->toBe(['data'])
                ->and(array_keys($response->json('data')))->toBe(['id', 'values', 'attachments', 'created_at', 'updated_at']);
        }
    });

    it('loads the files of a whole page in one more query, and the names of those who uploaded them in another', function (): void {
        $case = attachmentCase();

        foreach (range(1, 2) as $_) {
            attachmentTo($case['inventory'], itemIn($case['inventory']));
        }

        $list = fn (): array => statementsOf(fn () => inventoryCall($this, $case['team']['viewer'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/items")->assertOk());
        $reading = fn (string $table): int => count(array_filter($list(), fn (string $sql): bool => str_contains($sql, "from \"{$table}\"") || str_contains($sql, "from `{$table}`")));
        $files = $reading('attachments');
        $names = $reading('users');

        foreach (range(1, 5) as $_) {
            attachmentTo($case['inventory'], itemIn($case['inventory']), null, inventoryPerson('Another'));
        }

        expect($files)->toBe(1)
            ->and($reading('attachments'))->toBe(1)
            ->and($reading('users'))->toBe($names);
    });

    it('has the files of an item that was just changed', function (): void {
        $case = attachmentCase();
        $name = fieldIn($case['inventory'], 'text', 'Name');
        $attachment = attachmentTo($case['inventory'], $case['item']);

        $response = inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$name->ulid => 'Hammer']])->assertOk();

        expect(array_column($response->json('data.attachments'), 'id'))->toBe([$attachment->ulid]);
    });
});

describe('the disk', function (): void {
    it('is the default disk of the application when none is configured', function (): void {
        config(['inventories.attachments_disk' => null, 'filesystems.default' => 's3']);

        expect(app(AttachmentStorage::class)->diskName())->toBe('s3');
    });

    it('is the one configured', function (): void {
        config(['inventories.attachments_disk' => 'elsewhere', 'filesystems.default' => 'local']);

        expect(app(AttachmentStorage::class)->diskName())->toBe('elsewhere');
    });

    it('is never the public one: the private local disk is used instead, and the refusal is logged', function (): void {
        Log::spy();
        config(['inventories.attachments_disk' => 'public']);

        expect(app(AttachmentStorage::class)->diskName())->toBe('local');

        config(['inventories.attachments_disk' => null, 'filesystems.default' => 'public']);

        expect(app(AttachmentStorage::class)->diskName())->toBe('local');
        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(fn (string $message): bool => str_contains($message, 'public disk'));
    });

    it('writes to the disk the file will be read from later: the name of the disk is kept with the file', function (): void {
        $case = attachmentCase();
        $attachment = attachmentTo($case['inventory'], $case['item']);

        config(['inventories.attachments_disk' => 'another-disk']);

        expect($attachment->disk)->toBe(ATTACHMENT_TEST_DISK);
        attachmentGet($this, $case['team']['owner'], $case['files'].'/'.$attachment->ulid)->assertOk();
    });
});
