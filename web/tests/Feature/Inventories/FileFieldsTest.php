<?php

declare(strict_types=1);

use App\Inventories\Attachments\Attachments;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\PhotoType;
use App\Inventories\Fields\QueryKind;
use App\Inventories\InventoryFields;
use App\Models\Attachment;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\ItemRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/attachments.php';

/*
 * The file and photo fields: files that are uploaded to the field, whose value is the list of their attachments. What the
 * types say about themselves, their one option, what they refuse (a value sent with the item, `required`, a file that is not
 * an image in a photo field), how the upload fills the value and the removal empties it, and what the list can do with them
 * (filter on `empty`, nothing else). The files themselves are in ItemAttachmentsTest.php.
 */

uses(RefreshDatabase::class);

/**
 * An item with a photo field (three files at most) and a file field (the default one), for a team.
 *
 * @return array{team: array<string, mixed>, inventory: Model, item: Item, photos: InventoryField, documents: InventoryField, name: InventoryField, uri: string, files: string}
 */
function fileFieldsCase(int $maxPhotos = 3): array
{
    $case = attachmentCase();
    $photos = fieldIn($case['inventory'], 'photo', 'Photos', ['max_files' => $maxPhotos]);
    $documents = fieldIn($case['inventory'], 'file', 'Documents');
    $name = fieldIn($case['inventory'], 'text', 'Name');

    return $case + ['photos' => $photos, 'documents' => $documents, 'name' => $name];
}

/**
 * The stored value of a field of an item, as the database holds it.
 */
function fileFieldValue(Item $item, InventoryField $field): mixed
{
    return Item::query()->findOrFail($item->id)->values[$field->ulid] ?? null;
}

describe('the types', function (): void {
    it('are in the registry, with their label in the language of the request', function (): void {
        $types = app(FieldTypes::class);
        $this->useLanguage('en');

        expect($types->get('file')->label())->toBe('Attached file')
            ->and($types->get('photo')->label())->toBe('Photo')
            ->and($types->get('photo'))->toBeInstanceOf(PhotoType::class);

        $this->useLanguage('fr');

        expect($types->get('file')->label())->toBe('Fichier joint')
            ->and($types->get('photo')->label())->toBe('Photo');
    });

    it('say that their files are uploaded, and no other type does', function (): void {
        $uploaded = [];

        foreach (app(FieldTypes::class)->all() as $type) {
            if ($type->filledByUpload()) {
                $uploaded[] = $type->key();
            }
        }

        expect($uploaded)->toBe(['file', 'photo']);
    });

    it('cannot be sorted or added up, are filtered on being empty only, and add nothing to the search', function (string $key): void {
        $type = app(FieldTypes::class)->get($key);
        $field = new InventoryField(['name' => 'Files', 'type' => $key, 'options' => $type->normalizeOptions([], null)]);

        expect($type->queryKind())->toBe(QueryKind::Files)
            ->and($type->queryKind()->sortable())->toBeFalse()
            ->and($type->summable())->toBeFalse()
            ->and($type->searchText(['01j0000000000000000000000x'], $field))->toBeNull();
    })->with(['file', 'photo']);

    it('take images only for a photo, anything for a file', function (): void {
        expect(app(FieldTypes::class)->get('photo')->imagesOnly())->toBeTrue()
            ->and(app(FieldTypes::class)->get('file')->imagesOnly())->toBeFalse();
    });

    it('show the ULIDs of the files and nothing else, empty when there are none', function (string $key): void {
        $type = app(FieldTypes::class)->get($key);
        $field = new InventoryField(['name' => 'Files', 'type' => $key]);
        $ulid = '01j0000000000000000000000x';

        expect($type->present([$ulid, $ulid], $field))->toBe([$ulid, $ulid])
            ->and($type->present([$ulid, 5, 'not a ulid', null], $field))->toBe([$ulid])
            ->and($type->present('text', $field))->toBeNull()
            ->and($type->isEmpty($type->present([], $field)))->toBeTrue()
            ->and($type->isEmpty($type->present(null, $field)))->toBeTrue();
    })->with(['file', 'photo']);
});

describe('the option max_files', function (): void {
    it('is 1 unless the field says otherwise', function (string $type): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $field = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => $type, 'name' => 'Files'])->assertCreated();
        $nulled = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => $type, 'name' => 'More files', 'options' => ['max_files' => null]])->assertCreated();

        expect($field->json('data.options'))->toBe(['max_files' => 1])
            ->and($nulled->json('data.options'))->toBe(['max_files' => 1]);
    })->with(['file', 'photo']);

    it('is kept as sent, from 1 to 10', function (string $type, int $sent): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        $response = inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => $type, 'name' => 'Files', 'options' => ['max_files' => $sent]])->assertCreated();

        expect($response->json('data.options'))->toBe(['max_files' => $sent]);
    })->with([['file', 1], ['file', 10], ['photo', 4]]);

    it('is refused outside 1 to 10, and when it is not a whole number', function (mixed $sent): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => 'photo', 'name' => 'Files', 'options' => ['max_files' => $sent]])
            ->assertUnprocessable()->assertJsonValidationErrors('options.max_files');
    })->with([0, 11, -1, 2.5, 'many']);

    it('is the only option: the options of other types are refused', function (): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => 'file', 'name' => 'Files', 'options' => ['max_length' => 10]])
            ->assertUnprocessable()->assertJsonValidationErrors('options.max_length');
    });

    it('can be changed, and lowering it removes no file and leaves the value as it is', function (): void {
        $case = fileFieldsCase(3);
        attachmentTo($case['inventory'], $case['item'], attachmentImage(), field: $case['photos']);
        attachmentTo($case['inventory'], $case['item'], attachmentImage('b.png'), field: $case['photos']);
        $before = fileFieldValue($case['item'], $case['photos']);

        $response = inventoryCall($this, $case['team']['owner'], 'PATCH', "/api/v1/inventories/{$case['inventory']->ulid}/fields/{$case['photos']->ulid}", ['options' => ['max_files' => 1]])->assertOk();

        expect($response->json('data.options'))->toBe(['max_files' => 1])
            ->and(fileFieldValue($case['item'], $case['photos']))->toBe($before)
            ->and($before)->toHaveCount(2)
            ->and(Attachment::query()->count())->toBe(2);
    });

    it('never makes the values of the field invalid', function (): void {
        $type = app(FieldTypes::class)->get('photo');

        expect($type->mayInvalidateValues(['max_files' => 5], ['max_files' => 1]))->toBeFalse()
            ->and($type->mayChangeSearchText(['max_files' => 5], ['max_files' => 1]))->toBeFalse();
    });
});

describe('a field that is required', function (): void {
    it('cannot be a file or photo field, when it is added', function (string $type): void {
        $team = inventoryTeam();
        $inventory = inventoryIn($team['workspace']);

        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => $type, 'name' => 'Files', 'required' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('required');
        inventoryCall($this, $team['owner'], 'POST', "/api/v1/inventories/{$inventory->ulid}/fields", ['type' => $type, 'name' => 'Files', 'required' => false])->assertCreated();

        expect(InventoryField::query()->count())->toBe(1);
    })->with(['file', 'photo']);

    it('cannot be made so afterwards, but can be made not to be', function (): void {
        $case = fileFieldsCase();
        $uri = "/api/v1/inventories/{$case['inventory']->ulid}/fields/{$case['documents']->ulid}";

        inventoryCall($this, $case['team']['owner'], 'PATCH', $uri, ['required' => true])->assertUnprocessable()->assertJsonValidationErrors('required');
        inventoryCall($this, $case['team']['owner'], 'PATCH', $uri, ['required' => false, 'name' => 'Papers'])->assertOk()->assertJsonPath('data.required', false)->assertJsonPath('data.name', 'Papers');

        expect($case['documents']->fresh()?->required)->toBeFalse();
    });

    it('is refused by the service too, and a field that was made required by hand never stops an item', function (): void {
        $case = fileFieldsCase();

        expect(fn () => app(InventoryFields::class)->create($case['inventory'], 'photo', 'More', [], true))->toThrow(ValidationException::class);

        DB::table('inventory_fields')->where('id', $case['photos']->id)->update(['required' => true]);

        inventoryCall($this, $case['team']['owner'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/items", ['values' => [$case['name']->ulid => 'Hammer']])->assertCreated();
    });
});

describe('an item', function (): void {
    it('cannot be created with a value for a file or photo field, whatever it is', function (mixed $value): void {
        $case = fileFieldsCase();
        $uri = "/api/v1/inventories/{$case['inventory']->ulid}/items";

        $response = inventoryCall($this, $case['team']['owner'], 'POST', $uri, ['values' => [$case['photos']->ulid => $value, $case['name']->ulid => 'Hammer']])->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toBe(["values.{$case['photos']->ulid}"])
            ->and($response->json('errors')["values.{$case['photos']->ulid}"][0])->toBe('This field is filled by uploading files.')
            ->and(Item::query()->count())->toBe(1);
    })->with([
        'a list' => [['01j0000000000000000000000x']],
        'an empty list' => [[]],
        'null' => [null],
        'a text' => ['photo.png'],
        'a number' => [3],
    ]);

    it('cannot be changed with a value for a file or photo field either, and nothing else of the request is applied', function (): void {
        $case = fileFieldsCase();
        $attachment = attachmentTo($case['inventory'], $case['item'], attachmentPdf(), field: $case['documents']);

        $response = inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$case['documents']->ulid => [], $case['name']->ulid => 'Hammer']])->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toBe(["values.{$case['documents']->ulid}"])
            ->and(fileFieldValue($case['item'], $case['documents']))->toBe([$attachment->ulid])
            ->and(fileFieldValue($case['item'], $case['name']))->toBeNull();
    });

    it('refuses the key whatever the case of the ULID, naming it as it was sent', function (): void {
        $case = fileFieldsCase();
        $key = strtoupper($case['photos']->ulid);

        $response = inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$key => []]])->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toBe(["values.{$key}"]);
    });

    it('keeps the files of its fields when its other values change', function (): void {
        $case = fileFieldsCase();
        $attachment = attachmentTo($case['inventory'], $case['item'], attachmentImage(), field: $case['photos']);

        inventoryCall($this, $case['team']['owner'], 'PATCH', $case['uri'], ['values' => [$case['name']->ulid => 'Hammer']])->assertOk()
            ->assertJsonPath('data.values', [$case['photos']->ulid => [$attachment->ulid], $case['name']->ulid => 'Hammer']);

        expect(fileFieldValue($case['item'], $case['photos']))->toBe([$attachment->ulid])
            ->and(ItemRevision::query()->where('action', 'updated')->sole()->changes)->toEqual([$case['name']->ulid => ['before' => null, 'after' => 'Hammer']]);
    });

    it('can be created without any value for them, and has none', function (): void {
        $case = fileFieldsCase();

        $created = inventoryCall($this, $case['team']['owner'], 'POST', "/api/v1/inventories/{$case['inventory']->ulid}/items", ['values' => [$case['name']->ulid => 'Hammer']])->assertCreated();

        expect($created->json('data.values'))->toBe([$case['name']->ulid => 'Hammer'])
            ->and($created->json('data.attachments'))->toBe([]);
    });
});

describe('uploading a file to a field', function (): void {
    it('adds its ULID to the value of the field, in the order the files come, and links the file to the field', function (): void {
        $case = fileFieldsCase();

        $first = attachmentUpload($this, $case['team']['editor'], $case['files'], attachmentImage('a.png'), ['field' => $case['photos']->ulid])->assertCreated();
        $second = attachmentUpload($this, $case['team']['editor'], $case['files'], attachmentImage('b.png'), ['field' => $case['photos']->ulid])->assertCreated();
        [$a, $b] = Attachment::query()->orderBy('id')->get()->all();

        expect($a->field_id)->toBe($case['photos']->id)
            ->and($first->json("data.values.{$case['photos']->ulid}"))->toBe([$a->ulid])
            ->and($second->json("data.values.{$case['photos']->ulid}"))->toBe([$a->ulid, $b->ulid])
            ->and(array_column($second->json('data.attachments'), 'field'))->toBe([$case['photos']->ulid, $case['photos']->ulid])
            ->and(fileFieldValue($case['item'], $case['photos']))->toBe([$a->ulid, $b->ulid])
            ->and($a->thumbnail_path)->not->toBeNull();
    });

    it('keeps the files of each field apart, and the plain attachments out of the fields', function (): void {
        $case = fileFieldsCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $case['photos']->ulid])->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf(), ['field' => $case['documents']->ulid])->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf('plain.pdf'))->assertCreated();
        [$photo, $document, $plain] = Attachment::query()->orderBy('id')->get()->all();
        $values = Item::query()->findOrFail($case['item']->id)->values;

        expect($values)->toEqual([$case['photos']->ulid => [$photo->ulid], $case['documents']->ulid => [$document->ulid]])
            ->and($plain->field_id)->toBeNull();
    });

    it('takes the ULID of the field in any case', function (): void {
        $case = fileFieldsCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => strtoupper($case['photos']->ulid)])->assertCreated();

        expect(Attachment::query()->sole()->field_id)->toBe($case['photos']->id);
    });

    it('touches the item (its date and who changed it) and writes the file in its history, with the field', function (): void {
        $case = fileFieldsCase();
        DB::table('items')->where('id', $case['item']->id)->update(['updated_at' => '2020-01-01 00:00:00', 'updated_by' => null]);

        attachmentUpload($this, $case['team']['editor'], $case['files'], attachmentImage('a.png'), ['field' => $case['photos']->ulid])->assertCreated();
        $item = DB::table('items')->where('id', $case['item']->id)->first();
        $revision = ItemRevision::query()->where('action', 'attachment_added')->sole();

        expect($item->updated_at)->not->toBe('2020-01-01 00:00:00')
            ->and((int) $item->updated_by)->toBe($case['team']['editor']->id)
            ->and($revision->changes)->toEqual(['attachment' => Attachment::query()->sole()->ulid, 'name' => 'a.png', 'field' => $case['photos']->ulid]);
    });

    it('leaves the text the item is searched by as it was: files add nothing to it', function (): void {
        $case = fileFieldsCase();
        itemIn($case['inventory']);
        DB::table('items')->where('id', $case['item']->id)->update(['search_text' => 'unchanged']);

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf(), ['field' => $case['documents']->ulid])->assertCreated();

        expect(DB::table('items')->where('id', $case['item']->id)->value('search_text'))->toBe('unchanged');
    });

    it('is refused beyond the files the field takes, with the limit, and keeps nothing of it', function (): void {
        $case = fileFieldsCase(2);

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $case['photos']->ulid])->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $case['photos']->ulid])->assertCreated();
        $refused = attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $case['photos']->ulid])->assertStatus(409);

        expect($refused->json('code'))->toBe('field_file_limit_reached')
            ->and($refused->json('limit'))->toBe(2)
            ->and(Attachment::query()->count())->toBe(2)
            ->and(storedFiles())->toHaveCount(4)
            ->and(fileFieldValue($case['item'], $case['photos']))->toHaveCount(2);

        // The other field, and a plain attachment, are not concerned.
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf(), ['field' => $case['documents']->ulid])->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
    });

    it('takes one file by default and refuses a second one', function (): void {
        $case = fileFieldsCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf(), ['field' => $case['documents']->ulid])->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf(), ['field' => $case['documents']->ulid])->assertStatus(409)->assertJson(['code' => 'field_file_limit_reached', 'limit' => 1]);
    });

    it('is refused once a field was brought under the files it already holds, until one is removed', function (): void {
        $case = fileFieldsCase(3);
        attachmentTo($case['inventory'], $case['item'], attachmentImage(), field: $case['photos']);
        attachmentTo($case['inventory'], $case['item'], attachmentImage(), field: $case['photos']);
        app(InventoryFields::class)->update($case['inventory'], $case['photos'], ['options' => ['max_files' => 1]]);

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $case['photos']->ulid])->assertStatus(409);

        $first = Attachment::query()->orderBy('id')->firstOrFail();
        inventoryCall($this, $case['team']['owner'], 'DELETE', $case['files'].'/'.$first->ulid)->assertOk();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $case['photos']->ulid])->assertStatus(409);

        $second = Attachment::query()->orderBy('id')->firstOrFail();
        inventoryCall($this, $case['team']['owner'], 'DELETE', $case['files'].'/'.$second->ulid)->assertOk();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $case['photos']->ulid])->assertCreated();
    });

    it('counts against the files an item may hold, the files of its fields included', function (): void {
        config(['inventories.max_attachments_per_item' => 2]);
        $case = fileFieldsCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $case['photos']->ulid])->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf())->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentPdf(), ['field' => $case['documents']->ulid])->assertStatus(409)->assertJson(['code' => 'attachment_limit_reached', 'limit' => 2]);

        expect(fileFieldValue($case['item'], $case['documents']))->toBeNull();
    });

    it('is refused for a field that does not exist, is not a file or photo field, or is in another inventory', function (string $which): void {
        $case = fileFieldsCase();
        $elsewhere = fieldIn(inventoryIn($case['team']['workspace'], 'Elsewhere'), 'photo', 'Photos');
        $field = ['missing' => '01j0000000000000000000000x', 'text' => $case['name']->ulid, 'other inventory' => $elsewhere->ulid][$which];

        $response = attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $field])->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toBe(['field'])
            ->and(Attachment::query()->count())->toBe(0)
            ->and(storedFiles())->toBe([]);
    })->with(['missing', 'text', 'other inventory']);

    it('is refused to a viewer, and a viewer cannot fill a field either', function (): void {
        $case = fileFieldsCase();

        attachmentUpload($this, $case['team']['viewer'], $case['files'], attachmentImage(), ['field' => $case['photos']->ulid])->assertForbidden();

        expect(Attachment::query()->count())->toBe(0);
    });

    it('is refused when the field is gone, and leaves no file', function (): void {
        $case = fileFieldsCase();
        $stale = $case['inventory'];
        $photos = $case['photos'];

        // The field is deleted before the transaction locks; the request had looked at it before.
        $service = app(Attachments::class);
        app(InventoryFields::class)->delete($case['inventory'], $photos);

        expect(fn () => $service->upload($stale, $case['item'], attachmentImage(), $case['team']['owner'], $photos->ulid))
            ->toThrow(ValidationException::class);

        expect(storedFiles())->toBe([]);
    });
});

describe('a photo field', function (): void {
    it('takes JPEG, PNG, WebP and GIF images, and makes their thumbnails', function (string $format): void {
        $case = fileFieldsCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage('photo.'.$format, $format, 600, 300), ['field' => $case['photos']->ulid])->assertCreated();
        $attachment = Attachment::query()->sole();

        expect($attachment->mime)->toBe('image/'.$format)
            ->and($attachment->thumbnail_path)->not->toBeNull()
            ->and([$attachment->width, $attachment->height])->toBe([600, 300]);
    })->with(['jpeg', 'png', 'gif', 'webp']);

    it('refuses what is not an image of those types, in the file part, and keeps nothing', function (string $name, string $content): void {
        $case = fileFieldsCase();

        $response = attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs($name, $content, 'image/png'), ['field' => $case['photos']->ulid])->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toBe(['file'])
            ->and($response->json('errors.file.0'))->toBe('This field only takes images: JPEG, PNG, WebP or GIF.')
            ->and(Attachment::query()->count())->toBe(0)
            ->and(storedFiles())->toBe([])
            ->and(fileFieldValue($case['item'], $case['photos']))->toBeNull();
    })->with([
        'a pdf called a png' => ['photo.png', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF\n"],
        'a script' => ['photo.png', "<?php echo 'hello';\n"],
        'an svg' => ['logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>'],
        'a text' => ['photo.jpg', 'just words'],
    ]);

    it('refuses an image that cannot be read, in the file part', function (): void {
        $case = fileFieldsCase();
        $broken = substr(attachmentImageBytes('png', 40, 20), 0, 33).random_bytes(200);

        $response = attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('photo.png', $broken), ['field' => $case['photos']->ulid])->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toBe(['file'])
            ->and($response->json('errors.file.0'))->toBe('This image could not be read.')
            ->and(Attachment::query()->count())->toBe(0)
            ->and(storedFiles())->toBe([]);
    });

    it('refuses an image whose header cannot be read', function (): void {
        $case = fileFieldsCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('photo.png', substr(attachmentImageBytes('png', 40, 20), 0, 12)), ['field' => $case['photos']->ulid])->assertUnprocessable()->assertJsonValidationErrors('file');
    });

    it('keeps an image that has too many pixels to get a thumbnail: it is readable', function (): void {
        $case = fileFieldsCase();
        $png = attachmentImageBytes('png', 4, 4);
        $huge = substr($png, 0, 16).pack('NN', 8000, 6000).substr($png, 24);

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('huge.png', $huge), ['field' => $case['photos']->ulid])->assertCreated();
        $attachment = Attachment::query()->sole();

        expect($attachment->thumbnail_path)->toBeNull()
            ->and([$attachment->width, $attachment->height])->toBe([8000, 6000])
            ->and(fileFieldValue($case['item'], $case['photos']))->toBe([$attachment->ulid]);
    });

    it('is not what a file field is: a file field takes anything', function (): void {
        $case = fileFieldsCase();

        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentUploadedAs('notes.txt', 'words'), ['field' => $case['documents']->ulid])->assertCreated();
        attachmentUpload($this, $case['team']['owner'], $case['files'], attachmentImage(), ['field' => $case['documents']->ulid])->assertStatus(409);

        expect(Attachment::query()->count())->toBe(1);
    });
});

describe('removing a file of a field', function (): void {
    it('takes its ULID out of the value and the key away once none is left, and touches the item', function (): void {
        $case = fileFieldsCase();
        $first = attachmentTo($case['inventory'], $case['item'], attachmentImage('a.png'), field: $case['photos']);
        $second = attachmentTo($case['inventory'], $case['item'], attachmentImage('b.png'), field: $case['photos']);
        DB::table('items')->where('id', $case['item']->id)->update(['updated_at' => '2020-01-01 00:00:00', 'updated_by' => null]);

        $removed = inventoryCall($this, $case['team']['editor'], 'DELETE', $case['files'].'/'.$first->ulid)->assertOk();

        expect(fileFieldValue($case['item'], $case['photos']))->toBe([$second->ulid])
            ->and($removed->json("data.values.{$case['photos']->ulid}"))->toBe([$second->ulid])
            ->and((int) DB::table('items')->where('id', $case['item']->id)->value('updated_by'))->toBe($case['team']['editor']->id)
            ->and(DB::table('items')->where('id', $case['item']->id)->value('updated_at'))->not->toBe('2020-01-01 00:00:00');

        $last = inventoryCall($this, $case['team']['editor'], 'DELETE', $case['files'].'/'.$second->ulid)->assertOk();

        expect(Item::query()->findOrFail($case['item']->id)->values)->toBe([])
            ->and($last->json('data.values'))->toBe([]);
    });

    it('writes it in the history with the field, and shows the field as gone once it is deleted', function (): void {
        $case = fileFieldsCase();
        $attachment = attachmentTo($case['inventory'], $case['item'], attachmentPdf('a.pdf'), field: $case['documents']);

        inventoryCall($this, $case['team']['owner'], 'DELETE', $case['files'].'/'.$attachment->ulid)->assertOk();
        $history = fn (): array => inventoryCall($this, $case['team']['viewer'], 'GET', $case['uri'].'/revisions')->assertOk()->json('data');

        expect($history()[0]['action'])->toBe('attachment_removed')
            ->and($history()[0]['changes'])->toBe([['attachment' => ['id' => $attachment->ulid, 'name' => 'a.pdf'], 'field' => ['id' => $case['documents']->ulid, 'name' => 'Documents', 'type' => 'file']]]);

        app(InventoryFields::class)->delete($case['inventory'], $case['documents']);

        expect($history()[0]['changes'])->toBe([['attachment' => ['id' => $attachment->ulid, 'name' => 'a.pdf'], 'field' => null]])
            ->and($history()[1]['changes'][0]['field'])->toBeNull();
    });

    it('leaves the files of the other fields alone', function (): void {
        $case = fileFieldsCase();
        $photo = attachmentTo($case['inventory'], $case['item'], attachmentImage(), field: $case['photos']);
        $document = attachmentTo($case['inventory'], $case['item'], attachmentPdf(), field: $case['documents']);

        inventoryCall($this, $case['team']['owner'], 'DELETE', $case['files'].'/'.$photo->ulid)->assertOk();

        expect(Item::query()->findOrFail($case['item']->id)->values)->toEqual([$case['documents']->ulid => [$document->ulid]]);
    });
});

describe('the list of items', function (): void {
    it('filters on whether a file or photo field holds a file, with empty and nothing else', function (): void {
        $case = fileFieldsCase();
        $with = $case['item'];
        $without = itemIn($case['inventory']);
        attachmentTo($case['inventory'], $with, attachmentImage(), field: $case['photos']);
        $uri = "/api/v1/inventories/{$case['inventory']->ulid}/items";
        $ids = fn (string $empty): array => array_column(inventoryCall($this, $case['team']['viewer'], 'GET', $uri.'?'.http_build_query(['filter' => [$case['photos']->ulid => ['empty' => $empty]]]))->assertOk()->json('data'), 'id');

        expect($ids('true'))->toBe([$without->ulid])
            ->and($ids('false'))->toBe([$with->ulid]);

        inventoryCall($this, $case['team']['viewer'], 'GET', $uri.'?'.http_build_query(['filter' => [$case['photos']->ulid => ['min' => '1']]]))->assertUnprocessable()->assertJsonValidationErrors("filter.{$case['photos']->ulid}.min");
        inventoryCall($this, $case['team']['viewer'], 'GET', $uri.'?'.http_build_query(['filter' => [$case['photos']->ulid => ['in' => ['x']]]]))->assertUnprocessable();
    });

    it('does not sort by a file or photo field, nor add it up', function (): void {
        $case = fileFieldsCase();
        $uri = "/api/v1/inventories/{$case['inventory']->ulid}/items";

        inventoryCall($this, $case['team']['viewer'], 'GET', $uri.'?sort='.$case['documents']->ulid)->assertUnprocessable()->assertJsonValidationErrors('sort');
        inventoryCall($this, $case['team']['viewer'], 'GET', $uri.'?'.http_build_query(['totals' => [$case['documents']->ulid]]))->assertUnprocessable()->assertJsonValidationErrors('totals.0');
    });

    it('shows the files of the field in the values of each item', function (): void {
        $case = fileFieldsCase();
        $attachment = attachmentTo($case['inventory'], $case['item'], attachmentImage(), field: $case['photos']);

        $item = inventoryCall($this, $case['team']['viewer'], 'GET', "/api/v1/inventories/{$case['inventory']->ulid}/items")->assertOk()->json('data.0');

        expect($item['values'])->toBe([$case['photos']->ulid => [$attachment->ulid]])
            ->and($item['attachments'][0]['field'])->toBe($case['photos']->ulid)
            ->and($item['attachments'][0]['id'])->toBe($attachment->ulid);
    });
});

describe('the files of a field in storage', function (): void {
    it('are on the disk under the folder of the item, whatever the field', function (): void {
        $case = fileFieldsCase();
        $photo = attachmentTo($case['inventory'], $case['item'], attachmentImage(), field: $case['photos']);

        expect(Storage::disk(ATTACHMENT_TEST_DISK)->exists($photo->path))->toBeTrue()
            ->and($photo->path)->toStartWith("workspaces/{$case['team']['workspace']->ulid}/inventories/{$case['inventory']->ulid}/items/{$case['item']->ulid}/");
    });
});
