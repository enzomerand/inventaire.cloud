<?php

declare(strict_types=1);

namespace App\Inventories\Sharing;

use App\Http\Responses\StoredFileResponse;
use App\Inventories\Exports\ExportFormatter;
use App\Inventories\Exports\ExportTable;
use App\Inventories\Fields\ChoiceType;
use App\Inventories\Fields\ExportValue;
use App\Inventories\Fields\FieldType;
use App\Inventories\Fields\FieldTypes;
use App\Inventories\Fields\PhotoType;
use App\Inventories\Fields\UploadedFilesType;
use App\Inventories\Items;
use App\Inventories\ItemSearchText;
use App\Inventories\ItemValues;
use App\Inventories\Templates\TemplatePresenter;
use App\Models\Attachment;
use App\Models\InventoryField;
use App\Models\Item;
use App\Models\ShareLink;
use App\Support\DisplayTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Number;

/**
 * The inventory a share link shows, as the public page draws it: a page of its items, newest first, matching the words of a search, with
 * the values written the way a person reads them.
 *
 * It reads and shows nothing but what the link is for. The columns are the fields of the inventory (the photo and file fields only when the
 * link shows the files, see {@see ShareLink::$include_files}); an item is its values and, for the file fields, its files, and nothing else:
 * not who made or changed it, not its history, not who uploaded a file. Values are formatted by the same code as the exports
 * ({@see ExportTable}, {@see ExportFormatter}: the language of the request, the timezone of the instance), plus what only a page needs: a
 * choice or a tag is a badge, an address a link, a rating stars, a color a swatch. Links are only ever to a web address (`http:` or `https:`),
 * a mail address or a phone number; a value that looks like anything else is shown as text.
 *
 * The files are linked through the routes of the link (`share.attachment`, `share.thumbnail`), which check the link again at each request;
 * the token they carry is the one the visitor already holds.
 *
 * There is no sort, no filter and no total: only `page` and the words of a search.
 */
final class SharedInventory
{
    /** Most characters of a search; the rest of what was typed is ignored. */
    public const SEARCH_MAX_LENGTH = 100;

    /** The last page a visitor can ask for (a larger number is the last one). */
    public const MAX_PAGE = 1_000_000;

    /** The images a photo field takes: the ones that have an icon of their own when no thumbnail could be made. */
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Gets the registry that names the types, the checks of values and the search of items.
     */
    public function __construct(
        private readonly FieldTypes $types,
        private readonly ItemValues $values,
        private readonly ItemSearchText $search,
    ) {}

    /**
     * The text of a search as it is used: valid UTF-8 and at most {@see self::SEARCH_MAX_LENGTH} characters.
     */
    public static function cleanSearch(string $typed): string
    {
        return mb_substr(trim(mb_scrub($typed)), 0, self::SEARCH_MAX_LENGTH);
    }

    /**
     * A page of the items of the inventory of a link.
     *
     * @param  ShareLink  $link  A link that works, with its inventory loaded.
     * @param  string  $token  Its token, which the addresses of the files carry.
     * @param  string  $search  The words the visitor typed ('' for none).
     * @param  int  $page  The page asked for, from 1; one past the last page is the last page.
     */
    public function page(ShareLink $link, string $token, string $search = '', int $page = 1): SharedPage
    {
        $inventory = $link->inventory;
        $fields = $inventory->fields()->get();

        if (! $link->include_files) {
            $fields = $fields->reject(fn (InventoryField $field): bool => $this->types->of($field) instanceof UploadedFilesType)->values();
        }

        $table = new ExportTable($fields, $this->types, $this->values);
        $search = self::cleanSearch($search);

        $items = $inventory->items()->orderByDesc('items.id');

        if ($table->hasFileFields()) {
            // What a cell needs of a file, and nothing that tells where it is kept or who put it there: in one query for the whole page.
            $items->with(['attachments' => fn (Relation $files) => $files->select(['id', 'ulid', 'inventory_id', 'item_id', 'field_id', 'name', 'mime', 'size', 'thumbnail_path'])]);
        }

        $this->search->restrict($items, $search);

        $requested = max(1, min($page, self::MAX_PAGE));
        $paginator = (clone $items)->paginate(Items::PER_PAGE, Items::LIST_COLUMNS, 'page', $requested);

        // A page past the end shows the last one, not an empty table.
        if ($paginator->total() > 0 && $requested > $paginator->lastPage()) {
            $paginator = $items->paginate(Items::PER_PAGE, Items::LIST_COLUMNS, 'page', $paginator->lastPage());
        }

        return $this->shape($table, $paginator, $search, $token);
    }

    /**
     * Turns a page of items into the cells of the public page.
     *
     * @param  LengthAwarePaginator<int, Item>  $paginator
     */
    private function shape(ExportTable $table, LengthAwarePaginator $paginator, string $search, string $token): SharedPage
    {
        $formatter = new ExportFormatter(app()->getLocale(), DisplayTime::zone());
        $positions = array_keys($table->fields()->all());
        $rows = [];

        foreach ($paginator->items() as $item) {
            $values = $table->row($item);
            $presented = $table->presented($item);
            $cells = [];

            foreach ($positions as $position) {
                $field = $table->field($position);
                $cells[] = $this->cell($table->typeAt($position), $field, $values[$position], $presented[$field->ulid] ?? null, $item, $formatter, $token);
            }

            $rows[] = $cells;
        }

        return new SharedPage(
            columns: array_map(fn (int $position): string => $table->header($position), $positions),
            rows: $rows,
            search: $search,
            total: $paginator->total(),
            page: $paginator->currentPage(),
            lastPage: max(1, $paginator->lastPage()),
            first: (int) ($paginator->firstItem() ?? 0),
            last: (int) ($paginator->lastItem() ?? 0),
        );
    }

    /**
     * The cell for the value of one field of an item.
     *
     * @param  mixed  $presented  The value as the API gives it (the ids of the choices, the ULIDs of the files...), null when the item holds none.
     */
    private function cell(FieldType $type, InventoryField $field, ExportValue $value, mixed $presented, Item $item, ExportFormatter $formatter, string $token): SharedCell
    {
        return match (true) {
            $type instanceof UploadedFilesType => $this->files($type, $field, $presented, $item, $token),
            $type instanceof ChoiceType => $this->choices($field, $presented),
            $type->key() === 'tags' => $this->tags($presented),
            $value->isEmpty() => SharedCell::empty(),
            default => $this->plain($type->key(), $value, $formatter),
        };
    }

    /**
     * The cell of a value that is written as text, and linked or drawn when its type asks for it.
     */
    private function plain(string $type, ExportValue $value, ExportFormatter $formatter): SharedCell
    {
        $text = $formatter->format($value);

        return match ($type) {
            'url' => ($address = self::webAddress($text)) === null ? SharedCell::text($text) : new SharedCell(SharedCellKind::Link, $text, $address, external: true),
            'email' => new SharedCell(SharedCellKind::Link, $text, 'mailto:'.str_replace('%40', '@', rawurlencode($text))),
            'phone' => ($digits = (string) preg_replace('/[^\d+]/', '', $text)) === '' ? SharedCell::text($text) : new SharedCell(SharedCellKind::Link, $text, 'tel:'.$digits),
            'rating' => $this->stars($value),
            'color' => preg_match('/\A#[0-9a-f]{6}\z/i', $text) === 1 ? new SharedCell(SharedCellKind::Swatch, strtolower($text)) : SharedCell::text($text),
            'barcode' => new SharedCell(SharedCellKind::Mono, $text),
            'textarea' => SharedCell::text($text, multiline: true),
            default => SharedCell::text($text),
        };
    }

    /**
     * The address of a web page the text is, or null when it is anything else: only `http:` and `https:` addresses, with a host and no
     * space or control character, are linked (a `javascript:` address, say, is never a link).
     */
    public static function webAddress(string $text): ?string
    {
        if (preg_match('/[\x00-\x20\x7f]/', $text) === 1) {
            return null;
        }

        $parts = parse_url($text);

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }

        return $text;
    }

    /**
     * A rating as filled and empty stars, with the sentence that says the grade for those who do not see them.
     */
    private function stars(ExportValue $value): SharedCell
    {
        $max = $value->maximum ?? 0;
        $grade = max(0, min((int) $value->numberText(), $max));

        return new SharedCell(
            SharedCellKind::Stars,
            str_repeat('★', $grade).str_repeat('☆', $max - $grade),
            title: __(':grade out of :max', ['grade' => $grade, 'max' => $max]),
        );
    }

    /**
     * The badges of the choice or the choices a value holds, in the color of each choice; a choice the field no longer has is left out.
     */
    private function choices(InventoryField $field, mixed $presented): SharedCell
    {
        $chosen = is_array($presented) ? $presented : [$presented];
        $known = array_column(ChoiceType::choicesOf($field->options), null, 'id');
        $badges = [];

        foreach ($chosen as $id) {
            if (is_string($id) && isset($known[$id])) {
                $color = $known[$id]['color'] ?? null;
                $badges[] = ['text' => $known[$id]['label'], 'class' => $this->badge(is_string($color) ? $color : null)];
            }
        }

        return $badges === [] ? SharedCell::empty() : new SharedCell(SharedCellKind::Badges, badges: $badges);
    }

    /**
     * The tags of an item as plain badges.
     */
    private function tags(mixed $presented): SharedCell
    {
        $badges = [];

        foreach (is_array($presented) ? $presented : [] as $tag) {
            if (is_string($tag) && $tag !== '') {
                $badges[] = ['text' => $tag, 'class' => $this->badge(null)];
            }
        }

        return $badges === [] ? SharedCell::empty() : new SharedCell(SharedCellKind::Badges, badges: $badges);
    }

    /**
     * The Tabler badge of a color of the palette of choices (gray for none, or for a name that is not in it).
     */
    private function badge(?string $color): string
    {
        return TemplatePresenter::BADGES[$color ?? 'gray'] ?? TemplatePresenter::BADGES['gray'];
    }

    /**
     * The files of a photo or file field, in the order they were added: thumbnails for the photos, names for the files. A file that is no
     * longer there is left out.
     */
    private function files(UploadedFilesType $type, InventoryField $field, mixed $presented, Item $item, string $token): SharedCell
    {
        $held = $item->attachments->where('field_id', $field->getKey())->keyBy('ulid');
        $files = [];

        foreach (is_array($presented) ? $presented : [] as $ulid) {
            $attachment = is_string($ulid) ? $held->get($ulid) : null;

            if ($attachment instanceof Attachment) {
                $files[] = $this->file($attachment, $item, $token);
            }
        }

        return $files === [] ? SharedCell::empty() : new SharedCell($type instanceof PhotoType ? SharedCellKind::Photos : SharedCellKind::Files, files: $files);
    }

    /**
     * One file as the public page shows it.
     */
    private function file(Attachment $attachment, Item $item, string $token): SharedFile
    {
        $displayable = in_array($attachment->mime, StoredFileResponse::INLINE_TYPES, true);
        $path = ['token' => $token, 'item' => $item->ulid, 'attachment' => $attachment->ulid];

        return new SharedFile(
            name: $attachment->name,
            href: route('share.attachment', $displayable ? $path + ['inline' => 1] : $path, absolute: false),
            thumbnail: $attachment->hasThumbnail() ? route('share.thumbnail', $path, absolute: false) : null,
            icon: in_array($attachment->mime, self::IMAGE_TYPES, true) ? 'image' : ($attachment->mime === 'application/pdf' ? 'pdf' : 'file'),
            size: Number::fileSize($attachment->size, maxPrecision: 1),
            displayable: $displayable,
        );
    }
}
