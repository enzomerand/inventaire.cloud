/**
 * English messages and a `t()` / `tn()` that read them, for the tests of the components that mock `lib/i18n.js`.
 */

/** The messages of the inventory pages, as the server would hand them over in English. */
export const MESSAGES = {
    'pagination.range': ':first–:last of :total',
    'inventories.items.one': ':count item',
    'inventories.items.other': ':count items',
    'inventories.fields.one': ':count field',
    'inventories.fields.other': ':count fields',
    'inventories.confirm_delete.one': 'Delete :name and its :count item?',
    'inventories.confirm_delete.other': 'Delete :name and its :count items?',
    'inventories.notice.updated': 'Inventory updated.',
    'inventories.notice.item_added': 'Item added.',
    'inventories.notice.item_saved': 'Item saved.',
    'inventories.notice.item_deleted': 'Item deleted.',
    'inventories.error.limit': 'Inventory limit reached (:count per workspace).',
    'inventories.error.item_limit': 'Item limit reached (:count per inventory).',
    'inventories.item.title_create': 'New item',
    'inventories.item.title_edit': 'Edit the item',
    'inventories.item.title_view': 'Item',
    'inventories.item.edit': 'Edit the item',
    'inventories.item.show': 'Show the details of the item',
    'inventories.item.none': 'Not set',
    'inventories.item.remove_tag': 'Remove the tag :name',
    'grid.results.one': ':count result out of :total',
    'grid.results.other': ':count results out of :total',
    'grid.sort.default': 'Newest first',
    'grid.sort.created': 'Date added',
    'grid.sort.updated': 'Last modified',
    'grid.sort.ascending': 'Ascending order',
    'grid.sort.descending': 'Descending order',
    'grid.totals.all': 'Total',
    'grid.totals.selection': 'Selection total',
    'grid.notice.dropped': 'Some of the search options no longer apply: they were removed.',
    'grid.notice.fields_changed': 'The fields of this inventory have changed: the list was loaded again.',
    'grid.notice.not_saved': 'The new value of :field was not saved: :reason',
    'grid.filter.invalid_number': 'Enter a number with at most 6 decimals.',
    'grid.filter.too_large': 'Enter a number with at most 15 digits before the decimal sign.',
    'grid.filter.invalid_value': 'This value is not valid.',
    'grid.card.untitled': 'Untitled item',
    'grid.cell.edit': 'Edit :field: :value',
    'grid.cell.open_link': 'Open the link of :field',
    'grid.chip.remove': 'Remove the filter :label',
    'grid.chip.empty': ':name: empty',
    'grid.chip.not_empty': ':name: not empty',
    'grid.chip.between': ':name: :min – :max',
    'grid.chip.min': ':name: from :value',
    'grid.chip.max': ':name: up to :value',
    'grid.chip.value': ':name: :value',
    'grid.chip.deleted_choice': 'Deleted choice',
    'fields.boolean.yes': 'Yes',
    'fields.boolean.no': 'No',
    'fields.rating': ':value out of :max',
    'fields.title_add': 'Add a field',
    'fields.title_edit': 'Edit the field',
    'fields.confirm_delete': 'Delete the field :name?',
    'fields.move_up_named': 'Move :name up',
    'fields.move_down_named': 'Move :name down',
    'fields.edit_named': 'Edit :name',
    'fields.delete_named': 'Delete :name',
    'fields.notice.added': 'Field added.',
    'fields.notice.saved': 'Field saved.',
    'fields.notice.deleted': 'Field deleted.',
    'fields.error.limit': 'Field limit reached (:count per inventory).',
    'fields.choice.remove': 'Remove the choice',
    'fields.choice.remove_named': 'Remove the choice :name',
    'fields.barcode.any': 'Any code',
    'fields.color.none': 'No color',
    'fields.color.gray': 'Gray',
    'fields.color.red': 'Red',
    'fields.color.orange': 'Orange',
    'fields.color.yellow': 'Yellow',
    'fields.color.green': 'Green',
    'fields.color.teal': 'Teal',
    'fields.color.blue': 'Blue',
    'fields.color.indigo': 'Indigo',
    'fields.color.purple': 'Purple',
    'fields.color.pink': 'Pink',
    'fields.summary.max_length': 'Maximum length: :count',
    'fields.summary.decimals': 'Decimals: :count',
    'fields.summary.unit': 'Unit: :unit',
    'fields.summary.min': 'Minimum: :value',
    'fields.summary.max': 'Maximum: :value',
    'fields.summary.currency': 'Currency: :currency',
    'fields.summary.choices': 'Choices: :list',
    'fields.summary.format': 'Format: :format',
    'fields.summary.max_files': 'Files allowed: :count',
    'files.tab': 'Attachments (:count)',
    'files.add_file': 'Add a file',
    'files.add_photo': 'Add a photo',
    'files.count': ':count of :max',
    'files.hint_create': 'Files are sent when the item is saved.',
    'files.hint_edit': 'Files are saved as soon as they are added or removed.',
    'files.download_named': 'Download :name',
    'files.remove_named': 'Remove :name',
    'files.dismiss_named': 'Dismiss the error for :name',
    'files.confirm_remove': 'Delete the file :name? This cannot be undone.',
    'files.added_by': 'Added by :name',
    'files.error.too_large': 'This file is larger than :max.',
    'files.error.not_photo': 'Only JPEG, PNG, WebP and GIF images are accepted.',
    'files.error.field_full': 'This field cannot hold more files.',
    'files.error.item_limit': 'An item cannot hold more than :count files.',
    'files.error.field_limit': 'This field cannot hold more than :count files.',
    'files.error.storage_limit': 'The storage space of this workspace is full.',
    'files.notice.removed': 'File deleted.',
    'files.notice.partial.one': 'Item added, but :count file could not be sent.',
    'files.notice.partial.other': 'Item added, but :count files could not be sent.',
    'files.notice.upload_failed': ':name could not be sent: :reason',
    'history.created': 'Item created',
    'history.updated': 'Item modified',
    'history.change': ':field: :before → :after',
    'history.value': ':field: :value',
    'history.file_added': 'File added: :name',
    'history.file_removed': 'File removed: :name',
    'history.deleted_field': 'Deleted field',
    'history.deleted_user': 'Deleted user',
};

/**
 * The message of a key, with its placeholders filled in.
 *
 * @param {string} key
 * @param {Record<string, string | number>} [replacements]
 * @returns {string}
 */
export function t(key, replacements = {}) {
    // One pass over the message, as the real `t()` does: what a value holds is never read as a placeholder.
    return (MESSAGES[key] ?? key).replace(/:(\w+)/g, (placeholder, name) =>
        Object.hasOwn(replacements, name) ? String(replacements[name]) : placeholder,
    );
}

/**
 * The message of a key in the plural form of a count (English: one, or other).
 *
 * @param {string} key
 * @param {number} count
 * @param {Record<string, string | number>} [replacements]
 * @returns {string}
 */
export function tn(key, count, replacements = {}) {
    return t(`${key}.${count === 1 ? 'one' : 'other'}`, { count, ...replacements });
}
